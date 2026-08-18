<?php

namespace Tests\Unit\TeleportBeacon;

use App\Entities\BiomeEntity;
use App\Services\Player\TeleportBeacon\BeaconMessageFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регресс-гвард прод-бага 2026-07-08 (char 1106, `teleportBeaconSet`):
 *
 *   installSuccess(): Argument #4 ($biomeRow) must be of type ?array,
 *   App\Entities\BiomeEntity given
 *
 * `BiomeModel::$returnType = BiomeEntity` (F1.4.1), а форматтер объявляет `?array`.
 * Под strict_types это TypeError. Коварство: маяк к тому моменту УЖЕ установлен
 * (шаг 1 `install()` отрабатывает до отправки) — игрок просто не видел подтверждения.
 *
 * Тест фиксирует контракт: `BiomeEntity::toArray()` обязан скармливаться форматтеру
 * без падения и давать корректные поля биома в тексте.
 *
 * @internal
 */
final class BeaconInstallSuccessEntityTest extends CIUnitTestCase
{
    /** @return array<string,mixed> */
    private function biomeRow(): array
    {
        return [
            'id'                       => 4,
            'name'                     => 'Пепельные пустоши',
            'description'              => 'Серый пепел скрипит под ногами.',
            'biome_type'               => 'ash',
            'danger_level'             => 3,
            'danger_level_text'        => 'Опасно',
            'survival_difficulty'      => 2,
            'survival_difficulty_text' => 'Тяжело',
            'occurrence_rate'          => 0.15,
        ];
    }

    public function testEntityToArrayFeedsFormatterWithoutTypeError(): void
    {
        $entity = new BiomeEntity($this->biomeRow());

        // Ровно то, что теперь делает TeleportBeaconSetAction: Entity → array в отдельной переменной.
        $biomeRow = $entity->toArray();
        $this->assertIsArray($biomeRow, 'toArray() обязан вернуть массив — иначе снова TypeError');

        $payload = (new BeaconMessageFormatter())->installSuccess(
            120,
            340,
            777,
            $biomeRow,
            2,
            1,
            3,
            100
        );

        $this->assertArrayHasKey('text', $payload);
        $this->assertStringContainsString('Пепельные пустоши', $payload['text'], 'Имя биома обязано попасть в текст');
        $this->assertStringContainsString('X=120, Y=340', $payload['text']);
        $this->assertSame('Markdown', $payload['parse_mode']);
    }

    public function testNullBiomeStillRendersInstallConfirmation(): void
    {
        // Клетка без биома → подтверждение всё равно уходит (маяк-то установлен).
        $payload = (new BeaconMessageFormatter())->installSuccess(1, 2, 3, null, 0, 0, 3, 100);

        $this->assertStringContainsString('???', $payload['text'], 'Неизвестный биом → плейсхолдер, не падение');
        $this->assertArrayHasKey('reply_markup', $payload);
    }
}
