<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 4 — 3 охраняемые руины (НОВЫЕ курируемые, НЕ конвертация random strategic-объектов).
 *
 * По §Принцип 1 — ГЛУБОКИЙ СЕВЕР (Y 40-250: севернее Логова Y197 = max риск↔награда). type=ruins,
 * zone_policy=hostile. Каждая руина: settlement (enabled=0 DORMANT) + loot_config (повторяемый лут) +
 * страж-NPC (npc_type=hostile, ai_behavior=aggressive → AutoPveHandler авто-бьёт) в ростере role=guard.
 *
 * Активация = per-ruin settlements.enabled=1 (маркер/хаб) + settlements.ruins.enabled=1 (лут) +
 * settlements.ruins.guard_enabled=1 (стража). Idempotent. WipeManifest: settlements/settlement_npcs/
 * npcs = KEEP (мировой контент); спавны стражи = npc_spawns (TRANSIENT, крон восстановит).
 */
class SeedGuardedRuins extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [code, name, icon, biome_id (север), targetX, targetY, lore, loot{res=>max}, guard{...}]
        $ruins = [
            [
                'code'   => 'ruin_bunker',
                'name'   => 'Заброшенный Бункер',
                'icon'   => '☢️',
                'biome'  => 8, // Вулканические территории (есть с Y44)
                'tx'     => 300, 'ty' => 60,
                'lore'   => 'Вскрытый доколлапсный военный бункер на выжженном севере. Бетонные кишки уходят под землю, фонит, но в оружейках и складах ещё есть чем поживиться — если переживёшь стражу.',
                'loot'   => ['Sulfur' => 10, 'Ironstone' => 12, 'RareMetals' => 6, 'Oil' => 5],
                'guard'  => [
                    'en' => 'RuinBunkerSentry', 'ru' => 'Часовой Бункера',
                    'descr' => 'Закалённый одиночка, занявший вход в бункер. Бьёт на поражение всякого, кто сунется к его добыче.',
                    'level' => 24, 'health' => 220.0, 'str' => 30, 'agi' => 16, 'int' => 8, 'dmg' => 13.0, 'armor' => 6.0,
                ],
            ],
            [
                'code'   => 'ruin_technopark',
                'name'   => 'Разорённый Технопарк',
                'icon'   => '🏭',
                'biome'  => 7, // Пещеры и подземелья (есть с Y122)
                'tx'     => 650, 'ty' => 140,
                'lore'   => 'Подземный технопарк, разграбленный, но не до конца. В завалах серверных и цехов ржавеют редкие металлы и провода — наследие тех, кто умел строить машины.',
                'loot'   => ['RareMetals' => 7, 'Oil' => 6, 'Ironstone' => 8, 'Stones' => 10],
                'guard'  => [
                    'en' => 'RuinTechnoparkDrone', 'ru' => 'Дрон-страж Технопарка',
                    'descr' => 'Старый боевой дрон на остаточном заряде. Реагирует на движение и атакует без предупреждения.',
                    'level' => 22, 'health' => 200.0, 'str' => 26, 'agi' => 20, 'int' => 10, 'dmg' => 12.0, 'armor' => 5.0,
                ],
            ],
            [
                'code'   => 'ruin_ghostcity',
                'name'   => 'Мёртвый Город-призрак',
                'icon'   => '🏙️',
                'biome'  => 2, // Горы (изобильны на севере)
                'tx'     => 480, 'ty' => 100,
                'lore'   => 'Остов доколлапсного города в горах: пустые окна, обвалившиеся проспекты, ветер в бетонных коробках. Мародёры приходят за стройматериалом и металлом — и не все возвращаются.',
                'loot'   => ['Stones' => 12, 'Ironstone' => 10, 'Wood' => 14, 'RareMetals' => 5],
                'guard'  => [
                    'en' => 'RuinGhostCityScavenger', 'ru' => 'Мародёр Города-призрака',
                    'descr' => 'Одичавший мародёр, считающий руины своими. Защищает добычу ножом и яростью.',
                    'level' => 23, 'health' => 210.0, 'str' => 28, 'agi' => 18, 'int' => 9, 'dmg' => 13.0, 'armor' => 4.0,
                ],
            ],
        ];

        foreach ($ruins as $r) {
            // 1. Курируемая клетка глубокого севера в биоме руины (фолбэки шире).
            $cell = $this->findCell((int) $r['biome'], (int) $r['tx'], (int) $r['ty']);
            if ($cell === null) {
                continue;
            }
            $anchor = (int) $cell['cell_number'];
            $ax     = (int) $cell['coordinate_x'];
            $ay     = (int) $cell['coordinate_y'];

            // 2. Руина (idempotent по code). enabled=0 DORMANT.
            $exist = $this->db->table('settlements')->where('code', $r['code'])->get()->getRowArray();
            if (empty($exist)) {
                $this->db->table('settlements')->insert([
                    'code'           => $r['code'],
                    'name_ru'        => $r['name'],
                    'type'           => 'ruins',
                    'anchor_cell'    => $anchor,
                    'coordinate_x'   => $ax,
                    'coordinate_y'   => $ay,
                    'faction_id'     => null,
                    'zone_policy'    => 'hostile',
                    'icon'           => $r['icon'],
                    'description_ru' => $r['lore'],
                    'loot_config'    => json_encode(['resources' => $r['loot']], JSON_UNESCAPED_UNICODE),
                    'enabled'        => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $settlementId = (int) $this->db->insertID();
            } else {
                $settlementId = (int) $exist['id'];
            }

            // 3. Страж-NPC (idempotent по npc_name_en). hostile/aggressive → AutoPveHandler авто-бьёт.
            $g       = $r['guard'];
            $guardEn = (string) $g['en'];
            $gExist  = $this->db->table('npcs')->where('npc_name_en', $guardEn)->get()->getRowArray();
            if (empty($gExist)) {
                $this->db->table('npcs')->insert([
                    'npc_name_en'      => $guardEn,
                    'npc_name_ru'      => $g['ru'],
                    'npc_type'         => 'hostile',
                    'description'      => $g['descr'],
                    'level'            => $g['level'],
                    'health'           => $g['health'],
                    'strength'         => $g['str'],
                    'agility'          => $g['agi'],
                    'intellect'        => $g['int'],
                    'damage_type'      => 'Physical',
                    'damage_value'     => $g['dmg'],
                    'attack_speed'     => 1.00,
                    'armor'            => $g['armor'],
                    'aggression_range' => 1,
                    'is_boss'          => 0,
                    'ai_behavior'      => 'aggressive',
                ]);
                $guardId = (int) $this->db->insertID();
            } else {
                $guardId = (int) $gExist['id'];
            }

            // 4. Ростер: страж (role=guard). SettlementPlacementHandler ставит при guard_enabled.
            if ($guardId > 0) {
                $rExist = $this->db->table('settlement_npcs')
                    ->where('settlement_id', $settlementId)->where('npc_id', $guardId)->get()->getRowArray();
                if (empty($rExist)) {
                    $this->db->table('settlement_npcs')->insert([
                        'settlement_id' => $settlementId, 'npc_id' => $guardId, 'role' => 'guard',
                        'service_key' => null, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Ближайшая клетка глубокого севера (Y 40-250) в биоме, с расширяющимися фолбэками.
     *
     * @return array{cell_number: int|string, coordinate_x: int|string, coordinate_y: int|string}|null
     */
    private function findCell(int $biome, int $tx, int $ty): ?array
    {
        $byBiome = $this->db->query(
            'SELECT cell_number, coordinate_x, coordinate_y FROM map '
            . 'WHERE coordinate_y BETWEEN 40 AND 250 AND biome_id = ? '
            . 'ORDER BY (ABS(coordinate_x-?)+ABS(coordinate_y-?)) ASC LIMIT 1',
            [$biome, $tx, $ty]
        )->getRowArray();
        if (! empty($byBiome)) {
            return $byBiome;
        }

        $anyNorth = $this->db->query(
            'SELECT cell_number, coordinate_x, coordinate_y FROM map '
            . 'WHERE coordinate_y BETWEEN 40 AND 250 AND biome_id IS NOT NULL AND biome_id <> 4 '
            . 'ORDER BY (ABS(coordinate_x-?)+ABS(coordinate_y-?)) ASC LIMIT 1',
            [$tx, $ty]
        )->getRowArray();

        return empty($anyNorth) ? null : $anyNorth;
    }

    public function down(): void
    {
        $codes    = ['ruin_bunker', 'ruin_technopark', 'ruin_ghostcity'];
        $guardsEn = ['RuinBunkerSentry', 'RuinTechnoparkDrone', 'RuinGhostCityScavenger'];

        foreach ($codes as $code) {
            $s = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
            if (! empty($s)) {
                $this->db->table('settlement_npcs')->where('settlement_id', (int) $s['id'])->delete();
                $this->db->table('settlements')->where('id', (int) $s['id'])->delete();
            }
        }
        foreach ($guardsEn as $en) {
            $g = $this->db->table('npcs')->where('npc_name_en', $en)->get()->getRowArray();
            if (! empty($g)) {
                $this->db->table('npc_spawns')->where('npc_id', (int) $g['id'])->delete();
                $this->db->table('npcs')->where('id', (int) $g['id'])->delete();
            }
        }
    }
}
