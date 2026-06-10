<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 1 — Foundation: «последний визит» игрока.
 *
 * `telegram_users.last_seen` фиксирует момент ПОСЛЕДНЕГО входящего взаимодействия
 * (сообщение / нажатие кнопки). Проставляется в `BotController::webhook()` ПОСЛЕ
 * `handle()` (через LastSeenService), поэтому во время обработки апдейта код видит
 * ПРЕДЫДУЩЕЕ значение — основа для оффлайн-digest (Ф2) «пока тебя не было».
 *
 * НЕ реюзаем `updated_at` — тот трогается кронами (E1: ненадёжный сигнал активности).
 *
 * Оживляет латентную dormant-фичу: `EventDispatcher::buildContext()` теперь подставляет
 * реальный `last_seen_hours_ago` (был всегда null, TODO F7.6) → NightAttacks
 * `sleeping_player_skip` перестаёт быть мёртвым (не бьём спящих ночными атаками).
 *
 * WipeManifest: колонка добавлена в reset-map telegram_users (IDENTITY_RESET) —
 * новый сезон стартует с чистым last_seen.
 */
class E6AddLastSeenToTelegramUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('telegram_users', [
            'last_seen' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('telegram_users', 'last_seen');
    }
}
