<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — win-beat «глава 1 закрыта» (спина-слайс 4).
 *
 * Суб-killswitch усиления момента завершения шага обучающей цепочки «Первые шаги
 * выжившего» (OnbStep* × 8). win_beat=ON → WinBeatService добавляет к done_text полосу
 * прогресса «N из 8» (шаги 1-7) и капстон «Глава 1 закрыта» на финале (recap + рамка
 * главы 2). OFF (default) → compose() возвращает null, notifyOnboarding шлёт легаси-текст
 * byte-identical. Новых наград нет — чистый текст, balance не задет. ОТДЕЛЬНЫЙ от
 * мастер-флага 1a и суб-флагов 1b/2/3. game_settings = KEEP (WipeManifest не трогаем).
 * Idempotent. Категория world.
 */
class S4ColdOpenWinBeatGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.cold_open_v2.win_beat',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Суб-killswitch S4-4: win-beat «глава 1 закрыта» (WinBeatService). false (default) — DORMANT: завершение онбординг-шага шлёт рядовую just-in-time подсказку (done_text) byte-identical. true — к done_text шагов 1-7 добавляется полоса прогресса «N из 8» (эффект Зейгарник: видимая близость финиша тянет дойти, бьёт по обрывам цепочки на Craft 38%/Build 13%, воронка прод 2026-06-26), а финальный шаг (OnbStepLevel5) показывает капстон «ГЛАВА 1 ЗАКРЫТА» (recap пути новичка + рамка главы 2 + CTA в «Квесты») вместо рядового уведомления. Без новых наград — чистый текст. ОТДЕЛЬНЫЙ от мастер-флага 1a — своя player-facing копия → свой Tier-3 + одобрение владельцем.',
                'effect_text'        => 'QuestObjectiveHandler::notifyOnboarding вызывает WinBeatService::compose(titleEn, doneText, reward); non-null (ON + шаг цепочки) заменяет текст уведомления, иначе легаси-путь. Полоса/число шагов выводятся из OnboardingChainCatalog::total (нет дрейфа при росте цепочки).',
                'above_effect_text'  => 'true — новичок видит прогресс по «главе 1» на каждом шаге и триумф-капстон на финише; усиливает ощущение завершённости обучения и тягу дойти до конца.',
                'below_effect_text'  => 'false — завершение шага = рядовая подсказка «что дальше» без полосы прогресса и без капстона (текущее поведение 1a-3).',
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
            'onboarding.cold_open_v2.win_beat',
        ])->delete();
    }
}
