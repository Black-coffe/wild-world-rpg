<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-148 (расширение) — статус `undelivered` в firehose: «игрок нажал, а ответа не ушло».
 *
 * Повод (31.07.2026): экраны лавки крафта были мертвы 2.5 месяца, и никто не заметил, потому
 * что firehose знал только про РОУТИНГ — обработчик найден, исключений нет → `status='ok'`.
 * Сама отправка при этом молча падала (см. MediaSender §Тихая потеря photo-экранов).
 *
 * Ставится, только когда за апдейт НЕ прошла ни одна доставка при наличии провалов: провал
 * одной отправки — норма (`editOrSend` штатно роняет правку и вытягивает фолбэком).
 * Причина уходит в `error_text` («не доставлено — sendPhoto: …»), прежняя причина отказа
 * при этом не теряется.
 *
 * ⚠️ Колонка ENUM под STRICT: без этой миграции INSERT со значением вне списка ПАДАЕТ
 * (урок feedback_action_log_enum_strict_values) — поэтому миграция едет вместе с кодом.
 * Таблица уже классифицирована в Config\WipeManifest (новых таблиц/колонок нет).
 */
class Adr148AddUndeliveredStatus extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE player_action_log
             MODIFY COLUMN status ENUM('ok','error','rejected','unrouted','undelivered')
             NOT NULL DEFAULT 'ok'"
        );
    }

    public function down(): void
    {
        // Строки с новым значением сначала сводим к ближайшему по смыслу — иначе ALTER их обнулит.
        $this->db->query("UPDATE player_action_log SET status = 'error' WHERE status = 'undelivered'");
        $this->db->query(
            "ALTER TABLE player_action_log
             MODIFY COLUMN status ENUM('ok','error','rejected','unrouted')
             NOT NULL DEFAULT 'ok'"
        );
    }
}
