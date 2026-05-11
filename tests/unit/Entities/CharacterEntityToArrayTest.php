<?php

namespace Tests\Unit\Entities;

use App\Entities\CharacterEntity;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регрессионный тест на prod-баг 2026-05-11 (дважды): код, делегирующий персонажа
 * (полученного через `Model->first()` = {@see CharacterEntity}) в сервис, ожидающий
 * `array<string,mixed>`, использовал `(array) $entity` — а это даёт mangled-ключи
 * (`"\0*\0attributes"` и т.п.), не плоский `['id' => ...]`. Итог: `$character['id']`
 * === null → `Undefined array key "id"` (см. `ResourceTradeService`, `handleTradeReply`,
 * `SellResourceAction::finalizeSale`, `BuyResourceAction::finalizePurchase`).
 *
 * Правильный способ — `$entity->toArray()` (либо array-access прямо по Entity,
 * т.к. {@see \App\Entities\Traits\ArrayAccessibleEntity}).
 *
 * @internal
 */
final class CharacterEntityToArrayTest extends CIUnitTestCase
{
    public function testArrayCastProducesMangledKeysAndLosesId(): void
    {
        $entity = new CharacterEntity(['id' => 491, 'gold' => 1000, 'level' => 1]);

        $cast = (array) $entity;

        $this->assertArrayNotHasKey('id', $cast,
            '(array) $entity — антипаттерн: ключи mangled, плоского "id" нет');
    }

    public function testToArrayProducesFlatKeys(): void
    {
        $entity = new CharacterEntity(['id' => 491, 'gold' => 1000, 'level' => 1]);

        $arr = $entity->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertSame(491, $arr['id']);
        $this->assertArrayHasKey('gold', $arr);
        $this->assertSame(1000, $arr['gold']);
    }

    public function testArrayAccessWorksDirectly(): void
    {
        $entity = new CharacterEntity(['id' => 491, 'gold' => 1000]);

        $this->assertSame(491, $entity['id']);
        $this->assertSame(1000, $entity['gold']);
    }
}
