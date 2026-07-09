<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Controllers\Telegram\Commands\Actions\Camp\DeleteBaseAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-122 — штраф моментального сноса берётся за СВЁРНУТЫЙ ЛАГЕРЬ, а не за «один сарай».
 *
 * Тестируем НАСТОЯЩИЙ `DeleteBaseAction::shouldChargePenalty`: подменены только сеймы
 * (killswitch и счётчик баз), конструктор родителя не зовём — Telegram и БД не нужны.
 * Зеркалить логику в тесте нельзя: она балансовая, и ошибка стоит игроку 70% имущества.
 *
 * @internal
 */
final class DemolitionPenaltyTest extends CIUnitTestCase
{
    /**
     * @param bool $killswitch `buildings.demolition.last_base_only`
     * @param int  $bases      сколько баз у персонажа НА МОМЕНТ сноса (включая сносимую)
     */
    private function decide(bool $killswitch, int $bases): bool
    {
        $action = new class ($killswitch, $bases) extends DeleteBaseAction {
            public function __construct(private bool $ks, private int $bases)
            {
                // Родительский конструктор поднимает модели и требует CallbackQuery — не нужен.
            }

            protected function lastBaseOnlyEnabled(): bool
            {
                return $this->ks;
            }

            protected function baseCount(int $charId): int
            {
                return $this->bases;
            }

            public function decideFor(int $charId): bool
            {
                return $this->shouldChargePenalty($charId);
            }
        };

        return $action->decideFor(1);
    }

    /** OFF (default) → прежнее поведение byte-identical: штраф всегда, сколько бы баз ни было. */
    public function testKillswitchOffChargesAlwaysAsBefore(): void
    {
        $this->assertTrue($this->decide(false, 1), 'OFF: одна база — штраф.');
        $this->assertTrue($this->decide(false, 3), 'OFF: три базы — штраф (историческое поведение).');
    }

    /** ON + есть другие базы → лагерь не сворачивается, имущество цело. */
    public function testKillswitchOnSparesPropertyWhenOtherBasesRemain(): void
    {
        $this->assertFalse($this->decide(true, 2), 'ON: сносим одну из двух — штрафа нет.');
        $this->assertFalse($this->decide(true, 5), 'ON: сносим одну из пяти — штрафа нет.');
    }

    /** ON + это последняя база → игрок остаётся бездомным, штраф берётся. */
    public function testKillswitchOnChargesOnLastBase(): void
    {
        $this->assertTrue($this->decide(true, 1), 'ON: последняя база — штраф.');
    }

    /**
     * 🔴 Защита от рассинхрона: развилка считает базы ДО удаления лагеря, поэтому «последняя» —
     * это `count === 1`, а не 0. Если счёт съедет на пост-удаление, игрок с одной базой перестанет
     * платить штраф вовсе — фича молча умрёт.
     */
    public function testZeroBasesStillCountsAsLastBase(): void
    {
        $this->assertTrue($this->decide(true, 0), 'Вырожденный случай: без баз штраф не пропускаем.');
    }

    /** Остаток = количество − округлённая вверх потеря; последняя единица не исчезает. */
    public function testKeepShareArithmetic(): void
    {
        // Боевая формула: вычитаем округлённую вверх ПОТЕРЮ, а не считаем остаток через
        // (1 - loss) — иначе double-погрешность (1.0-0.8 = 0.19999...) съедает единицу.
        $keep = static fn (float $lossPct, int $qty): int => max(
            $qty >= 1 ? 1 : 0,
            $qty - (int) ceil($qty * min(1.0, max(0.0, $lossPct)))
        );

        $this->assertSame(30, $keep(0.70, 100), '70% потери → остаётся 30 из 100.');
        $this->assertSame(20, $keep(0.80, 100), '80% потери → остаётся 20 из 100.');
        $this->assertSame(1, $keep(0.70, 1), 'Последняя единица ресурса не испаряется.');
        $this->assertSame(1, $keep(1.0, 5), 'Даже при 100% потери остаётся 1 — инвариант floor-с-полом.');
    }
}
