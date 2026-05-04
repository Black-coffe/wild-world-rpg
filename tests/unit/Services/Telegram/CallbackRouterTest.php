<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\CallbackRouter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.5 — тесты wildcard-роутера. Critical path: ничто из реальной
 * mmorpg-логики не должно измениться при переходе с inline-mapping в
 * CallbackqueryCommand на этот роутер. Тесты — страховка.
 */
final class CallbackRouterTest extends CIUnitTestCase
{
    public function testResolveExactMatch(): void
    {
        $router = new CallbackRouter();
        $router->register('character', 'CharacterAction');

        $this->assertSame('CharacterAction', $router->resolve('character'));
    }

    public function testResolveReturnsNullForUnknownCallback(): void
    {
        $router = new CallbackRouter();
        $router->register('character', 'CharacterAction');

        $this->assertNull($router->resolve('unknown'));
    }

    public function testResolveWildcardMatchesSuffix(): void
    {
        $router = new CallbackRouter();
        $router->register('move_dir_*', 'MoveAction');

        $this->assertSame('MoveAction', $router->resolve('move_dir_north'));
        $this->assertSame('MoveAction', $router->resolve('move_dir_south_east'));
    }

    public function testResolveWildcardDoesNotMatchOtherPrefix(): void
    {
        $router = new CallbackRouter();
        $router->register('move_dir_*', 'MoveAction');

        $this->assertNull($router->resolve('move_dirx'));
        $this->assertNull($router->resolve('attack_north'));
    }

    public function testFirstMatchWins_SpecificBeforeWildcard(): void
    {
        // Порядок регистрации = порядок проверки. Регистрируем
        // специфичный паттерн ДО wildcard'а, чтобы он выиграл.
        $router = new CallbackRouter();
        $router->register('move_dir_north', 'NorthSpecificAction');
        $router->register('move_dir_*', 'GenericMoveAction');

        $this->assertSame('NorthSpecificAction', $router->resolve('move_dir_north'));
        $this->assertSame('GenericMoveAction', $router->resolve('move_dir_south'));
    }

    public function testFirstMatchWins_WildcardBeforeSpecificSwallows(): void
    {
        // Если зарегистрировать wildcard РАНЬШЕ — он перехватит всё.
        // Это документированное поведение; пользователь должен сам
        // следить за порядком.
        $router = new CallbackRouter();
        $router->register('move_dir_*', 'GenericMoveAction');
        $router->register('move_dir_north', 'NorthSpecificAction');

        $this->assertSame('GenericMoveAction', $router->resolve('move_dir_north'));
    }

    public function testRegisterManyAtOnce(): void
    {
        $router = new CallbackRouter();
        $router->registerMany([
            'character' => 'CharacterAction',
            'inventory' => 'InventoryAction',
            'pollVote_*' => 'PollVoteAction',
        ]);

        $this->assertSame('CharacterAction', $router->resolve('character'));
        $this->assertSame('InventoryAction', $router->resolve('inventory'));
        $this->assertSame('PollVoteAction', $router->resolve('pollVote_42'));
    }

    public function testWildcardMatchesEmptyTail(): void
    {
        // 'foo_*' должен матчить 'foo_' (один разделитель + ничего после).
        $router = new CallbackRouter();
        $router->register('foo_*', 'FooAction');

        $this->assertSame('FooAction', $router->resolve('foo_'));
    }

    public function testRegexCharsInPatternAreEscaped(): void
    {
        // Если в callback_data появятся символы вроде `.` или `(` — они
        // не должны интерпретироваться как regex. preg_quote() в коде.
        $router = new CallbackRouter();
        $router->register('action.with.dots', 'DotsAction');

        $this->assertSame('DotsAction', $router->resolve('action.with.dots'));
        $this->assertNull($router->resolve('actionXwithXdots'));
    }

    public function testGetRoutesReturnsRegisteredRoutes(): void
    {
        $router = new CallbackRouter();
        $router->register('character', 'CharacterAction');
        $router->register('move_dir_*', 'MoveAction');

        $routes = $router->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertFalse($routes[0]['isWildcard']);
        $this->assertTrue($routes[1]['isWildcard']);
    }
}
