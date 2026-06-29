<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-148 (расширение охвата) — добавляет 'task' в enum `player_action_log.source`.
 *
 * Фоновые завершения задач игрока (Worker: добыча/крафт/стройка/поход/ремонт/…) пишутся
 * через {@see \App\Services\Logging\PlayerActionLogger::recordTaskCompletion()} с source='task'.
 * STRICT-режим (урок feedback_action_log_enum_strict_values): значение вне enum = «Data
 * truncated» ERROR → enum обязан включать 'task' ДО первой записи.
 */
class Adr148PlayerActionLogAddTaskSource extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `player_action_log`
             MODIFY `source` ENUM('callback','command','text','forcereply','other','task')
             NOT NULL DEFAULT 'other'"
        );
    }

    public function down(): void
    {
        // Переводим уже записанные task-строки в допустимое значение перед сужением enum.
        $this->db->query("UPDATE `player_action_log` SET `source`='other' WHERE `source`='task'");
        $this->db->query(
            "ALTER TABLE `player_action_log`
             MODIFY `source` ENUM('callback','command','text','forcereply','other')
             NOT NULL DEFAULT 'other'"
        );
    }
}
