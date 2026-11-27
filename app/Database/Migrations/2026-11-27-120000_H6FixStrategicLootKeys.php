<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H6.1 — лут стратобъектов выдаёт то, что обещает (spec `docs/specs/h6-loot-1127/`).
 *
 * Сид-данные `world_objects.contents` пяти стратобъектов держали ключи, которым нет строки
 * (prod SELECT 2026-11-27):
 *   - Технопарк: `resources.Wiring` — это крафт-предмет `wiring`; в `crafted_items` он уже есть
 *     (20), ресурсная строка убирается, предмет оставляет большее из двух: max(20, 40) = 40.
 *   - Технопарк: `resources.Crystals` — у ресурса «Кристаллы» (id 30) `name_en` NULL; лечится
 *     проставлением `name_en='Crystals'` этой строке.
 *   - Сердце острова: `resources.metalFragments`, `resources.electronicComponents` — крафт-предметы,
 *     переезжают в `crafted_items`.
 *   - Бункер: `crafted_items.PipeGun`, `crafted_items.MetalSpear` — это оружие, переезжают в
 *     раздел `weapons` (StrategicLootHandler выдаёт его через WeaponGrantService).
 *   - Бункер / Остров-ферма / Сердце острова: `BasicMedKit` → настоящий предмет `FirstAidKit`.
 *
 * Максимумы — существующие сид-данные, не новые числа. Совпадение ключей — без учёта регистра,
 * как и collation таблиц. Повторный прогон ничего не меняет.
 *
 * Компенсации за прошлый лут нет (решение владельца 2026-11-27): журнала выданного не было.
 */
class H6FixStrategicLootKeys extends Migration
{
    private const OBJECTS = ['Bunker', 'Technopark', 'GhostCity', 'IslandFarm', 'IslandHeart'];

    /**
     * [раздел-источник, ключ-источник, раздел-цель, ключ-цель].
     */
    private const MOVES = [
        ['resources', 'Wiring', 'crafted_items', 'Wiring'],
        ['resources', 'metalFragments', 'crafted_items', 'metalFragments'],
        ['resources', 'electronicComponents', 'crafted_items', 'electronicComponents'],
        ['crafted_items', 'PipeGun', 'weapons', 'PipeGun'],
        ['crafted_items', 'MetalSpear', 'weapons', 'MetalSpear'],
        ['crafted_items', 'BasicMedKit', 'crafted_items', 'FirstAidKit'],
    ];

    /**
     * Чистое преобразование декодированного `contents` (список групп лута).
     *
     * Переезд в существующий ключ цели (без учёта регистра) оставляет ключ цели на месте со
     * значением большего максимума; переименование в том же разделе без цели — на месте
     * источника; переезд в другой раздел без цели — в конец раздела.
     *
     * @param array<int|string, mixed> $contents
     * @return array<int|string, mixed>
     */
    public static function fixContents(array $contents): array
    {
        foreach ($contents as $i => $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (self::MOVES as [$fromSection, $fromKey, $toSection, $toKey]) {
                $group = self::move($group, $fromSection, $fromKey, $toSection, $toKey);
            }
            $contents[$i] = $group;
        }

        return $contents;
    }

    /**
     * @param array<int|string, mixed> $group
     * @return array<int|string, mixed>
     */
    private static function move(array $group, string $fromSection, string $fromKey, string $toSection, string $toKey): array
    {
        $source = $group[$fromSection] ?? null;
        if (!is_array($source)) {
            return $group;
        }
        $sourceKey = self::findKey($source, $fromKey);
        if ($sourceKey === null) {
            return $group;
        }
        $value = $source[$sourceKey];

        if ($fromSection === $toSection) {
            $targetKey = self::findKey($source, $toKey);
            if ($targetKey === null) {
                $renamed = [];
                foreach ($source as $k => $v) {
                    $renamed[$k === $sourceKey ? $toKey : $k] = $v;
                }
                $group[$fromSection] = $renamed;
                return $group;
            }
            if ($targetKey === $sourceKey) {
                return $group;
            }
            unset($source[$sourceKey]);
            $source[$targetKey] = self::larger($source[$targetKey], $value);
            $group[$fromSection] = $source;
            return $group;
        }

        unset($source[$sourceKey]);
        $group[$fromSection] = $source;

        $target    = is_array($group[$toSection] ?? null) ? $group[$toSection] : [];
        $targetKey = self::findKey($target, $toKey);
        if ($targetKey === null) {
            $target[$toKey] = $value;
        } else {
            $target[$targetKey] = self::larger($target[$targetKey], $value);
        }
        $group[$toSection] = $target;

        return $group;
    }

    /**
     * @param array<int|string, mixed> $section
     */
    private static function findKey(array $section, string $key): int|string|null
    {
        foreach (array_keys($section) as $k) {
            if (strcasecmp((string) $k, $key) === 0) {
                return $k;
            }
        }
        return null;
    }

    private static function larger(mixed $kept, mixed $incoming): mixed
    {
        $keptNum     = is_numeric($kept) ? (float) $kept : 0.0;
        $incomingNum = is_numeric($incoming) ? (float) $incoming : 0.0;
        return $incomingNum > $keptNum ? $incoming : $kept;
    }

    public function up(): void
    {
        // Ресурс «Кристаллы» ищется по name_en, а он NULL.
        $this->db->table('resources')
            ->where('name', 'Кристаллы')
            ->where('name_en', null)
            ->update(['name_en' => 'Crystals']);

        $rows = $this->db->table('world_objects')
            ->select('id, contents')
            ->whereIn('name_en', self::OBJECTS)
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['contents'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $fixed = self::fixContents($decoded);
            if ($fixed === $decoded) {
                continue;
            }
            $this->db->table('world_objects')
                ->where('id', $row['id'])
                ->update([
                    'contents'   => json_encode($fixed, JSON_UNESCAPED_UNICODE),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    public function down(): void
    {
        // Намеренно пусто: откат вернул бы ключи, которые ничего не выдают.
    }
}
