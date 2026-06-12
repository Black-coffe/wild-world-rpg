<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E16 Ф2 (ADR-116) — 3 поясные аномалии (type=anomaly) в поясе Y300-600 («вторая родина»).
 *
 * Аномалии = enterable лор-лендмарки пояса: enter-кнопка авто (MoveCharacterToDirectionAction),
 * хаб (SettlementHubAction TYPE_SERVICES anomaly→anomalyloot), лут (RuinLootService prefix
 * world.anomalies из loot_config по кулдауну), маркеры на TG-/web-карте с лором (Map.php — generic).
 *
 * По §Принцип 1 (север=ценнее) — градиент ВНУТРИ пояса: верхний пояс (меньше Y) ценнее (даёт каплю
 * поясного rarity-1 сырья), нижний — generic. zone_policy=neutral (НЕ safe/НЕ hostile): без стражи
 * (ниже риск чем глубокий север), «опасность» — лор/среда. enabled=0 DORMANT (активация per-anomaly +
 * world.anomalies.enabled). Idempotent по code. WipeManifest: settlements=KEEP (мировой контент).
 */
class Adr116SeedBeltAnomalies extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [code, name, icon, biome (аномальный), targetX, targetY (пояс 300-600), lore, loot{res=>max}]
        $anomalies = [
            [
                'code'  => 'anomaly_forerunner_rift',
                'name'  => 'Разлом Предтеч',
                'icon'  => '🌀',
                'biome' => 8, // Вулканические территории — аномальный, редкий в поясе
                'tx'    => 400, 'ty' => 340, // верхний пояс — ценнее (даёт Кристалл Разлома)
                'lore'  => 'Трещина в самой ткани мира, оставшаяся от тех, кто был до нас. Воздух над ней звенит, металл холодит ладонь, а в глубине растут кристаллы, каких не бывает в честном камне. Сюда приходят те, кому хватает духу взять у Разлома его странное.',
                'loot'  => ['RiftCrystal' => 2, 'RareMetals' => 6, 'Stones' => 8],
            ],
            [
                'code'  => 'anomaly_ash_barrow',
                'name'  => 'Пепельный Курган',
                'icon'  => '⚱️',
                'biome' => 2, // Горы — изобильны в поясе
                'tx'    => 560, 'ty' => 470, // средний пояс (даёт Пепел Предтеч)
                'lore'  => 'Рукотворный холм, насыпанный задолго до коллапса. Под серой коркой — слой за слоем спрессованный пепел Предтеч, всё ещё тёплый, если копнуть глубже. Старики говорят, тут что-то жгли веками. Что — никто уже не помнит.',
                'loot'  => ['ForerunnerAsh' => 2, 'Ironstone' => 8, 'Oil' => 4],
            ],
            [
                'code'  => 'anomaly_humming_field',
                'name'  => 'Гудящее Поле',
                'icon'  => '📡',
                'biome' => 9, // Аномальный пояс-биом
                'tx'    => 470, 'ty' => 560, // нижний пояс — generic (ближе к югу = дешевле)
                'lore'  => 'Низина, где трава лежит кругами, а в ушах стоит ровный гул — будто под землёй до сих пор работает забытая машина. Компас врёт, фонарь мигает. Зато железо и редкие сплавы валяются прямо на виду — никто не задерживается тут надолго, чтобы их собрать.',
                'loot'  => ['RareMetals' => 5, 'Sulfur' => 6, 'Oil' => 4],
            ],
        ];

        foreach ($anomalies as $a) {
            $cell = $this->findBeltCell((int) $a['biome'], (int) $a['tx'], (int) $a['ty']);
            if ($cell === null) {
                continue;
            }
            $anchor = (int) $cell['cell_number'];
            $ax     = (int) $cell['coordinate_x'];
            $ay     = (int) $cell['coordinate_y'];

            $exist = $this->db->table('settlements')->where('code', $a['code'])->get()->getRowArray();
            if (! empty($exist)) {
                continue;
            }
            $this->db->table('settlements')->insert([
                'code'           => $a['code'],
                'name_ru'        => $a['name'],
                'type'           => 'anomaly',
                'anchor_cell'    => $anchor,
                'coordinate_x'   => $ax,
                'coordinate_y'   => $ay,
                'faction_id'     => null,
                'zone_policy'    => 'neutral',
                'enter_min_standing' => null,
                'icon'           => $a['icon'],
                'description_ru' => $a['lore'],
                'loot_config'    => json_encode(['resources' => $a['loot']], JSON_UNESCAPED_UNICODE),
                'enabled'        => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    /**
     * Ближайшая клетка пояса (Y 300-600) в биоме, с расширяющимися фолбэками (как SeedGuardedRuins).
     *
     * @return array{cell_number: int|string, coordinate_x: int|string, coordinate_y: int|string}|null
     */
    private function findBeltCell(int $biome, int $tx, int $ty): ?array
    {
        $byBiome = $this->db->query(
            'SELECT cell_number, coordinate_x, coordinate_y FROM map '
            . 'WHERE coordinate_y BETWEEN 300 AND 600 AND biome_id = ? '
            . 'ORDER BY (ABS(coordinate_x-?)+ABS(coordinate_y-?)) ASC LIMIT 1',
            [$biome, $tx, $ty]
        )->getRowArray();
        if (! empty($byBiome)) {
            return $byBiome;
        }

        // Фолбэк: любая суша пояса (исключаем реки biome 4) рядом с целью.
        $anyBelt = $this->db->query(
            'SELECT cell_number, coordinate_x, coordinate_y FROM map '
            . 'WHERE coordinate_y BETWEEN 300 AND 600 AND biome_id IS NOT NULL AND biome_id <> 4 '
            . 'ORDER BY (ABS(coordinate_x-?)+ABS(coordinate_y-?)) ASC LIMIT 1',
            [$tx, $ty]
        )->getRowArray();

        return empty($anyBelt) ? null : $anyBelt;
    }

    public function down(): void
    {
        $this->db->table('settlements')->whereIn('code', [
            'anomaly_forerunner_rift',
            'anomaly_ash_barrow',
            'anomaly_humming_field',
        ])->delete();
    }
}
