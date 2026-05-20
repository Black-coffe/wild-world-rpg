<?php

declare(strict_types=1);

namespace App\Services\CraftTree;

use App\Services\Crafting\RequiredResourcesParser;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Buildings as BuildingsConfig;
use Config\CraftRecipes;
use Config\Database;

/**
 * Агрегирует все рецепты крафта, постройки и ресурсы в единое дерево для
 * админ-визуализации `/admin/craft-tree`. Парсит свободный текст
 * `crafted_items.required_resources` (несколько форматов исторически — см.
 * `parseIngredients`) и JSON `buildings.required_resources`, разрешает
 * имена ингредиентов в строки `resources` / `crafted_items`, рекурсивно
 * считает суммарную стоимость крафта в золоте.
 *
 * Использует DB builder напрямую (без F1.4 Entity моделей) — нам нужны
 * plain assoc-массивы без `(array) $entity` mangled-keys ловушек.
 */
final class CraftTreeService
{
    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $resourcesById = null;

    /** @var array<string, int>|null */
    private ?array $resourceIdByName = null;

    /** @var array<string, int>|null */
    private ?array $craftedIdByName = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $craftedById = null;

    /** @var array<int, float|null> Memoized recursive gold cost per crafted-item-id. */
    private array $craftedCostMemo = [];

    /** @var array<string, array<string, mixed>>|null Recipe image lookup by name_eng. */
    private ?array $craftImageLookup = null;

    /** @var array<string, array<string, mixed>>|null Building image lookup by name_en. */
    private ?array $buildingImageLookup = null;

