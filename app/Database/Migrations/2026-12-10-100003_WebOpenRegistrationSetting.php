<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-accounts-p0-01 (ADR-188) — флаг открытой регистрации на сайте.
 *
 *   - web.open_registration (bool, default OFF = закрытая бета): регистрация по email и создание
 *     персонажа на сайте без Telegram. Бот-игроков не касается.
 *
 * Idempotent. game_settings = KEEP (WipeManifest не трогаем).
 */
class WebOpenRegistrationSetting extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('game_settings')->where('setting_key', 'web.open_registration')->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')->insert([
            'setting_key'        => 'web.open_registration',
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_int'          => null,
            'value_float'        => null,
            'value_bool'         => 0,
            'value_string'       => null,
            'default_value_text' => 'false',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'rationale_text'     => 'Гейт открытой регистрации на сайте (ADR-188, Фаза 0). false (default) — закрытая бета: новый аккаунт по email и персонаж без Telegram создать нельзя, страница регистрации честно говорит «регистрация пока закрыта» и ведёт в бота; вход в существующий аккаунт (код из бота, email, OAuth) работает всегда. true — любой посетитель сайта заводит аккаунт по email и создаёт персонажа без Telegram. Включать после проверки почты на проде и решения владельца: веб-персонаж в Фазе 0 ещё не умеет действовать (веб-клиент — Фаза 1).',
            'effect_text'        => 'Читается через GameSettingsService (gsBool) в регистрации на сайте: /account/register и /account/character.',
            'above_effect_text'  => 'true — сайт принимает новых игроков без Telegram (второй канал интейка, игроки из регионов, где Telegram неудобен).',
            'below_effect_text'  => 'false — новые игроки приходят только через бота; сайт даёт вход в уже существующие аккаунты.',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'web.open_registration')->delete();
    }
}
