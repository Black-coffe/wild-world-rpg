<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BiomeModel;
use App\Models\BuildingModel;
use App\Models\FactionModel;
use App\Models\OutfitModel;
use App\Models\ResourceModel;
use App\Models\WeaponModel;
use App\Models\WorldObjectModel;
use Config\Buildings as BuildingsConfig;
use Config\CraftRecipes;
use Config\ImageRegistry;

/**
 * Live-wiki публичного сайта (ADR-052): проекция игровых справочников в
 * view-ready данные. Читает ЖИВЫЕ игровые таблицы → wiki никогда не устаревает
 * (в отличие от старого WordPress-блога, который разошёлся с кодом — ADR-010).
 *
 * Принципы:
 *  - Только PLAYER-FACING поля. Внутренние balance/secret поля не отдаём.
 *  - 🔴 Брони: НЕ показываем *_resistance / *_modifier / special_bonus / penalty —
 *    они читаются только в PvP (PvpDamageCalculator), в PvE мёртвые
 *    ([[reference_outfit_resistances_pvp_only]]) → отдавать их = врать игроку.
 *  - Картинки — enhancement: резолвятся из ImageRegistry где надёжно (фракции,
 *    здания), иначе null. Текст самодостаточен (media-off конституция).
 *  - Кэш не добавляем: справочные модели уже кэшируют (findAllCached, bust-on-edit);
 *    второй агрегат-кэш = вторая поверхность устаревания.
 */
final class WikiContentService
{
    /** Канон-имя фракции → slug картинки (ImageRegistry faction/<slug>). */
    private const FACTION_IMAGE_SLUGS = [
        'Милитари'  => 'military',
        'Партизаны' => 'partisans',
        'Инженеры'  => 'engineers',
        'Фермеры'   => 'farmers',
        'Нейтралы'  => 'neutrals',
    ];

    /** name_en объекта мира → ключ картинки (ImageRegistry objects/<...>). */
    private const WORLD_OBJECT_IMAGE_KEYS = [
        'Bunker'           => 'objects/bunker',
        'Technopark'       => 'objects/technopark',
        'GhostCity'        => 'objects/ghostcity',
        'IslandFarm'       => 'objects/islandfarm',
        'Toolkit'          => 'objects/collection-of-rusted-and-weathered-tools-including',
        'Closed warehouse' => 'objects/an-old-long-abandoned-warehouse',
    ];

    /** @var array<string,string>|null Кэш карты ImageRegistry key(lowercase) => file. */
    private ?array $imageMap = null;

    /** @var array<string,array<string,string>>|null name_en→relPath по output_type (weapon|outfit). */
    private ?array $craftImagesByType = null;

    /** @var array<string,string>|null name_en постройки → relPath (Config\Buildings). */
    private ?array $buildingImagesMap = null;

    /**
     * Биомы мира.
     *
     * @return list<array<string,mixed>>
     */
    public function biomes(): array
    {
        $out = [];
        foreach ((new BiomeModel())->findAllCached() as $b) {
            $out[] = [
                'name'                => self::asString($b->name),
                'description'         => self::asString($b->description),
                'biome_type'          => self::asString($b->biome_type),
                'danger_level'        => self::asInt($b->danger_level),
                'survival_difficulty' => self::asInt($b->survival_difficulty),
                'image'               => null,
            ];
        }

        return $out;
    }

    /**
     * Ресурсы (сырьё).
     *
     * @return list<array<string,mixed>>
     */
    public function resources(): array
    {
        $out = [];
        foreach ((new ResourceModel())->findAllCached() as $r) {
            $out[] = [
                'name'           => self::asString($r->name),
                'name_en'        => self::asString($r->name_en),
                'description'    => self::asString($r->description),
                'rarity'         => self::asInt($r->rarity),
                'level_required' => self::asInt($r->level_required),
                'price'          => self::asInt($r->price),
                'icon_text'      => self::asString($r->icon_text),
                'type'           => self::asString($r->type),
                'image'          => null,
            ];
        }

        return $out;
    }

    /**
     * Фракции (канон-имена; «Партизаны», не «Разбойники»).
     *
     * @return list<array<string,mixed>>
     */
    public function factions(): array
    {
        $out = [];
        foreach ((new FactionModel())->findAll() as $row) {
            $f    = self::asArray($row);
            $name = self::asString($f['name'] ?? '');
            $slug = self::FACTION_IMAGE_SLUGS[$name] ?? null;
            $out[] = [
                'name'           => $name,
                'description'    => self::asString($f['description'] ?? ''),
                'type'           => self::asString($f['type'] ?? ''),
                'unique_ability' => self::asString($f['unique_ability'] ?? ''),
                'image'          => $slug !== null ? $this->resolveImage('faction/' . $slug) : null,
            ];
        }

        return $out;
    }

