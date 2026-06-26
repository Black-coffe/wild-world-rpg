<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S5 (ROADMAP-RETENTION-10, ADR-142) — гейт «Навеса» (первого укрытия).
 *
 * Два ключа гейтят ВИДИМОСТЬ кнопки «⛺ Навес» в списке построек (FirstShelterService):
 *   - enabled (killswitch, default OFF → dormant): кнопка не добавляется, BuildListAction
 *     рендерит как раньше (byte-identical ~93 игрокам).
 *   - max_level: потолок «новичка» — Навес предлагается только тем, кто на ранней стене
 *     (единый порог с contextual_hints/comeback/day2 = 6); ветеранам не показываем.
 *
 * Стоимость/время «Навеса» — в Config\Buildings::$recipes['LeanTo'] + tasks.min/max_duration
 * (единый паттерн со всеми зданиями, не GS-tunable как и остальные 15 рецептов). Здесь —
 * ТОЛЬКО feature-gate. game_settings = KEEP (WipeManifest не трогаем). Idempotent. Категория world.
 */
class S5FirstShelterGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.first_build.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S5 «первого укрытия» (Навес/LeanTo). false (default) — DORMANT: кнопка «⛺ Навес» не добавляется в список построек, BuildListAction byte-identical. true — новичку (level ≤ max_level) без единой постройки в списке первой кнопкой предлагается дешёвый one-shot Навес (25 дерева + 10 воды, ~3 мин, налог 0), закрывающий горлышко OnbStepBuild (Build-конверсия 9% — #1 утечка воронки). Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'FirstShelterService::enabled гейтит добавление кнопки «Навес» в BuildListAction.',
                'above_effect_text'  => 'true — новички получают доступную первую постройку → ожидаемый рост Build-конверсии 9%→20-25% (удар по #1 утечке онбординг-воронки).',
                'below_effect_text'  => 'false — первая постройка остаётся за стеной обычных зданий (crafted_items + 60-мин таймер), Build-конверсия 9%; текущее поведение.',
            ],
            [
                'setting_key'        => 'onboarding.first_build.max_level',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'recommended_min'    => 3,
                'recommended_max'    => 10,
                'hard_min'           => 1,
                'hard_max'           => 100,
                'rationale_text'     => 'Потолок уровня «новичка», которому предлагается Навес. 6 — единый порог с contextual_hints.max_level / comeback / day2. Ветеранам (выше) первое укрытие не нужно — у них уже есть базы и постройки; показ был бы шумом.',
                'effect_text'        => 'FirstShelterService::shouldOffer — characters.level ≤ этого значения (вместе с «0 построек»).',
                'above_effect_text'  => 'Выше — Навес увидят игроки постарше (но гейт «0 построек» всё равно отсечёт тех, у кого уже что-то построено).',
                'below_effect_text'  => 'Ниже — уже когорта (только самые свежие новички получат предложение Навеса).',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
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
        $this->db->table('game_settings')->whereIn('setting_key', [
            'onboarding.first_build.enabled',
            'onboarding.first_build.max_level',
        ])->delete();
    }
}
