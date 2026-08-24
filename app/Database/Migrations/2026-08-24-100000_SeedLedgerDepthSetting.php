<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Story chat-requests-batch-06 — глубина ленты экрана «🧾 Куда ушло» ADMIN-TUNABLE
 * (CLAUDE.md §🎛️, ADR-024): не магическое число в `LedgerService`, а ключ `game_settings`
 * с полной rationale. `game_settings` = KEEP (WipeManifest не трогаем — не новая таблица).
 */
class SeedLedgerDepthSetting extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'economy.ledger.depth';

        if (! empty($this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray())) {
            return; // idempotent
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'resources',
            'value_type'         => 'int',
            'value_int'          => 15,
            'default_value_text' => '15',
            'rationale_text'     => 'Сколько последних записей ленты «Куда ушло» показывать одним сообщением (Ivan Divan «лога движения средств тоже нету»; Max Syskov «исчезло 50%, сравнивал "сегодня 15:03" и "сейчас"»). 15 — покрывает «вчера было, сегодня нет» при обычном темпе игры (несколько экономических событий в день: налог раз в цикл, редкие продажи/покупки, изредка событие или смерть), но не превращается в простыню в одном сообщении.',
            'effect_text'        => 'LedgerService::entries() — LIMIT на выборку из action_log и event_effects_log (независимо, до слияния и сортировки по времени), затем array_slice() после сортировки до этого же числа. Пагинации нет — лента фиксированной глубины (Non-goal story 06).',
            'above_effect_text'  => 'Выше (напр. 60): лента покрывает больше истории за раз — реже «не вижу, куда делось на прошлой неделе», но сообщение растёт, и при частой экономике (много продаж/налогов/азартных ставок) строки старше нескольких часов начинают выпадать по 4096-символьному бюджету текстового сообщения renderScreen() раньше, чем должны бы по смыслу «глубина N» (ревью-находка: экран текстовый — `sendMessage`/`editMessageText`, лимит 4096, а не 1024 у подписи к фото).',
            'below_effect_text'  => 'Ниже (напр. 5): лента показывает буквально последние минуты — типичный вопрос «куда делось со вчерашнего дня» останется без ответа, если за это время было больше 5 событий (например, несколько циклов налога подряд).',
            'recommended_min'    => '5',
            'recommended_max'    => '30',
            'hard_min'           => '1',
            'hard_max'           => '50',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'economy.ledger.depth')->delete();
    }
}
