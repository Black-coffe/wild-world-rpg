<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Расширение enum `action_log.action_status`: + 'REJECTED'.
 *
 * Баг (найден 2026-06-09 при фиксе логирования экономики): `BaseAction::logRejected`
 * (F1.10) пишет `action_status='REJECTED'`, но колонка была enum('Pending','Completed',
 * 'Skipped') → MySQL в strict-режиме отвергал значение, save() падал, try/catch глотал →
 * ВСЕ rejected-записи (форензика отклонённых действий/эксплойт-попыток) молча терялись.
 *
 * После этой миграции logRejected начинает писать живые строки.
 */
class ExtendActionLogStatusEnum extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `action_log`
             MODIFY `action_status` ENUM('Pending','Completed','Skipped','REJECTED')
             NOT NULL DEFAULT 'Pending'"
        );
    }

    public function down(): void
    {
        // Переводим уже записанные REJECTED в допустимое значение перед сужением enum.
        $this->db->query(
            "UPDATE `action_log` SET `action_status`='Skipped' WHERE `action_status`='REJECTED'"
        );
        $this->db->query(
            "ALTER TABLE `action_log`
             MODIFY `action_status` ENUM('Pending','Completed','Skipped')
             NOT NULL DEFAULT 'Pending'"
        );
    }
}
