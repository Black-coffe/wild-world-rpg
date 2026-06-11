<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-120 (E20) — tip про автоматизацию + GameSettings онбординг-хинта
 * + drive-by фикс enum-коэрсии tip_type.
 *
 * 1) Tip «Автоматизация» в /tips (game_tips, тип 'крафт') — discoverability-намёк
 *    (конституция UX-DISCOVERABILITY): роботы + дроны + путь входа через Ангар.
 * 2) Drive-by: 3 старых tip'а (Settlements/GuardedRuins/NorthBosses) сидились с
 *    tip_type='world' ВНЕ enum → MySQL коэрсил в '' (class-of-bug ADR-104
 *    «значение вне enum»). Чиним на валидные категории.
 * 3) 2 ключа GameSettings окна онбординг-хинта AUTOMATION (one-shot при открытии
 *    экрана базы без Мастерской): min_level=12 (синхронно с квестом «Своя автоматика»),
 *    max_level=25 (ветеранов не пингуем — анти soft-broadcast, они находят кнопку «Ангар»).
 *
 * Seed существующих таблиц (game_tips/game_settings = KEEP) → WipeManifest не
 * затрагивается. Idempotent (по title_en / setting_key; фикс — только пустым tip_type).
 */
class Adr120AutomationTipAndHintSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1) Tip «Автоматизация».
        $titleEn = 'AutomationHangar';
        if (empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            $content = '🤖 *Автоматизация:* построй *Мастерскую робототехники* на базе — и пустошь начнёт работать на тебя. '
                . '*Роботы* часами исследуют карту и добывают ресурсы, пока ты офлайн. *Дроны* — мгновенная разведка зоны 21×21, '
                . 'доставка груза на базу с дальних клеток, ремонт всех роботов разом и защита базы в бою. '
                . 'Весь парк — в *🤖 Ангаре* на экране базы; крафт — в Стандартном верстаке.';

            $this->db->table('game_tips')->insert([
                'title_ru'   => '🤖 Автоматизация: роботы и дроны',
                'title_en'   => $titleEn,
                'tip_type'   => 'крафт',
                'content'    => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2) Drive-by фикс enum-коэрсии (только строки с пустым tip_type).
        $fixes = [
            'Settlements' => 'биомы',
            'GuardedRuins' => 'биомы',
            'NorthBosses' => 'NPC',
        ];
        foreach ($fixes as $en => $type) {
            $this->db->table('game_tips')
                ->where('title_en', $en)
                ->where('tip_type', '')
                ->update(['tip_type' => $type, 'updated_at' => $now]);
        }

        // 3) GameSettings окна хинта.
        $rows = [
            [
                'setting_key'        => 'onboarding.automation_hint.min_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'Нижняя граница уровня для one-shot хинта «Автоматизация» (E20/ADR-120): показывается при открытии экрана базы игроку без Мастерской робототехники. 12 — синхронно с квестом «Своя автоматика» и после фракционного гейта L10: игрок уже укоренился, экономика позволяет думать о мастерской.',
                'effect_text'        => 'Порог level в OnboardingHintService::maybeSendAutomationHint. Ниже порога хинт молчит (новичок ещё строит базовое).',
                'above_effect_text'  => 'Выше (напр. 20): хинт приходит позже — больше игроков L12-19 не узнают про автоматизацию вовремя, воронка RoboticsWorkshop останется узкой.',
                'below_effect_text'  => 'Ниже (напр. 5): хинт у новичков, которым мастерская не по карману → шум, обесценивание подсказок.',
                'recommended_min'    => '10',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '100',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'onboarding.automation_hint.max_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Верхняя граница окна хинта «Автоматизация»: ветеранов L25+ одноразовым пушем не дёргаем (анти soft-broadcast, как интро дейликов E8) — они находят всегда видимую кнопку «🤖 Ангар» на экране базы сами.',
                'effect_text'        => 'Потолок level в maybeSendAutomationHint. Выше потолка хинт не шлётся никогда.',
                'above_effect_text'  => 'Выше (напр. 100): хинт получат и ветераны при следующем открытии базы — разовая волна пушей по старожилам.',
                'below_effect_text'  => 'Ниже (напр. 15): окно L12-15 слишком узкое — большинство среднеуровневых не увидит подсказку.',
                'recommended_min'    => '15',
                'recommended_max'    => '40',
                'hard_min'           => '1',
                'hard_max'           => '202',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'AutomationHangar')->delete();
        $this->db->table('game_settings')->whereIn('setting_key', [
            'onboarding.automation_hint.min_level',
            'onboarding.automation_hint.max_level',
        ])->delete();
    }
}
