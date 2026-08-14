<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Правка «одна инструкция вместо двух» на первом экране (аудит 2026-08-14).
 *
 * Новичок получает ДВА сообщения подряд: первое (носитель постоянной reply-клавиатуры)
 * приказывает «Используйте меню ниже для выбора действия», второе — welcome с единственным
 * CTA «🧭 Сделать первый шаг». Замер по 49 регистрациям с 24.07 (окно 24 часа у всех):
 * 14 человек из 39 (36%) выбрали нижнее меню, и активности у них втрое меньше, чем у нажавших
 * CTA — 10.9 тапа против 37.0. Нижнее меню ведёт в хабы (Я / Мир / База / Дела), ничего не
 * значащие для впервые открывшего бота, и не возвращает к полярной звезде.
 *
 * Выключено по умолчанию: первое впечатление — высокоставочная копия, ей нужен свой Tier-3
 * и одобрение владельцем до экспозиции (тот же принцип, что у `start_greeting` и
 * `single_screen`, ADR-139).
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key.
 */
class ColdOpenMenuDeferGameSettings extends Migration
{
    private const KEY = 'onboarding.cold_open_v2.menu_defer';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => self::KEY,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'default_value_text' => '0',
            'rationale_text'     => 'Убирает с первого экрана вторую, конкурирующую инструкцию. Сообщение, которое несёт постоянную reply-клавиатуру, больше не приказывает «используйте меню ниже», а направляет вперёд, к экрану Роби с единственным призывом «Сделать первый шаг». Выключено по умолчанию: копия первого впечатления субъективна и требует одобрения владельца до экспозиции.',
            'effect_text'        => 'Меняется ТОЛЬКО текст первого сообщения новичку в StartCommand (ColdOpenGreetingService::menuAttachText). Сама reply-клавиатура по-прежнему ставится и остаётся доступной — прятать её нельзя, иначе меню превратится в загадку. Существующих игроков правка не касается вовсе: у них свой путь /start с текстом «🧭 Меню ниже». Эффект измеряется напрямую: обе кнопки первого экрана помечены источником `cold` (ADR-168), поэтому первый шаг новичка отделён от полусотни прочих мест, шлющих тот же callback `move`.',
            'above_effect_text'  => 'Значений выше 1 нет — это переключатель.',
            'below_effect_text'  => 'Выключить (0) — возврат к прежнему тексту «Добро пожаловать! Используйте меню ниже для выбора действия». Вернётся и измеренное расщепление маршрута: доля новичков будет уходить в нижнее меню вместо подготовленного первого шага и делать втрое меньше действий.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->get()
            ->getRowArray();

        if (empty($exists)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', self::KEY)->delete();
    }
}
