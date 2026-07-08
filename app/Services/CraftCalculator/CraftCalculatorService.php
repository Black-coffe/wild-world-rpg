<?php

declare(strict_types=1);

namespace App\Services\CraftCalculator;

use App\Models\ResourceModel;
use Config\CraftRecipes;

/**
 * ADR-149 (Поток E1, SEO-WEB-ROADMAP) — ядро публичного калькулятора крафта.
 *
 * Чистый read-only резолвер: по ключу рецепта + количеству разворачивает ПОЛНОЕ
 * дерево зависимостей в «спецификацию сырья» (bill-of-materials) — сколько всего
 * первичного сырья и сколько золота нужно, если докупать недостающее у торговца.
 *
 * 🔴 Источник истины = `Config\CraftRecipes` (100 рецептов, структурированные maps —
 * ровно то, из чего крафтит Telegram-бот), а НЕ DB-таблица `crafted_items` (free-text,
 * admin-facing, может дрейфовать). Цены/редкость — из таблицы `resources` (фикс-цены
 * торговца, `buy_price ?? price`).
 *
 * Рекурсия memoize-free (дерево мелкое, ≤ пары уровней), но cycle-safe через $inFlight —
 * структура портирована из [[CraftTreeService::computeCraftedCost]], но добавлены (а)
 * множитель qty и (б) аккумулятор сырья, которых там нет (тот считает только золото за 1 шт).
 *
 * Ссылки на суб-крафты в `crafted_items` идут ЛИБО по ключу рецепта (`BasicMedKit → Bandage`),
 * ЛИБО по `item_name_eng` (`Wiring → metalFragments`, рецепт keyed `MetalFragments`) →
 * резолвер индексирует по обоим.
 */
class CraftCalculatorService
{
    /** @var array<string, array<string, mixed>>|null keyed recipe map (ленивый) */
    private ?array $recipeMapCache = null;

    /** @var array<string, string>|null lc(ключ|item_name_eng) → ключ рецепта */
    private ?array $refIndexCache = null;

    /** @var array<string, array{buy: float, price: float, rarity: int, name_en: string, icon: string, tradeable: bool}>|null */
    private ?array $resourceIndexCache = null;

    public function __construct(
        private ?CraftRecipes $recipes = null,
        private ?ResourceModel $resourceModel = null,
    ) {
    }

    // ── data seams (переопределяемы в тестах, без DB) ─────────────────────────

    /** @return array<string, array<string, mixed>> */
    protected function loadRecipeMap(): array
    {
        return ($this->recipes ?? new CraftRecipes())->recipes;
    }

    /**
     * Индекс сырья: name_rus → цена/редкость. `buy` = цена покупки у торговца
     * (`buy_price`, fallback `price`) — сколько стоит докупить недостающее.
     *
     * @return array<string, array{buy: float, price: float, rarity: int, name_en: string, icon: string, tradeable: bool}>
     */
    protected function loadResourceIndex(): array
    {
        $model = $this->resourceModel ?? new ResourceModel();
        $index = [];
        foreach ($model->findAllCached() as $r) {
            $name = (string) ($r->name ?? '');
            if ($name === '') {
                continue;
            }
            $priceRaw = $r->price ?? null;
            $buyRaw   = $r->buy_price ?? null;
            $rarityRaw = $r->rarity ?? null;
            $price = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
            $buy   = is_numeric($buyRaw) ? (float) $buyRaw : 0.0;
            $index[$name] = [
                'buy'       => $buy > 0 ? $buy : $price,
                'price'     => $price,
                'rarity'    => is_numeric($rarityRaw) ? (int) $rarityRaw : 0,
                'name_en'   => (string) ($r->name_en ?? ''),
                'icon'      => (string) ($r->icon_text ?? ''),
                'tradeable' => (int) ($r->is_tradeable ?? 0) === 1,
            ];
        }
        return $index;
    }

    // ── memoized accessors ────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function recipeMap(): array
    {
        return $this->recipeMapCache ??= $this->loadRecipeMap();
    }

    /** @return array<string, array{buy: float, price: float, rarity: int, name_en: string, icon: string, tradeable: bool}> */
    private function resourceIndex(): array
    {
        return $this->resourceIndexCache ??= $this->loadResourceIndex();
    }

