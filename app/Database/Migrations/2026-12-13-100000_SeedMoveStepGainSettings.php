<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W2.N2-02 (ADR-190) — прирост за одиночный шаг уезжает из hardcoded-чисел
 * `MoveCharacterToDirectionAction` (сила +0.02, опыт +0.03 за шаг, × ранний множитель новичка) в
 * GameSettings под `world.move.*` (категория `world`). Читает {@see \App\Services\World\MoveService}
 * — общее ядро шага бота и веба `/play`. Дефолты равны прежним числам — поведение прода не меняется.
 *
 * Idempotent по `setting_key` (как `SeedWorldMoveGameSettings`). `game_settings` = KEEP
 * (WipeManifest не трогаем — новых таблиц/player-колонок нет).
 */
class SeedMoveStepGainSettings extends Migration
{
    private const KEYS = ['world.move.stat_per_step', 'world.move.xp_per_step'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.move.stat_per_step',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Прирост 💪 силы за один шаг по карте (move_dir_* в боте, клик по соседней клетке и роза в /play). Значение перенесено 1:1 из hardcoded `0.02` прежнего `MoveCharacterToDirectionAction` — ходьба понемногу качает силу, не заменяя бой и работу как основной источник.',
                'effect_text'        => 'MoveService::step() прибавляет это значение × ранний множитель новичка (EarlyProgressionService::gainMultiplier) к силе на каждом успешном шаге. Поход (MarchingTaskHandler) этот ключ не читает.',
                'above_effect_text'  => 'Выше 0.02 — сила растёт от простой ходьбы быстрее, чем задумано: игрок «нахаживает» силу туда-сюда по соседним клеткам вместо боя и работы.',
                'below_effect_text'  => 'Ниже 0.02 — шаг перестаёт давать заметный прирост, исследование пешком теряет часть награды за потраченную усталость.',
                'recommended_min'    => '0.01',
                'recommended_max'    => '0.05',
                'hard_min'           => '0.0',
                'hard_max'           => '0.5',
            ],
            [
                'setting_key'        => 'world.move.xp_per_step',
                'value_type'         => 'float',
                'value_float'        => 0.03,
                'default_value_text' => '0.03',
                'rationale_text'     => 'Прирост ⭐ опыта за один шаг по карте (move_dir_* в боте, клик по соседней клетке и роза в /play). Значение перенесено 1:1 из hardcoded `0.03` прежнего `MoveCharacterToDirectionAction` — движение И есть разведка (ADR-019), поэтому шаг даёт немного опыта.',
                'effect_text'        => 'MoveService::step() прибавляет это значение × ранний множитель новичка (EarlyProgressionService::gainMultiplier) к опыту на каждом успешном шаге. Поход (MarchingTaskHandler) этот ключ не читает.',
                'above_effect_text'  => 'Выше 0.03 — уровень набирается хождением по кругу: прогрессия уходит от добычи, крафта и боя к ходьбе.',
                'below_effect_text'  => 'Ниже 0.03 — разведка пешком почти не двигает уровень, новичку дольше до первых гейтов по уровню.',
                'recommended_min'    => '0.01',
                'recommended_max'    => '0.08',
                'hard_min'           => '0.0',
                'hard_max'           => '1.0',
            ],
        ];

        $defaults = [
            'category'     => 'world',
            'value_int'    => null,
            'value_bool'   => null,
            'value_string' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', self::KEYS)->delete();
    }
}
