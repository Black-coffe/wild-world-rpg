<?php

namespace Tests\Unit\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction;
use App\Entities\CharacterEntity;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регресс-гейт контракта «AttackPlayerAction нормализует CharacterEntity → array».
 *
 * Прод-инцидент 2026-06-14 (поле-PvP «Атаковать», 3× CRITICAL): $attacker приходит
 * CharacterEntity (F1.4 — getUserAndCharacter()/CharacterModel::find() возвращают Entity),
 * а PvpRoundOrchestrator::simulateFight($p1) и PvpRewardOrchestrator::processMutualExhaustion
 * строго типизированы `array` → `TypeError: must be of type array, CharacterEntity given`
 * на AttackPlayerAction.php:188. Фикс — нормализация обоих бойцов на входе handle()
 * через toCharacterArray(). Тест падает, если конвертер сломать.
 *
 * Pure-метод (static) — тестируется без инстанса контроллера (его конструктор требует
 * CallbackQuery). RNG-fence не затрагивается: toRawArray() даёт те же данные.
 *
 * @internal
 */
final class AttackPlayerActionTest extends CIUnitTestCase
{
    public function testToCharacterArrayConvertsEntityToPlainArray(): void
    {
        $entity = new CharacterEntity([
            'id'       => 880,
            'name'     => 'SarCasM',
            'level'    => 20,
            'health'   => 100.0,
            'strength' => 50,
        ]);

        $arr = AttackPlayerAction::toCharacterArray($entity);

        // Главное: на выходе именно plain array (а не Entity) → strict `array`-консьюмеры ок.
        $this->assertIsArray($arr);
        $this->assertArrayHasKey('id', $arr);
        $this->assertEquals(880, $arr['id']);
        $this->assertEquals('SarCasM', $arr['name']);
        $this->assertEquals(20, $arr['level']);
    }

    public function testToCharacterArrayPassesPlainArrayThrough(): void
    {
        $row = ['id' => 7, 'name' => 'X', 'faction' => 'roamers'];

        $this->assertSame($row, AttackPlayerAction::toCharacterArray($row));
    }

    public function testToCharacterArrayReturnsEmptyForNonCharacter(): void
    {
        // Защитный fallback (не должно случаться при валидном входе, но без TypeError).
        $this->assertSame([], AttackPlayerAction::toCharacterArray(null));
        $this->assertSame([], AttackPlayerAction::toCharacterArray('not-a-character'));
    }
}
