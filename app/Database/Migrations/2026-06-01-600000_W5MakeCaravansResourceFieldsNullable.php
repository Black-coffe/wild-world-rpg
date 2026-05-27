<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W5 (ADR-064) hotfix — caravans.resource_id / quantity / price_per_unit
 * должны быть NULLABLE, чтобы drone-offer caravans могли иметь NULL в этих
 * колонках (drone-offer использует drone_type/gold_price вместо).
 *
 * Bug найден Tier-3 smoke (2026-05-27): `Column resource_id cannot be null`
 * → INSERT с drone-offer бросает DataException → SpawnCaravanCron Failed.
 * Tests (in-memory schema) пропустили — unit-test'ы не ловят NOT NULL constraint
 * с MariaDB-side.
 *
 * Idempotent — MODIFY noop если уже nullable.
 */
class W5MakeCaravansResourceFieldsNullable extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE caravans MODIFY resource_id INT UNSIGNED NULL");
        $this->db->query("ALTER TABLE caravans MODIFY quantity INT UNSIGNED NULL DEFAULT NULL");
        $this->db->query("ALTER TABLE caravans MODIFY price_per_unit INT UNSIGNED NULL DEFAULT NULL");
    }

    public function down(): void
    {
        // Revert не делаем — drop колонок drone_type/gold_price произойдёт
        // в обратной миграции W5AddCaravansOfferType. Если откатывать дальше
        // — придётся ручным UPDATE resource_id=0 для drone-rows.
        // Здесь оставляем nullable (forward-compat).
    }
}
