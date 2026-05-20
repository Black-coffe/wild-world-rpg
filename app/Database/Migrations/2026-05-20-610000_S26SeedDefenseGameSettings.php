<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S26 (v0.51.207) — combat-balance GameSettings для defensive structures
 * (constitutional admin-tunable, ADR-024; ADR-030 fairness-аудит).
 *
 * Combat-knobs (live-tunable, шлифуются по фидбэку без редеплоя). hp/tax —
 * building-колонки. DefenseStructureService читает эти ключи; cap ограничивает
 * суммарное снижение урона. category=combat, value_type=int. Idempotent.
 */
class S26SeedDefenseGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'defense.wall.damage_reduction_percent',
                'value_int'          => 15,
                'default_value_text' => '15',
                'rationale_text'     => 'На сколько % WoodenWall снижает урон, который защитник получает в PvP-бою, пока стоит на своей базе. 15% — заметно, но бой остаётся проигрываемым сильным атакующим (cap 40% суммарно). Защита не бесплатна: decay + daily tax.',
                'effect_text'        => 'DefenseStructureService включает множитель (1 − 0.15) к урону по владельцу-защитнику в раундах PvP у его базы. Суммарное снижение ограничено defense.total_damage_reduction_max_percent.',
                'above_effect_text'  => 'При 50%+ защитник у базы почти неуязвим → PvP-перекос к defender, атака базы теряет смысл.',
                'below_effect_text'  => 'При 0 стена не снижает урон — теряет смысл как защита (остаётся только tax-нагрузкой).',
            ],
            [
                'setting_key'        => 'defense.fence.attacker_damage_per_round',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько HP теряет атакующий за каждый раунд PvP-боя у базы защитника с BarbedFence. 3 — щиплет (за бой ~10-30 урона), не убивает сам по себе; добавляет цену атаке укреплённой базы.',
                'effect_text'        => 'DefenseStructureService наносит этот flat-урон атакующему каждый раунд, когда защитник-владелец защищается у базы. Детерминированно (без RNG — round loop под fixture-fence).',
                'above_effect_text'  => 'При 20+ ограда сама убивает атакующих за бой → защитник побеждает не сражаясь, перекос.',
                'below_effect_text'  => 'При 0 ограда не наносит урон — теряет смысл (остаётся tax-нагрузкой).',
            ],
            [
                'setting_key'        => 'defense.total_damage_reduction_max_percent',
                'value_int'          => 40,
                'default_value_text' => '40',
                'rationale_text'     => 'Жёсткий потолок суммарного снижения урона от ВСЕХ defensive-структур (fairness-барьер из ADR-030). 40% — защитник у базы крепок, но проигрываем; не даёт стакать структуры в неуязвимость.',
                'effect_text'        => 'DefenseStructureService клампит итоговый damage_reduction этим значением, сколько бы стен ни было.',
                'above_effect_text'  => 'При 70%+ укреплённая база почти непробиваема → PvP у базы мёртв.',
                'below_effect_text'  => 'При 10% защита почти не ощущается, стройка структур не оправдана.',
            ],
            [
                'setting_key'        => 'defense.decay_hp_per_attack',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Сколько HP теряет каждая защитная структура за отбитую атаку (fairness-барьер: защита не вечна). При 10 и hp=200 стена держит ~20 атак до поломки, затем неактивна до ремонта (S5).',
                'effect_text'        => 'DefenseStructureService::applyDecay уменьшает character_buildings.hp на это значение после PvP-боя у базы. hp≤0 → структура не учитывается в защите до repair.',
                'above_effect_text'  => 'При 100+ структура ломается за 1-2 атаки → защита почти одноразовая, стройка не оправдана.',
                'below_effect_text'  => 'При 0 структуры вечны (нет sink) → защита бесплатна после постройки, перекос к defender.',
            ],
        ];

        $shared = [
            'category'        => 'combat',
            'value_type'      => 'int',
            'recommended_min' => '0',
            'recommended_max' => '50',
            'hard_min'        => '0',
            'hard_max'        => '100',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'defense.wall.damage_reduction_percent',
            'defense.fence.attacker_damage_per_round',
            'defense.total_damage_reduction_max_percent',
            'defense.decay_hp_per_attack',
        ])->delete();
    }
}
