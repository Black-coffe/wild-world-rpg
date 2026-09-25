<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-bridge-p1-01 (ADR-189) — флаг игры на сайте.
 *
 *   - web.play_enabled (bool, default OFF): `/play` — вся игра в браузере через маршруты бота,
 *     входящие и колокольчик. Выключен — `/play` показывает заглушку.
 *
 * Idempotent. game_settings = KEEP (WipeManifest не трогаем).
 */
class WebPlayEnabledSetting extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('game_settings')->where('setting_key', 'web.play_enabled')->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')->insert([
            'setting_key'        => 'web.play_enabled',
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
            'rationale_text'     => 'Гейт игры на сайте (ADR-189, Фаза 1). false (default) — /play показывает заглушку, входящие на сайте не пишутся; фоновые сообщения виртуальным персонажам всё равно не уходят в Telegram (защита работает при любом значении флага). true — вошедший на сайт игрок играет в браузере теми же маршрутами, что и в боте, а колокольчик показывает входящие. Включать после Tier-3 смоука на preprod и решения владельца.',
            'effect_text'        => 'Читается через GameSettingsService (gsBool) в /play, /play/act, /play/inbox и при записи входящих на сайте.',
            'above_effect_text'  => 'true — сайт становится вторым полноценным клиентом игры: web-only персонажи играют, связанные игроки видят копии фоновых сообщений на сайте.',
            'below_effect_text'  => 'false — игра только в Telegram; сайт даёт вход, кабинет и заглушку «Играть».',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'web.play_enabled')->delete();
    }
}
