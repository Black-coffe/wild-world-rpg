<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-158 — экран «чего не хватает» + строка правды о времени крафта: killswitch.
 *
 * Экран недостачи: до этого старт крафта отвечал «Недостаточно ресурсов для крафта
 * N шт.» — без списка, чисел и кнопок, хотя точный список сервер уже считал и молча
 * выбрасывал в лог отказа. Прод за 60 дней: 144 отказа у 26 игроков, из них 137 —
 * нехватка СЫРЬЯ и 127 — попытка собрать ОДНУ штуку.
 *
 * Строка правды: свободный стек множителей времени достигает ×0.22 (Мастерская L10 ×
 * Лаборатория L10 × сытость × специализация × фракц-проект), но был полностью невидим
 * — игрок с −78% видел только итоговое число, читал крафт как медленный и просил
 * платное ускорение, которое у него уже есть.
 *
 * Оба ключа — только видимость (read-only рендер), баланс не трогают. Killswitch на
 * случай, если разметка/длина caption повёдет себя неожиданно на живом трафике.
 *
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц нет.
 */
class Adr158CraftShortageHint extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'craft.shortage_hint.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch экрана «чего не хватает». true — при нехватке материалов игрок видит точный список (нужно/есть), в каких биомах добывается недостающее сырьё и сколько стоит докупить, плюс кнопки «Добыть» / «Купить» / переход к компоненту. Порядок вариантов намеренно «сперва собрать и добыть, потом купить»: обратный молча сообщал бы новичку, что платить — нормальный первый ход.',
                'effect_text'        => 'GenericCraftActionStart при missing_materials рендерит экран через CraftShortageService вместо строки «Недостаточно ресурсов». Сам гейт крафта не меняется — это только объяснение отказа.',
                'above_effect_text'  => 'true (вкл): отказ перестаёт быть тупиком, у игрока есть следующий шаг прямо на экране.',
                'below_effect_text'  => 'false (выкл): возвращается прежняя строка без списка и кнопок — мгновенный откат, если разметка или длина сообщения повёдет себя плохо.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'craft.duration_breakdown.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch «строки правды» о времени крафта. true — в уведомлении о запуске рядом с итоговым временем показывается базовое и за счёт чего крафт быстрее («база 15 мин, −78%; ускоряют: Мастерская L10 · Сытость»). Невидимый множитель читается игроком как поломка: с −78% на руках игроки просили ускорить крафт, не зная, что ускорение уже есть, и не связывая его со своими постройками.',
                'effect_text'        => 'GenericCraftActionStart::notifyCraftStarted подставляет CraftDurationBreakdown::truthLine() вместо строки «Время крафта». Без действующих бонусов строка не отличается от прежней — «−0%» не пишем.',
                'above_effect_text'  => 'true (вкл): вложения в постройки, еду и специализацию становятся видимыми и окупаются в глазах игрока.',
                'below_effect_text'  => 'false (выкл): прежняя однострочная подача времени.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
        ];

        $defaults = [
            'category'        => 'craft',
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
            'craft.shortage_hint.enabled',
            'craft.duration_breakdown.enabled',
        ])->delete();
    }
}
