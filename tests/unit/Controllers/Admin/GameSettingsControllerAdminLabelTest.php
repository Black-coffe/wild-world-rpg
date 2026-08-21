<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use App\Controllers\Admin\GameSettingsController;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * Регресс на баг «updated_by = unknown» (2026-08-21): `currentAdminLabel()` звал
 * несуществующий `$auth->user()` (реальный `service('auth')` — `App\Libraries\Authentication`,
 * у которой есть только `getCurrentUser()`) — `method_exists` был всегда false, метка
 * всегда падала в 'unknown'-ветку. Покрываем чистую функцию резолва напрямую, без
 * session/DB seam'ов.
 */
final class GameSettingsControllerAdminLabelTest extends CIUnitTestCase
{
    public function testResolvesEmailWhenPresent(): void
    {
        $user = (object) ['id' => 7, 'email' => 'admin@wildworld.fun', 'name' => 'Admin'];

        $this->assertSame(
            'admin@wildworld.fun',
            GameSettingsController::resolveAdminLabel($user)
        );
    }

    public function testFallsBackToNameWhenEmailMissing(): void
    {
        $user = (object) ['id' => 7, 'email' => '', 'name' => 'Andrei'];

        $this->assertSame('Andrei', GameSettingsController::resolveAdminLabel($user));
    }

    public function testFallsBackToAdminIdWhenNoEmailOrName(): void
    {
        $user = (object) ['id' => 7];

        $this->assertSame('admin#7', GameSettingsController::resolveAdminLabel($user));
    }

    public function testReturnsUnknownOnlyWhenNoUserObject(): void
    {
        $this->assertSame('unknown', GameSettingsController::resolveAdminLabel(null));
    }

    /**
     * Реальный shape `App\Entities\User` (из `App\Libraries\Authentication::getCurrentUser()`) —
     * этот тест красный без фикса, потому что раньше метка бралась не отсюда вовсе.
     */
    public function testResolvesFromAuthenticationLibraryUserShape(): void
    {
        $user = (object) [
            'id'    => 3,
            'name'  => 'Administrator',
            'email' => 'root@wildworld.fun',
        ];

        $this->assertSame('root@wildworld.fun', GameSettingsController::resolveAdminLabel($user));
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    /**
     * End-to-end: подменяет `service('auth')` фейком, у которого — как у реальной
     * `App\Libraries\Authentication` — есть только `getCurrentUser()`, но НЕТ `user()`.
     * До фикса `currentAdminLabel()` звал `user()`, `method_exists` был false и метка
     * всегда падала в 'unknown' — этот тест был бы красным на старом коде.
     */
    public function testCurrentAdminLabelUsesGetCurrentUserNotUser(): void
    {
        $fakeAuth = new class {
            public function getCurrentUser(): object
            {
                return (object) ['id' => 5, 'name' => 'Andrei', 'email' => 'andrei@wildworld.fun'];
            }
        };

        Services::injectMock('auth', $fakeAuth);

        $controller = new GameSettingsController();
        $method     = new ReflectionMethod($controller, 'currentAdminLabel');
        $method->setAccessible(true);

        $this->assertSame('andrei@wildworld.fun', $method->invoke($controller));
    }
}