    private RequiredResourcesParser $requiredResourcesParser;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?RequiredResourcesParser $parser = null)
    {
        $this->db                      = $db ?? Database::connect();
        $this->requiredResourcesParser = $parser ?? new RequiredResourcesParser();
    }

    /**
     * Загружает маппинг recipe.name_eng → image и building.name_en → image из
     * существующих `Config\CraftRecipes` / `Config\Buildings` (тот же source-of-truth
     * что использует Telegram-бот при крафте/стройке).
     */
    private function primeImageLookups(): void
    {
        $craftConfig = new CraftRecipes();
        $this->craftImageLookup = [];
        foreach ($craftConfig->recipes as $key => $recipe) {
            if (! is_string($key) || ! is_array($recipe)) {
                continue;
            }
            $image = $recipe['image_completed'] ?? $recipe['image_in_progress'] ?? null;
            if (! is_string($image) || $image === '') {
                continue;
            }
            $this->craftImageLookup[$key] = ['image' => $image];
        }

        $buildingConfig = new BuildingsConfig();
        $this->buildingImageLookup = [];
        foreach ($buildingConfig->recipes as $key => $building) {
            $image = $building['completion_image'] ?? $building['image_in_progress'];
            if ($image === '') {
                continue;
            }
            $this->buildingImageLookup[$key] = ['image' => $image];
        }
    }

    /**
     * Wraps `$db->table()->get()` to narrow `ResultInterface|false` → `ResultInterface`
     * (CI4 returns false only on driver-level connection failure; treated as empty here).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $table, ?string $orderBy = null): array
    {
        $builder = $this->db->table($table);
        if ($orderBy !== null) {
            $builder->orderBy($orderBy);
        }
        $result = $builder->get();
        if (! $result instanceof ResultInterface) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();
        return $rows;
    }

    /**
     * Полный payload для view / JSON-endpoint.
     *
     * @return array{
     *     generatedAt: int,
     *     stats: array{recipes:int, buildings:int, resources:int},
     *     workbenches: list<array<string, mixed>>,
     *     buildings: list<array<string, mixed>>,
     *     resources: list<array<string, mixed>>,
     * }
     */
    public function buildTree(): array
    {
        $this->primeCaches();
        $this->primeImageLookups();
        $this->craftedCostMemo = [];

        $workbenches = $this->groupCraftedByWorkbench();
        $buildings   = $this->buildBuildings();
        $resources   = $this->buildResources();

        return [
            'generatedAt' => time(),
            'stats'       => [
                'recipes'   => count($this->craftedById ?? []),
                'buildings' => count($buildings),
                'resources' => count($resources),
            ],
            'workbenches' => $workbenches,
            'buildings'   => $buildings,
            'resources'   => $resources,
        ];
    }

    /**
     * S30 — заголовок CSV-экспорта craft-tree.
     *
     * @return list<string>
     */
    public function csvHeader(): array
    {
        return ['Тип', 'Группа', 'Тир/Уровень', 'Название', 'NameEn', 'Категория', 'Треб. уровень', 'Стоимость (золото)', 'Ингредиенты'];
    }

    /**
     * S30 — плоские строки craft-tree (рецепты + постройки) для CSV. Все значения
     * строки (CSV-friendly + PHPStan L9). Порядок колонок = csvHeader().
     *
     * @return list<list<string>>
     */
    public function buildCsvRows(): array
    {
        $tree = $this->buildTree();
        $rows = [];

        foreach ($tree['workbenches'] as $wb) {
            $wbName  = self::toString($wb['name'] ?? '');
            $tierRaw = self::toInt($wb['tier'] ?? 0);
            $tier    = $tierRaw > 0 ? 'T' . $tierRaw : '—';
            $dirs    = is_array($wb['directions'] ?? null) ? $wb['directions'] : [];
            foreach ($dirs as $dir) {
                if (! is_array($dir)) {
                    continue;
                }
                $recipes = is_array($dir['recipes'] ?? null) ? $dir['recipes'] : [];
                foreach ($recipes as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $rl = self::toNullableInt($r['requiredLevel'] ?? null);
                    $tc = self::toNullableFloat($r['totalCost'] ?? null);
                    $rows[] = [
                        'рецепт',
                        $wbName,
                        $tier,
                        self::toString($r['name'] ?? ''),
                        self::toString($r['nameEn'] ?? ''),
                        self::toString($r['type'] ?? ''),
                        $rl !== null ? (string) $rl : '',
                        $tc !== null ? (string) $tc : '',
                        $this->ingredientsToCsvString($r['ingredients'] ?? null),
                    ];
                }
            }
        }

        foreach ($tree['buildings'] as $b) {
            $lvl = self::toNullableInt($b['level'] ?? null);
            $rl  = self::toNullableInt($b['minCharacterLevel'] ?? null);
            $tc  = self::toNullableFloat($b['totalCost'] ?? null);
            $rows[] = [
                'постройка',
                self::toString($b['buildingType'] ?? ''),
                $lvl !== null ? 'L' . $lvl : '—',
                self::toString($b['name'] ?? ''),
                self::toString($b['nameEn'] ?? ''),
                'building',
                $rl !== null ? (string) $rl : '',
                $tc !== null ? (string) $tc : '',
                $this->ingredientsToCsvString($b['ingredients'] ?? null),
            ];
        }

        return $rows;
    }

    private function ingredientsToCsvString(mixed $ingredients): string
    {
        if (! is_array($ingredients)) {
            return '';
        }
        $parts = [];
        foreach ($ingredients as $ing) {
            if (! is_array($ing)) {
                continue;
            }
            $name = self::toString($ing['name'] ?? '');
            $qty  = self::toInt($ing['qty'] ?? 0);
            if ($name !== '') {
                $parts[] = "{$name}×{$qty}";
            }
        }
        return implode('; ', $parts);
    }

    private function primeCaches(): void
    {
        $this->resourcesById    = [];
        $this->resourceIdByName = [];
        $resources = $this->fetchAll('resources');
        foreach ($resources as $row) {
            $id = self::toInt($row['id'] ?? null);
            $this->resourcesById[$id] = $row;
            $name = mb_strtolower(trim(self::toString($row['name'] ?? '')));
            if ($name !== '') {
                $this->resourceIdByName[$name] = $id;
            }
            $nameEn = mb_strtolower(trim(self::toString($row['name_en'] ?? '')));
            if ($nameEn !== '' && ! isset($this->resourceIdByName[$nameEn])) {
                $this->resourceIdByName[$nameEn] = $id;
            }
        }

        $this->craftedById     = [];
        $this->craftedIdByName = [];
        $crafted = $this->fetchAll('crafted_items');
        foreach ($crafted as $row) {
            $id = self::toInt($row['id'] ?? null);
            $this->craftedById[$id] = $row;
            $name = mb_strtolower(trim(self::toString($row['name_rus'] ?? '')));
            if ($name !== '') {
                $this->craftedIdByName[$name] = $id;
            }
            $nameEng = mb_strtolower(trim(self::toString($row['name_eng'] ?? '')));
            if ($nameEng !== '' && ! isset($this->craftedIdByName[$nameEng])) {
                $this->craftedIdByName[$nameEng] = $id;
            }
        }
    }

    /**
     * Парсит `required_resources` (любой из 5 форматов) и резолвит каждое имя
     * в FK на `resources` / `crafted_items`. Делегирует парсинг
     * {@see RequiredResourcesParser} (S2 — единая точка парсинга для всех
     * консьюмеров required_resources в проекте).
     *
     * @return list<array{name:string, qty:int, unit:string, refType:string, refId:int|null, missing:bool}>
     */
    public function parseIngredients(?string $raw): array
    {
        $canonical = $this->requiredResourcesParser->parse($raw);
        $out       = [];
        foreach ($canonical as $name => $qty) {
            $out[] = $this->resolveIngredient($name, $qty, '');
        }
        return $out;
    }

    /**
     * Подбирает FK для ингредиента: сначала в resources, затем в crafted_items.
     *
     * @return array{name:string, qty:int, unit:string, refType:string, refId:int|null, missing:bool}
     */
    private function resolveIngredient(string $rawName, int $qty, string $unit): array
    {
        $needle    = mb_strtolower(trim($rawName));
        $refType   = '';
        $refId     = null;
        $missing   = true;
        $canonName = $rawName;

        if (isset($this->resourceIdByName[$needle])) {
            $refType   = 'resource';
            $refId     = $this->resourceIdByName[$needle];
            $missing   = false;
            $canonName = self::toString($this->resourcesById[$refId]['name'] ?? $rawName);
        } elseif (isset($this->craftedIdByName[$needle])) {
            $refType   = 'crafted';
            $refId     = $this->craftedIdByName[$needle];
            $missing   = false;
            $canonName = self::toString($this->craftedById[$refId]['name_rus'] ?? $rawName);
        }

        return [
            'name'    => $canonName,
            'qty'     => $qty,
            'unit'    => $unit,
            'refType' => $refType,
            'refId'   => $refId,
            'missing' => $missing,
        ];
    }

    /**
     * Считает стоимость крафта в золоте, рекурсивно раскрывая sub-crafts.
     * Memoized; защищён от циклов через `$inFlight`.
     *
     * @param array<int, true> $inFlight
     */
    private function computeCraftedCost(int $craftedId, array $inFlight = []): ?float
    {
        if (array_key_exists($craftedId, $this->craftedCostMemo)) {
            return $this->craftedCostMemo[$craftedId];
        }
        if (isset($inFlight[$craftedId])) {
            return null;
        }
        $inFlight[$craftedId] = true;

        $row = $this->craftedById[$craftedId] ?? null;
        if ($row === null) {
            return null;
        }

        $rawIngredients = isset($row['required_resources']) && is_string($row['required_resources'])
            ? $row['required_resources']
            : null;
        $ingredients = $this->parseIngredients($rawIngredients);
        $total       = 0.0;
        foreach ($ingredients as $ing) {
            $unit = $this->ingredientUnitCost($ing, $inFlight);
            if ($unit === null) {
                continue; // unresolved — пропустить
            }
            $total += $unit * $ing['qty'];
        }

        return $this->craftedCostMemo[$craftedId] = round($total, 2);
    }

    /**
     * @param array{refType:string, refId:int|null} $ing
     * @param array<int, true> $inFlight
     */
    private function ingredientUnitCost(array $ing, array $inFlight): ?float
    {
        if ($ing['refType'] === 'resource' && $ing['refId'] !== null) {
            $row = $this->resourcesById[$ing['refId']] ?? null;
            if ($row === null) {
                return null;
            }
            $price = $row['buy_price'] ?? $row['price'] ?? null;
            return self::toNullableFloat($price);
        }
        if ($ing['refType'] === 'crafted' && $ing['refId'] !== null) {
            return $this->computeCraftedCost($ing['refId'], $inFlight);
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupCraftedByWorkbench(): array
    {
        /** @var array<string, array<string, list<array<string, mixed>>>> $groups */
        $groups = []; // [workbench => [direction => [recipes...]]]
        foreach ($this->craftedById ?? [] as $row) {
            $workbench = $this->normalizeWorkbench(self::toString($row['crafting_location'] ?? ''));
            $direction = $this->normalizeDirection(self::toString($row['direction_craft'] ?? ''));
            $groups[$workbench][$direction][] = $this->buildRecipe($row);
        }

        $orderedWorkbenches = [
            'Workbench Level 1', 'Workbench Level 2', 'Workbench Level 3',
            'Smithy', 'Workshop', 'Jeweler Table',
            'Magic Workshop', 'Enchanting Room',
            'Campfire', 'Near water', 'On base', 'On site',
            'Farm', 'Field', 'All locations', 'Legacy', 'Unknown',
        ];

        $out = [];
        foreach ($orderedWorkbenches as $key) {
            if (! isset($groups[$key])) {
                continue;
            }
            $out[] = $this->buildWorkbenchGroup($key, $groups[$key]);
            unset($groups[$key]);
        }
        ksort($groups);
        foreach ($groups as $key => $dirs) {
            $out[] = $this->buildWorkbenchGroup($key, $dirs);
        }
        return $out;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $directions
     * @return array<string, mixed>
     */
    private function buildWorkbenchGroup(string $workbench, array $directions): array
    {
        $directionList = [];
        $recipeCount   = 0;
        foreach ($directions as $direction => $recipes) {
            usort($recipes, static function (array $a, array $b): int {
                return strcmp(self::toString($a['name'] ?? ''), self::toString($b['name'] ?? ''));
            });
            $recipeCount += count($recipes);
            $directionList[] = [
                'key'     => $direction,
                'name'    => $direction,
                'icon'    => $this->iconForDirection($direction),
                'recipes' => $recipes,
                'count'   => count($recipes),
            ];
        }
        usort($directionList, static function (array $a, array $b): int {
            return strcmp(self::toString($a['name']), self::toString($b['name']));
        });

        return [
            'key'         => $workbench,
            'name'        => $this->workbenchDisplayName($workbench),
            'icon'        => $this->iconForWorkbench($workbench),
            'tier'        => $this->workbenchTier($workbench),
            'directions'  => $directionList,
            'recipeCount' => $recipeCount,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildRecipe(array $row): array
    {
        $rawIngredients = isset($row['required_resources']) && is_string($row['required_resources'])
            ? $row['required_resources']
            : null;
        $ingredients = $this->parseIngredients($rawIngredients);
        $craftedId   = self::toInt($row['id'] ?? null);
        $totalCost   = $this->computeCraftedCost($craftedId);
        $ingredients = array_map(function (array $ing): array {
            $unit = $this->ingredientUnitCost($ing, []);
            $ing['unitCost']  = $unit;
            $ing['totalCost'] = $unit !== null ? round($unit * $ing['qty'], 2) : null;
            $ing['icon']      = $this->iconForIngredient($ing);
            return $ing;
        }, $ingredients);

        $nameEng = self::toString($row['name_eng'] ?? '');
        $image   = $this->craftImageLookup[$nameEng]['image'] ?? null;

        return [
            'id'              => $craftedId,
            'name'            => self::toString($row['name_rus'] ?? ''),
            'nameEn'          => $nameEng,
            'image'           => is_string($image) ? $image : null,
            'type'            => self::toString($row['type'] ?? ''),
            'icon'            => $this->iconForType(self::toString($row['type'] ?? '')),
            'effect'          => self::toString($row['effect'] ?? ''),
            'damage'          => self::toInt($row['damage'] ?? 0),
            'armor'           => self::toInt($row['armor'] ?? 0),
            'hp'              => self::toInt($row['hp'] ?? 0),
            'characterBoost'  => self::toString($row['character_boost'] ?? ''),
            'weight'          => self::toNullableFloat($row['weight'] ?? null),
            'price'           => self::toNullableFloat($row['price'] ?? null),
            'stackSize'       => self::toNullableInt($row['stack_size'] ?? null),
            'requiredLevel'   => self::toNullableInt($row['required_level'] ?? null),
            'craftingTime'    => self::toString($row['crafting_time'] ?? ''),
            'description'     => self::toString($row['description'] ?? ''),
            'durabilityCount' => self::toNullableInt($row['durability_count'] ?? null),
            'durabilityTime'  => self::toNullableInt($row['durability_time'] ?? null),
            'ingredients'     => $ingredients,
            'totalCost'       => $totalCost,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildBuildings(): array
    {
        $rows = $this->fetchAll('buildings', 'level, name_ru');
        $out  = [];
        foreach ($rows as $row) {
            $rawIngredients = isset($row['required_resources']) && is_string($row['required_resources'])
                ? $row['required_resources']
                : null;
            $ingredients = $this->parseIngredients($rawIngredients);
            $totalCost   = 0.0;
            $hasMissing  = false;
            $ingredients = array_map(function (array $ing) use (&$totalCost, &$hasMissing): array {
                $unit = $this->ingredientUnitCost($ing, []);
                if ($unit !== null) {
                    $totalCost += $unit * $ing['qty'];
                } else {
                    $hasMissing = true;
                }
                $ing['unitCost']  = $unit;
                $ing['totalCost'] = $unit !== null ? round($unit * $ing['qty'], 2) : null;
                $ing['icon']      = $this->iconForIngredient($ing);
                return $ing;
            }, $ingredients);

            $buildingType = self::toString($row['building_type'] ?? '');
            $nameEn       = self::toString($row['name_en'] ?? '');
            $image        = $this->buildingImageLookup[$nameEn]['image'] ?? null;
            $out[] = [
                'id'                     => self::toInt($row['id'] ?? null),
                'name'                   => self::toString($row['name_ru'] ?? ''),
                'nameEn'                 => $nameEn,
                'image'                  => is_string($image) ? $image : null,
                'description'            => self::toString($row['description'] ?? ''),
                'buildingType'           => $buildingType,
                'icon'                   => $this->iconForBuildingType($buildingType),
                'hp'                     => self::toNullableInt($row['hp'] ?? null),
                'constructionTime'       => self::toNullableInt($row['construction_time'] ?? null),
                'tax'                    => self::toNullableInt($row['tax'] ?? null),
                'level'                  => self::toNullableInt($row['level'] ?? null),
                'usage'                  => self::toString($row['usage'] ?? ''),
                'minCharacterLevel'      => self::toNullableInt($row['min_character_level'] ?? null),
                'daysUntilDisappearance' => self::toNullableInt($row['days_until_disappearance'] ?? null),
                'usageCount'             => self::toNullableInt($row['usage_count'] ?? null),
                'effects'                => $this->decodeEffects(self::toString($row['effects'] ?? '')),
                'ingredients'            => $ingredients,
                'totalCost'              => round($totalCost, 2),
                'hasMissing'             => $hasMissing,
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildResources(): array
    {
        $out = [];
        foreach ($this->resourcesById ?? [] as $row) {
            $out[] = [
                'id'            => self::toInt($row['id'] ?? null),
                'name'          => self::toString($row['name'] ?? ''),
                'nameEn'        => self::toString($row['name_en'] ?? ''),
                'price'         => self::toFloat($row['price'] ?? 0),
                'buyPrice'      => self::toNullableFloat($row['buy_price'] ?? null),
                'sellPrice'     => self::toNullableFloat($row['sell_price'] ?? null),
                'rarity'        => self::toInt($row['rarity'] ?? 0),
                'type'          => self::toString($row['type'] ?? ''),
                'iconText'      => self::toString($row['icon_text'] ?? ''),
                'levelRequired' => self::toNullableInt($row['level_required'] ?? null),
                'description'   => self::toString($row['description'] ?? ''),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $ar = self::toInt($a['rarity']);
            $br = self::toInt($b['rarity']);
            if ($ar !== $br) {
                return $ar <=> $br;
            }
            return strcmp(self::toString($a['name']), self::toString($b['name']));
        });
        return $out;
    }

    /**
     * @return list<mixed>|array<string, mixed>
     */
    private function decodeEffects(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [$raw];
    }

    private function normalizeWorkbench(string $loc): string
    {
        $loc = trim($loc);
        if ($loc === '') {
            return 'Unknown';
        }
        $lower = mb_strtolower($loc);
        return match (true) {
            str_contains($lower, 'workbench level 1') => 'Workbench Level 1',
            str_contains($lower, 'workbench level 2') => 'Workbench Level 2',
            str_contains($lower, 'workbench level 3') => 'Workbench Level 3',
            $lower === 'smithy'         => 'Smithy',
            $lower === 'workshop'       => 'Workshop',
            $lower === 'jeweler table'  => 'Jeweler Table',
            $lower === 'magic workshop' => 'Magic Workshop',
            $lower === 'enchanting room' => 'Enchanting Room',
            $lower === 'campfire'       => 'Campfire',
            $lower === 'near water'     => 'Near water',
            $lower === 'on base'        => 'On base',
            $lower === 'on site'        => 'On site',
            $lower === 'farm'           => 'Farm',
            $lower === 'field'          => 'Field',
            $lower === 'all'            => 'All locations',
            str_contains($lower, 'crafting_location') => 'Legacy',
            default => $loc,
        };
    }

    private function normalizeDirection(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return '— без школы —';
        }
        return $dir;
    }

    private function workbenchDisplayName(string $key): string
    {
        return match ($key) {
            'Workbench Level 1' => '📦 Верстак Ур. 1',
            'Workbench Level 2' => '🧰 Верстак Ур. 2',
            'Workbench Level 3' => '⚙️ Верстак Ур. 3',
            'Smithy'            => '🔥 Кузница',
            'Workshop'          => '🛠 Мастерская',
            'Jeweler Table'     => '💎 Стол ювелира',
            'Magic Workshop'    => '✨ Магическая мастерская',
            'Enchanting Room'   => '🪄 Комната зачарования',
            'Campfire'          => '🔥 Костёр',
            'Near water'        => '💧 У воды',
            'On base'           => '🏕 На базе',
            'On site'           => '📍 На месте',
            'Farm'              => '🌾 Ферма',
            'Field'             => '🌱 Поле',
            'All locations'     => '🌐 Везде',
            'Legacy'            => '⚠️ Не указано (legacy)',
            'Unknown'           => '❓ Без локации',
            default             => $key,
        };
    }

    private function workbenchTier(string $key): int
    {
        return match ($key) {
            'Workbench Level 1' => 1,
            'Workbench Level 2' => 2,
            'Workbench Level 3' => 3,
            default             => 0,
        };
    }

    private function iconForWorkbench(string $key): string
    {
        return match ($key) {
            'Workbench Level 1', 'Workbench Level 2', 'Workbench Level 3' => 'ri-tools-fill',
            'Smithy'          => 'ri-fire-fill',
            'Workshop'        => 'ri-hammer-fill',
            'Jeweler Table'   => 'ri-vip-diamond-fill',
            'Magic Workshop', 'Enchanting Room' => 'ri-magic-fill',
            'Campfire'        => 'ri-fire-line',
            'Near water'      => 'ri-water-flash-fill',
            'On base'         => 'ri-home-4-fill',
            'On site', 'Farm', 'Field' => 'ri-plant-fill',
            'All locations'   => 'ri-global-fill',
            default           => 'ri-question-line',
        };
    }

    private function iconForDirection(string $direction): string
    {
        $key = mb_strtolower($direction);
        return match (true) {
            str_contains($key, 'metalworking')   => 'ri-hammer-fill',
            str_contains($key, 'woodworking')    => 'ri-leaf-line',
            str_contains($key, 'stoneworking')   => 'ri-stack-fill',
            str_contains($key, 'leatherworking') => 'ri-shirt-line',
            str_contains($key, 'fabric')         => 'ri-shirt-line',
            str_contains($key, 'treatment')      => 'ri-capsule-fill',
            str_contains($key, 'farming')        => 'ri-plant-line',
            str_contains($key, 'stone')          => 'ri-stack-fill',
            str_contains($key, 'component')      => 'ri-cpu-line',
            str_contains($key, 'building')       => 'ri-building-2-fill',
            str_contains($key, 'robot')          => 'ri-robot-fill',
            str_contains($key, 'workbench')      => 'ri-tools-fill',
            str_contains($key, 'transport')      => 'ri-truck-fill',
            str_contains($key, 'common')         => 'ri-folder-fill',
            str_contains($key, 'standard')       => 'ri-folder-2-fill',
            str_contains($key, 'professional')   => 'ri-medal-fill',
            str_contains($key, 'unique')         => 'ri-vip-crown-fill',
            default => 'ri-folder-line',
        };
    }

    private function iconForType(string $type): string
    {
        return match ($type) {
            'food'         => 'ri-restaurant-fill',
            'drug'         => 'ri-capsule-fill',
            'tool'         => 'ri-hammer-fill',
            'weapon'       => 'ri-sword-fill',
            'clothing'     => 'ri-shirt-fill',
            'component'    => 'ri-puzzle-fill',
            'transport'    => 'ri-truck-fill',
            'utility'      => 'ri-tools-fill',
            'building'     => 'ri-building-2-fill',
            'accessory'    => 'ri-vip-diamond-line',
            'decorative'   => 'ri-palette-fill',
            'magical item' => 'ri-magic-fill',
            'robots'       => 'ri-robot-fill',
            'military'     => 'ri-shield-star-fill',
            'workbench'    => 'ri-tools-fill',
            default        => 'ri-box-3-fill',
        };
    }

    private function iconForBuildingType(string $type): string
    {
        return match ($type) {
            'military'    => 'ri-shield-star-fill',
            'residential' => 'ri-home-4-fill',
            'farming'     => 'ri-plant-fill',
            'resource'    => 'ri-database-2-fill',
            'engineering' => 'ri-settings-3-fill',
            'defensive'   => 'ri-shield-cross-fill',
            default       => 'ri-building-2-fill',
        };
    }

    /**
     * @param array{refType:string, refId:int|null, missing:bool} $ing
     */
    private function iconForIngredient(array $ing): string
    {
        if ($ing['missing']) {
            return 'ri-alert-line';
        }
        return $ing['refType'] === 'crafted' ? 'ri-flask-fill' : 'ri-pin-distance-line';
    }

    // ── Type narrowing helpers (PHPStan L9 friendly) ────────────────────

    private static function toString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v) || is_bool($v)) {
            return (string) $v;
        }
        return '';
    }

    private static function toInt(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function toFloat(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private static function toNullableInt(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function toNullableFloat(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
