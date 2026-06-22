<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB8 (ADR-137 «Узлы») — GameSettings дележа лута «Облавы» (золото по вкладу + TTL ledger).
 *
 * Узлы — первая в v1 повторяемая цель, дающая ЗОЛОТО (обычный лут). Пул золота = level-based,
 * делится между всеми, кто внёс ≥ `loot_min_contribution_pct` урона (от max_health узла),
 * пропорционально вкладу. Золото — faucet без sink → `gold_hard_cap` страхует от гиперинфляции
 * при росте килов/уровней. Soulbound-трофей (оружие/броня) — редкий дроп, выдаётся WB9.
 *
 * Дефолты — стартовые (ROADMAP §3); финальная калибровка — WB15. Constitutional rule (ADR-024):
 * rationale/effect/above/below NOT NULL. Seed game_settings → WipeManifest=KEEP. Идемпотентно по
 * setting_key.
 */
class Adr137NodesLootGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'combat.nodes.gold_per_level', 'type' => 'int', 'value' => 50,
                'rationale' => 'Золото с узла = base_pool: current_level × это значение (ADR-137 WB8). Узлы — главный новый золото-faucet эндгейма; масштаб с уровнем делает северные узлы экономически выгоднее южных (награда растёт с риском). Пул делится между участниками «Облавы» по вкладу.',
                'effect' => 'Множитель золота за уровень узла (пул до floor/cap).',
                'above' => 'Выше: килы узлов заливают экономику золотом → инфляция цен на рынке, обесценивание добычи.',
                'below' => 'Ниже: поход на узел экономически не окупается → высокие узлы никто не бьёт ради золота.',
                'rmin' => 0, 'rmax' => 500, 'hmin' => 0, 'hmax' => 100000,
            ],
            [
                'setting_key' => 'combat.nodes.gold_floor', 'type' => 'int', 'value' => 100,
                'rationale' => 'Минимальный пул золота с узла любого уровня (ADR-137 WB8). Гарантирует, что даже низкоуровневый южный узел (L1-3) стоит похода для новичка — нижний порог поверх level×gold_per_level.',
                'effect' => 'Нижняя граница пула золота независимо от уровня.',
                'above' => 'Выше: даже тривиальные южные узлы дают много золота → фарм-эксплойт на низких тирах.',
                'below' => 'Ниже: южные узлы почти не дают золота → новичку нет смысла их трогать.',
                'rmin' => 0, 'rmax' => 5000, 'hmin' => 0, 'hmax' => 100000,
            ],
            [
                'setting_key' => 'combat.nodes.gold_hard_cap', 'type' => 'int', 'value' => 50000,
                'rationale' => 'Потолок пула золота с одного узла (ADR-137 WB8). Золото = faucet без sink → жёсткий cap страхует от гиперинфляции при росте килов и уровней (верхние узлы L150+ × gold_per_level превысили бы разумное). Анти-инфляционный предохранитель.',
                'effect' => 'Верхняя граница пула золота с узла.',
                'above' => 'Выше: верхние узлы заливают экономику → гиперинфляция, рынок ломается.',
                'below' => 'Ниже: эндгейм-узлы недоплачивают относительно усилий → нет экономического стимула на севере.',
                'rmin' => 1000, 'rmax' => 200000, 'hmin' => 0, 'hmax' => 100000000,
            ],
            [
                'setting_key' => 'combat.nodes.loot_min_contribution_pct', 'type' => 'float', 'value' => 0.05,
                'rationale' => 'Минимальная доля урона (от max_health узла), чтобы попасть в дележ лута (ADR-137 WB8). Отсекает AFK-пассажиров, ткнувших узел один раз ради халявы, но не наказывает легитимного участника большой «Облавы» (порог абсолютный — от полоски HP узла, не от доли группы).',
                'effect' => 'Порог участия: доля HP узла, которую надо снять для права на лут.',
                'above' => 'Выше: мелкие, но честные вкладчики в большой облаве остаются без лута → демотивация участвовать.',
                'below' => 'Ниже: ткнул-и-ушёл пассажир получает долю наравне с теми, кто реально бил → халява и абьюз.',
                'rmin' => 0.0, 'rmax' => 0.5, 'hmin' => 0.0, 'hmax' => 1.0,
            ],
            [
                'setting_key' => 'combat.nodes.engagement_ttl_min', 'type' => 'int', 'value' => 720,
                'rationale' => 'TTL строки ledger вклада в минутах (ADR-137 WB8). Строки потребляются при килле узла; этот GC чистит вклад по НЕДОБИТЫМ узлам (игрок ослабил, ушёл, узел зарегенил и не был убит) — чтобы ledger не копил мусор. 720 мин = 12ч ≈ окно одной «жизни» узла (respawn_hours).',
                'effect' => 'Через сколько минут простоя строка вклада удаляется GC-кроном.',
                'above' => 'Выше: вклад «висит» дольше → если узел добили сильно позже, давний чиппер ещё в дележе (может быть честно), но ledger пухнет.',
                'below' => 'Ниже: вклад сгорает быстро → отступивший игрок, вернувшийся добить позже, теряет учтённый ранее урон.',
                'rmin' => 60, 'rmax' => 4320, 'hmin' => 1, 'hmax' => 100000,
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
            'combat.nodes.gold_per_level', 'combat.nodes.gold_floor', 'combat.nodes.gold_hard_cap',
            'combat.nodes.loot_min_contribution_pct', 'combat.nodes.engagement_ttl_min',
        ])->delete();
    }
}
