<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\CharacterFactionModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-097 — регресс на root-cause бага выбора фракции (insert-ветка ChooseFaction::fractionation).
 *
 * CharacterFactionModel валидирует `notification_count => required`. Insert БЕЗ него молча
 * падает (insert()→false, валидация до обращения к БД) — раньше запись не создавалась, а
 * золото-стимул всё равно начислялся бы (эксплойт повторного выбора). Пинуем контракт: без
 * notification_count insert падает валидацию. Поэтому fractionation обязан слать
 * notification_count и начислять подъёмные только при успешной фиксации ($saved).
 *
 * @internal
 */
final class CharacterFactionInsertValidationTest extends CIUnitTestCase
{
    public function testInsertWithoutNotificationCountFailsValidation(): void
    {
        $model  = new CharacterFactionModel();
        $result = $model->insert([
            'character_id'        => 999991,
            'faction_id'          => 4,
            'joined_at'           => '2026-06-04 12:00:00',
            'notified_at'         => '2026-06-04 12:00:00',
            'notification_status' => 'True',
        ]);

        $this->assertFalse($result, 'insert без notification_count обязан падать валидацию (root-cause бага fractionation)');
        $this->assertArrayHasKey('notification_count', $model->errors());
    }
}
