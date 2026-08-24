<?php

declare(strict_types=1);

namespace Tests\Unit\World;

use App\Controllers\Telegram\Commands\Actions\MarchAction;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * chat-requests-batch-03 — экран настройки маршрута называет потолок заказа вместо
 * молчаливого зажима (feedback Max Syskov «в походе могу выбрать максимум 60 клеток,
 * это баг или фича?)»).
 *
 * Ревью-доследование: гонять `capLine()` в изоляции не ловит разрыв «текст vs клэмп» —
 * если `showRouteSetup()` передаст в `capLine()` литерал 60 вместо реального `$cap`
 * (или вовсе уберёт вызов из `$text`), три старых теста этого файла остались бы
 * зелёными: они проверяли только рендер строки по УЖЕ переданному числу, не то,
 * ОТКУДА оно берётся и совпадает ли с тем, чем реально зажат `$n`. Красным до правки
 * был только `ReflectionException`, не содержательная проверка.
 *
 * Фикс — `MarchAction::clampOrderToCap()`: клэмп `$n` И построение `capLine()`
 * ОДНОЙ функцией, один возврат `{n, cap, capLine}`. `showRouteSetup()` теперь читает
 * оба потребителя из этого возврата — разойтись негде физически, не только по
 * договорённости. Тесты ниже бьют в эту функцию (не в `capLine()` напрямую) —
 * гоняют ТОТ ЖЕ код, который реально зажимает заказ, а не отдельный рендер.
 *
 * @internal
 */
final class MarchCapTextTest extends CIUnitTestCase
{
    /** @return array{n:int,cap:int,capLine:string} */
    private function invokeClamp(int $n, int $maxStepsPerOrder): array
    {
        $method = new ReflectionMethod(MarchAction::class, 'clampOrderToCap');
        $method->setAccessible(true);

        $march = (new \ReflectionClass(MarchAction::class))->newInstanceWithoutConstructor();

        /** @var array{n:int,cap:int,capLine:string} $result */
        $result = $method->invoke($march, $n, ['max_steps_per_order' => $maxStepsPerOrder]);

        return $result;
    }

    /**
     * Главная проверка ревью: число в `capLine` ОБЯЗАНО совпадать с тем, чем
     * реально зажат заказ (`cap`/`n` при переборе) — не с каким-то параллельным
     * литералом. Заказ 999 при потолке 60 клэмпится до 60, и текст называет 60.
     */
    public function testClampNamesTheSameCapItClampsWith(): void
    {
        $result = $this->invokeClamp(999, 60);

        $this->assertSame(60, $result['n'], 'заказ клэмпится к потолку профиля');
        $this->assertSame(60, $result['cap']);
        $this->assertStringContainsString('60', $result['capLine']);
        $this->assertStringContainsString('клеток', $result['capLine']);
    }

    /**
     * Потолок зависит от транспорта (ADR-174) — ни клэмп, ни текст не имеют права
     * хранить своё собственное число: подставили другой профиль — оба поменялись
     * СИНХРОННО, каким бы число ни оказалось (пешком/машина/подкрутка из админки).
     */
    public function testClampReflectsVehicleDependentCapNotAFixedSixty(): void
    {
        $onFoot    = $this->invokeClamp(999, 60);
        $onVehicle = $this->invokeClamp(999, 25);

        $this->assertSame(60, $onFoot['cap']);
        $this->assertSame(60, $onFoot['n']);
        $this->assertStringContainsString('60', $onFoot['capLine']);

        $this->assertSame(25, $onVehicle['cap']);
        $this->assertSame(25, $onVehicle['n']);
        $this->assertStringContainsString('25', $onVehicle['capLine']);
        $this->assertStringNotContainsString('60', $onVehicle['capLine']);
    }

    /** Заказ ниже потолка не трогается — клэмп однонаправленный (только сверху). */
    public function testClampLeavesOrderBelowCapUntouched(): void
    {
        $result = $this->invokeClamp(10, 60);

        $this->assertSame(10, $result['n'], 'заказ ниже потолка не режется');
        $this->assertSame(60, $result['cap'], 'потолок в тексте — всё равно реальный (60), не заказанное число');
    }

    public function testClampCapLineIsMarkdownSafe(): void
    {
        $result = $this->invokeClamp(999, 60);

        $this->assertSame(0, substr_count($result['capLine'], '*') % 2, 'непарная * роняет Legacy Markdown отправку молча');
    }
}