    /**
     * Здания базы.
     *
     * @return list<array<string,mixed>>
     */
    public function buildings(): array
    {
        $out = [];
        foreach ((new BuildingModel())->findAll() as $row) {
            $b      = self::asArray($row);
            $nameEn = self::asString($b['name_en'] ?? '');
            $out[]  = [
                'name'                => self::asString($b['name_ru'] ?? ''),
                'name_en'             => $nameEn,
                'description'         => self::asString($b['description'] ?? ''),
                'building_type'       => self::asString($b['building_type'] ?? ''),
                'hp'                  => self::asInt($b['hp'] ?? null),
                'construction_time'   => self::asInt($b['construction_time'] ?? null),
                'tax'                 => self::asInt($b['tax'] ?? null),
                'level'               => self::asInt($b['level'] ?? null),
                'min_character_level' => self::asInt($b['min_character_level'] ?? null),
                'effects'             => $this->decodeJson(self::asString($b['effects'] ?? '')),
                'image'               => $this->buildingImages()[$nameEn] ?? ($nameEn !== '' ? $this->resolveImage('camp/' . $nameEn) : null),
            ];
        }

        return $out;
    }

    /**
     * Оружие (на проде ~20 шт.; локально может быть пусто).
     *
     * @return list<array<string,mixed>>
     */
    public function weapons(): array
    {
        $out = [];
        $images = $this->craftImagesFor('weapon');
        foreach ((new WeaponModel())->findAll() as $row) {
            $w      = self::asArray($row);
            $nameEn = self::asString($w['name_en'] ?? '');
            $out[]  = [
                'name'           => self::asString($w['name'] ?? ''),
                'name_en'        => $nameEn,
                'description'    => self::asString($w['description'] ?? ''),
                'weapon_type'    => self::asString($w['weapon_type'] ?? ''),
                'damage_value'   => self::asFloat($w['damage_value'] ?? null),
                'damage_type'    => self::asString($w['damage_type'] ?? ''),
                'required_level' => self::asInt($w['required_level'] ?? null),
                'rarity'         => self::asString($w['rarity'] ?? ''),
                'price'          => self::asInt($w['price'] ?? null),
                'image'          => $images[$nameEn] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Броня. 🔴 Без *_resistance / *_modifier / special_bonus / penalty —
     * это PvP-only/мёртвые поля ([[reference_outfit_resistances_pvp_only]]).
     *
     * @return list<array<string,mixed>>
     */
    public function outfits(): array
    {
        $out = [];
        $images = $this->craftImagesFor('outfit');
        foreach ((new OutfitModel())->findAll() as $row) {
            $o      = self::asArray($row);
            $nameEn = self::asString($o['name_en'] ?? '');
            $out[]  = [
                'name'           => self::asString($o['name'] ?? ''),
                'name_en'        => $nameEn,
                'description'    => self::asString($o['description'] ?? ''),
                'armor_type'     => self::asString($o['armor_type'] ?? ''),
                'slot'           => self::asString($o['slot'] ?? ''),
                'armor_value'    => self::asFloat($o['armor_value'] ?? null),
                'required_level' => self::asInt($o['required_level'] ?? null),
                'rarity'         => self::asString($o['rarity'] ?? ''),
                'price'          => self::asInt($o['price'] ?? null),
                'image'          => $images[$nameEn] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Объекты мира (только активные).
     *
     * @return list<array<string,mixed>>
     */
    public function worldObjects(): array
    {
        $out = [];
        foreach ((new WorldObjectModel())->where('status', 'active')->findAll() as $row) {
            $w      = self::asArray($row);
            $nameEn = self::asString($w['name_en'] ?? '');
            $key    = self::WORLD_OBJECT_IMAGE_KEYS[$nameEn] ?? null;
            $out[]  = [
                'name'        => self::asString($w['name'] ?? ''),
                'name_en'     => $nameEn,
                'description' => self::asString($w['description'] ?? ''),
                'image'       => $key !== null ? $this->resolveImage($key) : null,
            ];
        }

        return $out;
    }

    /**
     * Разделы wiki (для индекса) с количеством элементов.
     *
     * @return list<array{key:string,title:string,count:int}>
     */
    public function sections(): array
    {
        return [
            ['key' => 'biomes', 'title' => 'Биомы', 'count' => count($this->biomes())],
            ['key' => 'resources', 'title' => 'Ресурсы', 'count' => count($this->resources())],
            ['key' => 'factions', 'title' => 'Фракции', 'count' => count($this->factions())],
            ['key' => 'buildings', 'title' => 'Здания', 'count' => count($this->buildings())],
            ['key' => 'weapons', 'title' => 'Оружие', 'count' => count($this->weapons())],
            ['key' => 'outfits', 'title' => 'Броня', 'count' => count($this->outfits())],
            ['key' => 'world-objects', 'title' => 'Объекты мира', 'count' => count($this->worldObjects())],
        ];
    }

    /**
     * Данные одного раздела по ключу (для Wiki::entry).
     *
     * @return list<array<string,mixed>>|null
     */
    public function section(string $key): ?array
    {
        return match ($key) {
            'biomes'        => $this->biomes(),
            'resources'     => $this->resources(),
            'factions'      => $this->factions(),
            'buildings'     => $this->buildings(),
            'weapons'       => $this->weapons(),
            'outfits'       => $this->outfits(),
            'world-objects' => $this->worldObjects(),
            default         => null,
        };
    }

    /**
     * Относительный путь картинки из ImageRegistry по key (case-insensitive),
     * только для готовых (generated/approved/locked). Иначе null → view покажет
     * placeholder. Путь относителен public/ — view добавит base_url().
     */
    private function resolveImage(string $key): ?string
    {
        $map = $this->imageMap();

        return $map[mb_strtolower($key)] ?? null;
    }

    /**
     * @return array<string,string>
     */
    private function imageMap(): array
    {
        if ($this->imageMap !== null) {
            return $this->imageMap;
        }

        $map = [];
        $cfg = config(ImageRegistry::class);
        foreach ($cfg->images as $e) {
            if (! in_array($e['status'], ['generated', 'approved', 'locked'], true)) {
                continue;
            }
            if ($e['key'] !== '' && $e['file'] !== '') {
                $map[mb_strtolower($e['key'])] = $e['file'];
            }
        }

        return $this->imageMap = $map;
    }

    /**
     * name_en крафтового предмета → relPath картинки, из `Config\CraftRecipes`
     * (тот же source-of-truth, что бот при крафте). Только для существующих файлов.
     *
     * @return array<string,string>
     */
    private function craftImagesFor(string $outputType): array
    {
        if ($this->craftImagesByType === null) {
            $byType = ['weapon' => [], 'outfit' => []];
            foreach ((new CraftRecipes())->recipes as $recipe) {
                if (! is_array($recipe)) {
                    continue;
                }
                $type = self::asString($recipe['output_type'] ?? '');
                if (! isset($byType[$type])) {
                    continue;
                }
                $nameEn = self::asString($recipe[$type . '_name_en'] ?? '');
                $image  = self::asString($recipe['image_completed'] ?? '');
                if ($image === '') {
                    $image = self::asString($recipe['image_in_progress'] ?? '');
                }
                if ($nameEn === '' || ! $this->imageExists($image)) {
                    continue;
                }
                $byType[$type][$nameEn] = $image;
            }
            $this->craftImagesByType = $byType;
        }

        return $this->craftImagesByType[$outputType] ?? [];
    }

    /**
     * name_en постройки → relPath картинки, из `Config\Buildings` (completion_image).
     * Только для существующих файлов.
     *
     * @return array<string,string>
     */
    private function buildingImages(): array
    {
        if ($this->buildingImagesMap === null) {
            $map = [];
            foreach ((new BuildingsConfig())->recipes as $key => $b) {
                $image = self::asString($b['completion_image'] ?? '');
                if ($this->imageExists($image)) {
                    $map[(string) $key] = $image;
                }
            }
            $this->buildingImagesMap = $map;
        }

        return $this->buildingImagesMap;
    }

    /** Файл картинки физически существует в public/ (relPath относителен public). */
    private function imageExists(string $relPath): bool
    {
        return $relPath !== '' && is_file(FCPATH . $relPath);
    }

    /**
     * @return array<array-key,mixed>
     */
    private function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ---- mixed-нарроверы (идиомы CraftTreeService, phpstan L9) ----

    private static function asString(mixed $v): string
    {
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }

    private static function asInt(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function asFloat(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * @return array<array-key,mixed>
     */
    private static function asArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }
}
