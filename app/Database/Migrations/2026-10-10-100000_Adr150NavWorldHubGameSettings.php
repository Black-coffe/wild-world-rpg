<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 Слайс 1 — killswitch группы «🌍 Мир» (пере-архитектура навигации).
 *
 * Выводит уже-текстовый компас ходьбы (MoveCharacterAction/MoveSurfaceService) на
 * верхний уровень: нижняя кнопка «Карта» → «🌍 Мир» и ведёт СРАЗУ к компасу-розетке
 * (а не к статичному фото-тупику); фото карты мира демотируется в «🗺 Обзор» внутри
 * экрана Мир (+ кнопка возврата «🧭 Идти»). Плюс slash `/go` (`/world`,`/map` алиасы).
 *
 * false (default) — DORMANT: меню показывает «Карта» → статичное фото (текущее
 * поведение), «🌍 Мир»-роутинг/кнопки «🗺 Обзор» не появляются → byte-identical.
 * true — «Карта»/«🌍 Мир»/`/go` открывают компас; фото — через «🗺 Обзор».
 * Активация после Tier-3 cold-smoke + решение владельца.
 *
 * ⚠️ Деплой-шаг при активации: после флипа — `php spark bot:setcommands` на боте
 * (setMyCommands — серверное состояние Telegram, не применяется кодом автоматически).
 *
 * game_settings = KEEP (WipeManifest не трогаем — таблица уже классифицирована).
 * Idempotent по setting_key. Категория world (рядом с onboarding/nav-флагами).
 */
class Adr150NavWorldHubGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'navigation.world_hub.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-150 Слайс 1: killswitch группы «🌍 Мир». Триггер — жалоба игрока «после обучения непонятно даже как просто ИДТИ»: ходьба (компас) спрятана под Перс→Действия→Двигаться, а нижняя кнопка «Карта» ведёт в статичное фото-тупик без единой кнопки. false (default) — DORMANT, меню/роутинг byte-identical (Карта→фото). true — нижняя кнопка становится «🌍 Мир» и ведёт СРАЗУ к компасу-розетке ходьбы; фото карты мира демотируется в «🗺 Обзор» с кнопкой возврата «🧭 Идти»; slash /go прыгает к компасу. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'BotMenuService.mainReplyKeyboard рендерит «🌍 Мир» вместо «Карта»; GenericmessageCommand роутит текст «🌍 Мир»/«карта» на компас (MoveSurfaceService) вместо фото; экран компаса показывает кнопку «🗺 Обзор». При флаге OFF всё как раньше.',
                'above_effect_text'  => 'true — новичок находит ходьбу с верхнего уровня за 1 тап (закрывает корневую жалобу онбординга), фото карты доступно кнопкой «🗺 Обзор». Требует `php spark bot:setcommands` для /go.',
                'below_effect_text'  => 'false — текущее поведение: «Карта» = статичное фото-тупик, ходьба остаётся спрятанной под Перс→Действия→Двигаться.',
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
        $this->db->table('game_settings')->where('setting_key', 'navigation.world_hub.enabled')->delete();
    }
}
