<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-097 — Подталкивание выбора фракции: 4 GameSettings (admin-tunable, ADR-024).
 *
 * Диагноз: осведомлённость уже максимальна (невыбравшие получили крон-уведомление
 * до 288 раз), но конверсия 15/440. Рычаг — не громкость, а убедительность + стимул +
 * анти-спам:
 *   • faction.choice.incentive_enabled / incentive_gold — ОДНОРАЗОВЫЕ «подъёмные» за
 *     первый выбор фракции (повод выбрать СЕЙЧАС; начисляются в ChooseFaction::fractionation).
 *   • faction.notification.max_reminders / interval_hours — потолок и каданс крон-нагов
 *     (FactionNotificationHandler), чтобы прекратить спам (288 нагов → блок бота).
 *
 * Каждый ключ с rich rationale (why/effect/above/below) — invariant ADR-024.
 * category='endgame' (как faction.project.*). Idempotent.
 *
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц/player-колонок нет.
 */
class Adr097FactionChoiceNudge extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'faction.choice.incentive_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch одноразовых «подъёмных» (золото) за первый выбор фракции. true — игрок получает бонус в момент выбора + сообщения упоминают его как стимул выбрать сейчас.',
                'effect_text'        => 'true → ChooseFaction::fractionation начисляет faction.choice.incentive_gold ОДИН раз при первом вступлении (выбор необратим → повтор невозможен); экран выбора и крон-уведомление называют сумму. false → стимул не начисляется и не упоминается.',
                'above_effect_text'  => 'true (вкл): прямой повод выбрать фракцию сейчас — поднимает конверсию из 15/440 невыбравших.',
                'below_effect_text'  => 'false (выкл): мгновенный откат стимула без редеплоя (если золото-бонус разгоняет инфляцию или эксплуатируется).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'faction.choice.incentive_gold',
                'value_type'         => 'int',
                'value_int'          => 1000,
                'default_value_text' => '1000',
                'rationale_text'     => 'Размер одноразовых «подъёмных» за первый выбор фракции (≈одна faction-квест-награда). 1000 — заметно для игрока ур.10, но скромно против инфляции; начисляется один раз (выбор необратим).',
                'effect_text'        => 'increaseGold(+N) в ChooseFaction::fractionation при первом вступлении; сумма показывается в экране выбора и крон-уведомлении как стимул.',
                'above_effect_text'  => 'Выше (напр. 5000): сильнее мотивирует выбор, но раздувает золото-массу и обесценивает другие источники золота.',
                'below_effect_text'  => 'Ниже (напр. 200): стимул почти незаметен, конверсия не растёт — смысл стимула теряется.',
                'recommended_min'    => '500',
                'recommended_max'    => '5000',
                'hard_min'           => '0',
                'hard_max'           => '100000',
            ],
            [
                'setting_key'        => 'faction.notification.max_reminders',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Потолок крон-уведомлений о выборе фракции на персонажа (FactionNotificationHandler). 5 — пара дней мягких напоминаний, потом тишина; дальше discoverability держат кнопка «⚑ Выбрать фракцию» на экране Персонажа + tips. До ADR-097 потолка НЕ было (невыбравшие получали до 288 нагов → спам и блокировки бота).',
                'effect_text'        => 'Когда notification_count ≥ значения, крон прекращает напоминать (read-side кнопка/tips остаются). 0 — без потолка (старое поведение, бесконечный наг).',
                'above_effect_text'  => 'Выше (напр. 30): больше напоминаний — выше шанс заметить, но растёт риск notification fatigue и блокировок бота.',
                'below_effect_text'  => 'Ниже (напр. 2): почти не напоминаем — меньше раздражения, но и меньше шанс, что игрок вернётся к выбору.',
                'recommended_min'    => '2',
                'recommended_max'    => '15',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'faction.notification.interval_hours',
                'value_type'         => 'int',
                'value_int'          => 24,
                'default_value_text' => '24',
                'rationale_text'     => 'Минимальный интервал (часы) между повторными крон-уведомлениями о фракции одному персонажу. 24 — раз в сутки, не назойливо.',
                'effect_text'        => 'FactionNotificationHandler шлёт повтор только если с последнего прошло ≥ значения часов И не превышен max_reminders.',
                'above_effect_text'  => 'Выше (напр. 72): реже напоминаем — мягче, но дольше до выбора.',
                'below_effect_text'  => 'Ниже (напр. 6): чаще напоминаем — навязчивее, быстрее упираемся в max_reminders.',
                'recommended_min'    => '12',
                'recommended_max'    => '72',
                'hard_min'           => '1',
                'hard_max'           => '720',
            ],
        ];

        $defaults = [
            'category'        => 'endgame',
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
            'faction.choice.incentive_enabled',
            'faction.choice.incentive_gold',
            'faction.notification.max_reminders',
            'faction.notification.interval_hours',
        ])->delete();
    }
}
