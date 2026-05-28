<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W17 (vNext2, Фаза 4, ADR-071) — GameSettings PvP-дуэлей (admin-tunable, ADR-024).
 *
 * `pvp.duel.enabled` (bool, default **false = DORMANT**): кнопка «⚔️ Дуэль» и логика дуэли
 * не активны (PlayerDetectionService без изменений). Активация в конце ROADMAP.
 * baseline_* — общий уровень/статы/здоровье для stat-equalize (честная арена). Idempotent.
 */
class W17SeedPvpDuelGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'pvp.duel.enabled',
                'category'           => 'combat',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch opt-in дуэлей (ADR-071). true — игроки с флагом duels_open могут получать честные дуэли (stat-equalize, без death/XP-loss). false — DORMANT: кнопка «⚔️ Дуэль» и логика не активны, PlayerDetectionService без изменений. Активация в конце ROADMAP с анонсом.',
                'effect_text'        => 'DuelService::enabled. true → DuelAction (duel_<id>) проводит бой на equalized-статах через неизменный simulateFight (null defense), исход без последствий.',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (текущий default) = дуэли выключены (обычное летальное PvP не затронуто).',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.duel.baseline_level',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Уровень, к которому нормализуются оба бойца в дуэли (stat-equalize). 20 — середина прогрессии: ни нубский, ни эндгеймный. Влияет на level-diff компонент урона (в дуэли всегда 0, оба равны).',
                'effect_text'        => 'DuelService::equalize override level обоих бойцов перед simulateFight.',
                'above_effect_text'  => 'Выше — номинально, эффект минимален (level-diff в дуэли = 0 при равных).',
                'below_effect_text'  => 'Ниже — то же; знаковость только для логов/нарратива.',
                'recommended_min'    => '10',
                'recommended_max'    => '50',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.duel.baseline_stat',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Значение силы/ловкости/интеллекта, к которому нормализуются оба бойца. 50 — даёт ощутимые stats-бонусы (сила→урон, ловкость→уворот) без перекоса. Равны у обоих → решает билд (экип) + RNG.',
                'effect_text'        => 'DuelService::equalize override strength/agility/intellect обоих к этому значению.',
                'above_effect_text'  => 'Выше → больше урон/уворот у обоих (бои короче/динамичнее), но симметрично.',
                'below_effect_text'  => 'Ниже → слабее статы у обоих, больше веса у оружия/RNG.',
                'recommended_min'    => '20',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.duel.baseline_health',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 1000,
                'default_value_text' => '1000',
                'rationale_text'     => 'Здоровье, к которому нормализуются оба бойца в дуэли. 1000 — достаточно для нескольких раундов (видно отыгрыш билда), но не марафон до 150 раундов. Равно у обоих.',
                'effect_text'        => 'DuelService::equalize override health/max_health обоих к этому значению (+ tired=full, без tired-штрафа).',
                'above_effect_text'  => 'Выше → дольше бои (риск упереться в pvpMaxRounds=150 → exhausted-ничья).',
                'below_effect_text'  => 'Ниже → бои короче, выше дисперсия исхода (один удачный удар решает).',
                'recommended_min'    => '300',
                'recommended_max'    => '3000',
                'hard_min'           => '1',
                'hard_max'           => '1000000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'pvp.duel.enabled', 'pvp.duel.baseline_level', 'pvp.duel.baseline_stat', 'pvp.duel.baseline_health',
            ])->delete();
    }
}
