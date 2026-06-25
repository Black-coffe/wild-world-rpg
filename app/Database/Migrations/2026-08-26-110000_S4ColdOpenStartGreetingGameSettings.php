<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — cold-open framing /start (спина-слайс 1b).
 *
 * Суб-killswitch для cold-open приветствия (короткий интригующий вариант /start вместо
 * легаси 121-слова). ОТДЕЛЬНЫЙ от мастер-флага `onboarding.cold_open_v2.enabled` (уже ON —
 * для полярной звезды 1a): первое впечатление = субъективная высокоставочная копия, ей нужен
 * свой Tier-3 + одобрение копии владельцем до экспозиции (П10). false (default) → DORMANT,
 * byte-identical (StartCommand шлёт легаси-приветствие). game_settings = KEEP (WipeManifest
 * не трогаем). Idempotent (как Adr138). Категория world — как все онбординг-ключи.
 */
class S4ColdOpenStartGreetingGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.cold_open_v2.start_greeting',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Суб-killswitch S4-1b: cold-open приветствие /start (ColdOpenGreetingService) вместо легаси 121-слова. false (default) — DORMANT: новичок видит прежнее приветствие (byte-identical). true — короткий интригующий вариант (постапок-тон Роби, ~3 абзаца), ведущий к CTA «назвать героя» (S1: 58% не называют чара — первый экран слишком плотный для bounce). ОТДЕЛЬНЫЙ от мастер-флага (он ON для полярной звезды): копия первого впечатления субъективна → свой Tier-3 + одобрение владельца до активации.',
                'effect_text'        => 'StartCommand для нового чара: greetingEnabled() ON → cold-open текст, OFF → легаси. Имя-CTA / стартовый паёк / спавн не меняются.',
                'above_effect_text'  => 'true — короткое приветствие может поднять named-rate (42%→цель ≥65%) и снизить bounce; риск — потеря части лор-контекста первого экрана (компенсируется /guide + полярной звездой).',
                'below_effect_text'  => 'false — легаси 121-слово: полнее по лору, но плотнее → выше шанс отвала до именования.',
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
            'onboarding.cold_open_v2.start_greeting',
        ])->delete();
    }
}
