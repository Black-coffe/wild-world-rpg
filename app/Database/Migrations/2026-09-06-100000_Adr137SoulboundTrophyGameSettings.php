<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB9 (ADR-137 «Узлы») — GameSettings soulbound-трофеев «Метка пустоши».
 *
 * При killе узла среди вкладчиков «Облавы» (eligible WB8) разыгрывается РЕДКИЙ soulbound-трофей
 * (оружие/броня, «не продаётся / не теряется») — взвешенная по вкладу лотерея (НЕ last-hit, НЕ AFK).
 * Шанс растёт с уровнем узла, гейт по нижнему уровню (юг не сыпет уникумами). Бонус трофея —
 * **RAID-ONLY**: усиливает урон ТОЛЬКО против узлов (в PvP/обычном PvE не влияет — решение владельца,
 * портрет П4 «без PvP-power-creep»; PvP-стат отрезан в отдельный будущий ADR).
 *
 * Дефолты — стартовые (ROADMAP §3); калибровка — WB15. Constitutional rule (ADR-024):
 * rationale/effect/above/below NOT NULL. Seed game_settings → WipeManifest=KEEP. Идемпотентно.
 */
class Adr137SoulboundTrophyGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'combat.nodes.soulbound_min_level', 'type' => 'int', 'value' => 50,
                'rationale' => 'Нижний уровень узла, с которого вообще может выпасть soulbound-трофей (ADR-137 WB9). Юг (L1-49) не должен сыпать уникумами — трофей = знак того, что взял по-настоящему сильную точку (центр/север).',
                'effect' => 'Минимальный уровень узла для шанса трофея.',
                'above' => 'Выше: трофеи доступны только на дальнем севере → слишком редки, П7-коллекционер фрустрирован.',
                'below' => 'Ниже: трофеи фармятся на лёгком юге → теряют престиж, девальвация «Метки пустоши».',
                'rmin' => 1, 'rmax' => 150, 'hmin' => 1, 'hmax' => 999,
            ],
            [
                'setting_key' => 'combat.nodes.soulbound_base_chance', 'type' => 'float', 'value' => 0.05,
                'rationale' => 'Базовый шанс трофея на пороге soulbound_min_level (ADR-137 WB9). Итоговый шанс = base + (level × per_level), но не выше soulbound_chance_max. Низкая база → трофей остаётся событием, а не рутиной.',
                'effect' => 'Стартовая вероятность трофея у минимально-квалифицирующего узла.',
                'above' => 'Выше: трофеи частят уже на средних узлах → инфляция уникумов.',
                'below' => 'Ниже: на средних узлах трофей почти никогда → гринд-стена.',
                'rmin' => 0.0, 'rmax' => 0.5, 'hmin' => 0.0, 'hmax' => 1.0,
            ],
            [
                'setting_key' => 'combat.nodes.soulbound_chance_per_level', 'type' => 'float', 'value' => 0.002,
                'rationale' => 'Прирост шанса трофея за каждый уровень узла (ADR-137 WB9). При base=0.05, per=0.002: L50→0.15, L100→0.25, L150→0.35(cap). Делает северные (высокие) узлы заметно щедрее на трофеи — награда за риск.',
                'effect' => 'Сколько добавляется к шансу трофея за каждый уровень узла.',
                'above' => 'Выше: потолок шанса достигается слишком рано → север гарантированно сыпет трофеи.',
                'below' => 'Ниже: даже топовые узлы редко дают трофей → север недонаграждён.',
                'rmin' => 0.0, 'rmax' => 0.02, 'hmin' => 0.0, 'hmax' => 1.0,
            ],
            [
                'setting_key' => 'combat.nodes.soulbound_chance_max', 'type' => 'float', 'value' => 0.35,
                'rationale' => 'Потолок шанса soulbound-трофея (ADR-137 WB9). Даже самый высокий узел не гарантирует трофей — сохраняет редкость и ценность «Метки пустоши» как достижения.',
                'effect' => 'Максимальная вероятность трофея независимо от уровня узла.',
                'above' => 'Выше: топ-узлы почти всегда дают трофей → девальвация, П7 теряет цель коллекционирования.',
                'below' => 'Ниже: трофей всегда маловероятен → фрустрация на честно взятом топ-узле.',
                'rmin' => 0.05, 'rmax' => 1.0, 'hmin' => 0.0, 'hmax' => 1.0,
            ],
            [
                'setting_key' => 'combat.nodes.soulbound_raid_bonus_pct', 'type' => 'float', 'value' => 0.05,
                'rationale' => 'RAID-ONLY бонус: каждый владеемый soulbound-трофей усиливает урон игрока ТОЛЬКО против узлов на эту долю (ADR-137 WB9). Изолированный слой BossEncounterService — в PvP и обычном PvE НЕ влияет (решение владельца, П4 «без PvP-power-creep»). Помогает добивать всё более сильные узлы по мере коллекции трофеев.',
                'effect' => 'Прибавка к урону по узлам за каждый трофей (только в бою с узлом).',
                'above' => 'Выше: коллекция трофеев делает верхние узлы тривиальными → теряется co-op-стена.',
                'below' => 'Ниже: трофеи почти не влияют на бой → нет ощущения прогресса от коллекции.',
                'rmin' => 0.0, 'rmax' => 0.2, 'hmin' => 0.0, 'hmax' => 10.0,
            ],
            [
                'setting_key' => 'combat.nodes.soulbound_raid_bonus_cap', 'type' => 'float', 'value' => 0.5,
                'rationale' => 'Потолок суммарного RAID-ONLY бонуса от всех трофеев (ADR-137 WB9). Не даёт коллекции из десятков трофеев обнулить вызов узлов — co-op остаётся нужен на верхних тирах.',
                'effect' => 'Максимальная суммарная прибавка к урону по узлам от трофеев.',
                'above' => 'Выше: ветеран с большой коллекцией соло-выносит то, что задумано для группы.',
                'below' => 'Ниже: бонус упирается в потолок быстро → смысла копить много трофеев нет.',
                'rmin' => 0.0, 'rmax' => 2.0, 'hmin' => 0.0, 'hmax' => 100.0,
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $insert = [
                'setting_key' => $r['setting_key'], 'category' => 'combat', 'value_type' => $r['type'],
                'default_value_text' => (string) $r['value'],
                'rationale_text' => $r['rationale'], 'effect_text' => $r['effect'],
                'above_effect_text' => $r['above'], 'below_effect_text' => $r['below'],
                'recommended_min' => $r['rmin'], 'recommended_max' => $r['rmax'],
                'hard_min' => $r['hmin'], 'hard_max' => $r['hmax'],
                'created_at' => $now, 'updated_at' => $now,
            ];
            $insert[$r['type'] === 'float' ? 'value_float' : 'value_int'] = $r['value'];
            $this->db->table('game_settings')->insert($insert);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'combat.nodes.soulbound_min_level', 'combat.nodes.soulbound_base_chance',
            'combat.nodes.soulbound_chance_per_level', 'combat.nodes.soulbound_chance_max',
            'combat.nodes.soulbound_raid_bonus_pct', 'combat.nodes.soulbound_raid_bonus_cap',
        ])->delete();
    }
}