    /**
     * lc(ключ рецепта) и lc(item_name_eng) → канонический ключ рецепта.
     * Ключ имеет приоритет над item_name_eng при коллизии.
     *
     * @return array<string, string>
     */
    private function refIndex(): array
    {
        if ($this->refIndexCache !== null) {
            return $this->refIndexCache;
        }
        $index = [];
        foreach ($this->recipeMap() as $key => $recipe) {
            $nameEng = is_string($recipe['item_name_eng'] ?? null) ? $recipe['item_name_eng'] : '';
            if ($nameEng !== '') {
                $index[mb_strtolower($nameEng)] = $key; // item_name_eng
            }
        }
        // Ключ рецепта имеет приоритет — пишем ПОСЛЕ, перекрывая коллизии с name_eng.
        foreach (array_keys($this->recipeMap()) as $key) {
            $index[mb_strtolower($key)] = $key;
        }
        return $this->refIndexCache = $index;
    }

    /** Резолвит ссылку (ключ или item_name_eng) в канонический ключ рецепта; null если не крафт. */
    private function resolveRef(string $ref): ?string
    {
        $map = $this->recipeMap();
        if (isset($map[$ref])) {
            return $ref;
        }
        return $this->refIndex()[mb_strtolower($ref)] ?? null;
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Разворачивает рецепт × qty в полную спецификацию сырья.
     *
     * @return array{
     *   recipe: string, name: string, icon: string, qty: int, found: bool,
     *   rawResources: list<array{name: string, name_en: string, qty: int, rarity: int, icon: string, buy: float, lineGold: float, priced: bool}>,
     *   subCrafts: list<array{recipe: string, name: string, icon: string, qty: int}>,
     *   tree: array<string, mixed>|null,
     *   goldMarket: float, goldRequired: float, goldTotal: float,
     *   unresolved: array<string, int>
     * }
     */
    public function expand(string $recipeKey, int $qty = 1): array
    {
        $qty = max(1, $qty);
        $key = $this->resolveRef($recipeKey);
        $map = $this->recipeMap();

        if ($key === null) {
            return [
                'recipe'       => $recipeKey,
                'name'         => $recipeKey,
                'icon'         => '',
                'qty'          => $qty,
                'found'        => false,
                'rawResources' => [],
                'subCrafts'    => [],
                'tree'         => null,
                'goldMarket'   => 0.0,
                'goldRequired' => 0.0,
                'goldTotal'    => 0.0,
                'unresolved'   => [],
            ];
        }

        /** @var array<string, int> $raw name_rus → qty */
        $raw = [];
        /** @var array<string, int> $subs ключ рецепта → qty */
        $subs = [];
        /** @var array<string, int> $unresolved */
        $unresolved = [];
        $goldRequired = 0.0;

        $this->accumulate($key, $qty, $raw, $subs, $goldRequired, $unresolved, []);

        $ri = $this->resourceIndex();
        $goldMarket = 0.0;
        $rawOut = [];
        foreach ($raw as $name => $q) {
            $meta   = $ri[$name] ?? null;
            $buy    = $meta['buy'] ?? 0.0;
            // 🔴 priced только если ресурс реально покупается у торговца (is_tradeable=1).
            // Иначе строка сырья показывается без цены («—») и НЕ попадает в goldMarket —
            // иначе обещали бы «докупить у торговца» то, что купить нельзя (game-designer ревью ADR-149).
            $priced = $meta !== null && $buy > 0 && $meta['tradeable'];
            $line   = $priced ? $buy * $q : 0.0;
            $goldMarket += $line;
            $rawOut[] = [
                'name'     => $name,
                'name_en'  => $meta['name_en'] ?? '',
                'qty'      => $q,
                'rarity'   => $meta['rarity'] ?? 0,
                'icon'     => $meta['icon'] ?? '',
                'buy'      => round($buy, 2),
                'lineGold' => round($line, 2),
                'priced'   => $priced,
            ];
        }
        // Крупнейшие потребности — первыми (нагляднее в UI).
        usort($rawOut, static fn (array $a, array $b): int => $b['qty'] <=> $a['qty']);

        $subsOut = [];
        foreach ($subs as $subKey => $q) {
            $subRecipe = $map[$subKey] ?? [];
            $subsOut[] = [
                'recipe' => $subKey,
                'name'   => is_string($subRecipe['item_name_rus'] ?? null) ? $subRecipe['item_name_rus'] : $subKey,
                'icon'   => is_string($subRecipe['icon_emoji'] ?? null) ? $subRecipe['icon_emoji'] : '',
                'qty'    => $q,
            ];
        }
        usort($subsOut, static fn (array $a, array $b): int => $b['qty'] <=> $a['qty']);

        $recipe = $map[$key];
        return [
            'recipe'       => $key,
            'name'         => is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : $key,
            'icon'         => is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '',
            'qty'          => $qty,
            'found'        => true,
            'rawResources' => $rawOut,
            'subCrafts'    => $subsOut,
            'tree'         => $this->buildNode($key, $qty, []),
            'goldMarket'   => round($goldMarket, 2),
            'goldRequired' => round($goldRequired, 2),
            'goldTotal'    => round($goldMarket + $goldRequired, 2),
            'unresolved'   => $unresolved,
        ];
    }

    /**
     * Рекурсивно копит сырьё/суб-крафты/золото. Cycle-safe через $inFlight.
     *
     * @param array<string, int>    $raw
     * @param array<string, int>    $subs
     * @param array<string, int>    $unresolved
     * @param list<string>          $inFlight
     */
    private function accumulate(string $key, int $mult, array &$raw, array &$subs, float &$goldRequired, array &$unresolved, array $inFlight): void
    {
        $map = $this->recipeMap();
        if (! isset($map[$key])) {
            return;
        }
        $recipe = $map[$key];

        $goldReq = $recipe['gold_required'] ?? null;
        if (is_numeric($goldReq)) {
            $goldRequired += (float) $goldReq * $mult;
        }

        $resources = $recipe['resources'] ?? [];
        if (is_array($resources)) {
            foreach ($resources as $name => $q) {
                if (! is_string($name) || ! is_numeric($q)) {
                    continue;
                }
                $raw[$name] = ($raw[$name] ?? 0) + (int) $q * $mult;
            }
        }

        $crafted = $recipe['crafted_items'] ?? [];
        if (is_array($crafted)) {
            foreach ($crafted as $ref => $q) {
                if (! is_string($ref) || ! is_numeric($q)) {
                    continue;
                }
                $need   = (int) $q * $mult;
                $subKey = $this->resolveRef($ref);
                if ($subKey === null) {
                    $unresolved[$ref] = ($unresolved[$ref] ?? 0) + $need;
                    continue;
                }
                $subs[$subKey] = ($subs[$subKey] ?? 0) + $need;
                if (in_array($subKey, $inFlight, true)) {
                    continue; // 🔴 цикл — не рекурсируем глубже
                }
                $this->accumulate($subKey, $need, $raw, $subs, $goldRequired, $unresolved, [...$inFlight, $subKey]);
            }
        }
    }

    /**
     * Строит ВЛОЖЕННЫЙ узел дерева крафта (для разворота по клику, ADR-149 E1.1) — в отличие
     * от accumulate() (плоский аккумулятор итогов), сохраняет иерархию: прямое сырьё узла +
     * его прямые суб-крафты как дочерние узлы (рекурсивно, каждый со своим сырьём и детьми).
     * Cycle-safe через $inFlight: при цикле дочерний узел — лист без разворота (cyclic=true).
     * qty каждого узла = потребность на этом уровне (× накопленный множитель родителей).
     *
     * @param list<string> $inFlight
     * @return array<string, mixed>
     */
    private function buildNode(string $key, int $mult, array $inFlight): array
    {
        $map    = $this->recipeMap();
        $recipe = $map[$key] ?? [];
        $ri     = $this->resourceIndex();

        /** @var list<array{name: string, icon: string, rarity: int, qty: int}> $directRaw */
        $directRaw = [];
        $resources = $recipe['resources'] ?? [];
        if (is_array($resources)) {
            foreach ($resources as $name => $q) {
                if (! is_string($name) || ! is_numeric($q)) {
                    continue;
                }
                $meta        = $ri[$name] ?? null;
                $directRaw[] = [
                    'name'   => $name,
                    'icon'   => $meta !== null ? $meta['icon'] : '',
                    'rarity' => $meta !== null ? $meta['rarity'] : 0,
                    'qty'    => (int) $q * $mult,
                ];
            }
            usort($directRaw, static fn (array $a, array $b): int => $b['qty'] <=> $a['qty']);
        }

        /** @var list<array{qty: int, node: array<string, mixed>}> $childEntries */
        $childEntries = [];
        /** @var list<string> $unresolved */
        $unresolved = [];
        $crafted    = $recipe['crafted_items'] ?? [];
        if (is_array($crafted)) {
            foreach ($crafted as $ref => $q) {
                if (! is_string($ref) || ! is_numeric($q)) {
                    continue;
                }
                $need   = (int) $q * $mult;
                $subKey = $this->resolveRef($ref);
                if ($subKey === null) {
                    $unresolved[] = $ref;
                    continue;
                }
                $node = in_array($subKey, $inFlight, true)
                    ? $this->leafNode($subKey, $need, true)                       // 🔴 цикл — лист без разворота
                    : $this->buildNode($subKey, $need, [...$inFlight, $subKey]);
                $childEntries[] = ['qty' => $need, 'node' => $node];
            }
            usort($childEntries, static fn (array $a, array $b): int => $b['qty'] <=> $a['qty']);
        }

        return [
            'recipe'     => $key,
            'name'       => is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : $key,
            'icon'       => is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '',
            'qty'        => $mult,
            'directRaw'  => $directRaw,
            'children'   => array_map(static fn (array $e): array => $e['node'], $childEntries),
            'cyclic'     => false,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Лист-узел суб-крафта без разворота (цикл): показывается, но глубже не раскрывается.
     *
     * @return array<string, mixed>
     */
    private function leafNode(string $key, int $qty, bool $cyclic): array
    {
        $recipe = $this->recipeMap()[$key] ?? [];

        return [
            'recipe'     => $key,
            'name'       => is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : $key,
            'icon'       => is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '',
            'qty'        => $qty,
            'directRaw'  => [],
            'children'   => [],
            'cyclic'     => $cyclic,
            'unresolved' => [],
        ];
    }

    /**
     * Каталог всех рецептов для витрины (сгруппирован по zone_name).
     * Read-only display-данные; расчёт делает expand()/клиент.
     *
     * @return list<array{
     *   zone: string, zone_emoji: string,
     *   items: list<array{recipe: string, name: string, name_en: string, icon: string, direct: list<array{name: string, qty: int, kind: string}>, goldRequired: float}>
     * }>
     */
    public function catalog(): array
    {
        /** @var array<string, array{zone: string, zone_emoji: string, items: list<array{recipe: string, name: string, name_en: string, icon: string, direct: list<array{name: string, qty: int, kind: string}>, goldRequired: float}>}> $groups */
        $groups = [];
        foreach ($this->recipeMap() as $key => $recipe) {
            $zone      = is_string($recipe['zone_name'] ?? null) ? $recipe['zone_name'] : 'прочее';
            $zoneEmoji = is_string($recipe['zone_emoji'] ?? null) ? $recipe['zone_emoji'] : '';

            /** @var list<array{name: string, qty: int, kind: string}> $direct */
            $direct = [];
            $resources = $recipe['resources'] ?? [];
            if (is_array($resources)) {
                foreach ($resources as $name => $q) {
                    if (is_string($name) && is_numeric($q)) {
                        $direct[] = ['name' => $name, 'qty' => (int) $q, 'kind' => 'resource'];
                    }
                }
            }
            $crafted = $recipe['crafted_items'] ?? [];
            if (is_array($crafted)) {
                foreach ($crafted as $ref => $q) {
                    if (! is_string($ref) || ! is_numeric($q)) {
                        continue;
                    }
                    $subKey = $this->resolveRef($ref);
                    $sub    = $subKey !== null ? ($this->recipeMap()[$subKey] ?? []) : [];
                    $label  = is_string($sub['item_name_rus'] ?? null) ? $sub['item_name_rus'] : $ref;
                    $direct[] = ['name' => $label, 'qty' => (int) $q, 'kind' => 'crafted'];
                }
            }

            $goldReq = $recipe['gold_required'] ?? null;
            $groups[$zone] ??= ['zone' => $zone, 'zone_emoji' => $zoneEmoji, 'items' => []];
            $groups[$zone]['items'][] = [
                'recipe'       => $key,
                'name'         => is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : $key,
                'name_en'      => is_string($recipe['item_name_eng'] ?? null) ? $recipe['item_name_eng'] : $key,
                'icon'         => is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '',
                'direct'       => $direct,
                'goldRequired' => is_numeric($goldReq) ? (float) $goldReq : 0.0,
            ];
        }

        return array_values($groups);
    }

    /**
     * Плоский список ключей рецептов (для выпадашки выбора на клиенте).
     *
     * @return list<array{recipe: string, name: string, icon: string, zone: string}>
     */
    public function itemList(): array
    {
        $out = [];
        foreach ($this->recipeMap() as $key => $recipe) {
            $out[] = [
                'recipe' => $key,
                'name'   => is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : $key,
                'icon'   => is_string($recipe['icon_emoji'] ?? null) ? $recipe['icon_emoji'] : '',
                'zone'   => is_string($recipe['zone_name'] ?? null) ? $recipe['zone_name'] : 'прочее',
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }
}
