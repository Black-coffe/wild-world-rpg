<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GameSettings;

use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S5 (v0.51.187) — unit-тесты для GameSettingsService.
 *
 * Используем анонимные mock-наследники GameSettingsModel чтобы избежать
 * реального соединения с БД (как в ToolDurabilityProcessorTest для S4).
 *
 * @internal
 * @covers \App\Services\GameSettings\GameSettingsService
 */
final class GameSettingsServiceTest extends CIUnitTestCase
{
    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $model = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return null;
            }
        };

        $svc = new GameSettingsService($model);
        $this->assertSame(0.75, $svc->get('nonexistent.key', 0.75));
    }

    public function testGetCoercesIntType(): void
    {
        $model = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'           => 1,
                    'setting_key'  => $key,
                    'value_type'   => 'int',
                    'value_int'    => '42', // приходит как string из БД
                    'hard_min'     => null,
                    'hard_max'     => null,
                ];
            }
        };

        $svc = new GameSettingsService($model);
        $v   = $svc->get('test.int_key', 0);
        $this->assertSame(42, $v);
    }

    public function testGetCoercesFloatType(): void
    {
        $model = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'           => 1,
                    'setting_key'  => $key,
                    'value_type'   => 'float',
                    'value_float'  => '0.50',
                    'hard_min'     => null,
                    'hard_max'     => null,
                ];
            }
        };

        $svc = new GameSettingsService($model);
        $v   = $svc->get('test.float_key', 1.0);
        $this->assertSame(0.50, $v);
    }

    public function testGetCoercesBoolType(): void
    {
        $model = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'           => 1,
                    'setting_key'  => $key,
                    'value_type'   => 'bool',
                    'value_bool'   => 1,
                ];
            }
        };

        $svc = new GameSettingsService($model);
        $this->assertTrue($svc->get('test.bool_key', false));
    }

    public function testSetRejectsValueAboveHardMax(): void
    {
        $updateCalled = false;
        $model = new class($updateCalled) extends GameSettingsModel {
            /** @phpstan-ignore-next-line property.phpDocType */
            public function __construct(private bool &$updateCalled) { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'         => 1,
                    'value_type' => 'float',
                    'hard_min'   => '0.10',
                    'hard_max'   => '0.90',
                ];
            }
            public function update($id = null, $data = null): bool
            {
                $this->updateCalled = true;
                return true;
            }
        };

        $svc = new GameSettingsService($model);
        $ok  = $svc->set('test.key', 0.99); // выше hard_max 0.90
        $this->assertFalse($ok);
        $this->assertFalse($updateCalled, 'update() не должен быть вызван при out-of-bounds');
    }

    public function testSetRejectsValueBelowHardMin(): void
    {
        $model = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'         => 1,
                    'value_type' => 'int',
                    'hard_min'   => '5',
                    'hard_max'   => '100',
                ];
            }
        };

        $svc = new GameSettingsService($model);
        $this->assertFalse($svc->set('test.key', 3));
    }

    public function testSetAcceptsValueInBounds(): void
    {
        $model = new class extends GameSettingsModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'         => 1,
                    'value_type' => 'float',
                    'hard_min'   => '0.10',
                    'hard_max'   => '0.90',
                ];
            }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $svc = new GameSettingsService($model);
        $this->assertTrue($svc->set('test.key', 0.50, 'admin@test'));
        $this->assertSame(0.50, $model->lastUpdate['value_float'] ?? null);
        $this->assertSame('admin@test', $model->lastUpdate['updated_by'] ?? null);
    }

    public function testResetReturnsToDefault(): void
    {
        $model = new class extends GameSettingsModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'                 => 1,
                    'value_type'         => 'float',
                    'value_float'        => 0.20, // current (out-of-recommended)
                    'default_value_text' => '0.50',
                    'hard_min'           => '0.10',
                    'hard_max'           => '0.90',
                ];
            }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $svc = new GameSettingsService($model);
        $ok  = $svc->reset('test.key', 'admin@test');
        $this->assertTrue($ok);
        $this->assertSame(0.50, $model->lastUpdate['value_float'] ?? null);
    }

    public function testSetBoolCoercesStringValues(): void
    {
        // Регресс на баг: admin POST шлёт строку «true»/«false». (bool)"false"=true
        // (непустая строка) ставило бы 1 → отключение killswitch через admin UI не работало.
        $model = new class extends GameSettingsModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return ['id' => 1, 'value_type' => 'bool', 'hard_min' => null, 'hard_max' => null];
            }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $svc = new GameSettingsService($model);

        $svc->set('k', 'true');
        $this->assertSame(1, $model->lastUpdate['value_bool'] ?? null);
        $svc->set('k', 'false'); // ← главный кейс фикса
        $this->assertSame(0, $model->lastUpdate['value_bool'] ?? null);
        $svc->set('k', '1');
        $this->assertSame(1, $model->lastUpdate['value_bool'] ?? null);
        $svc->set('k', '0');
        $this->assertSame(0, $model->lastUpdate['value_bool'] ?? null);
        $svc->set('k', true);
        $this->assertSame(1, $model->lastUpdate['value_bool'] ?? null);
        $svc->set('k', false);
        $this->assertSame(0, $model->lastUpdate['value_bool'] ?? null);
        $svc->set('k', ''); // пустая строка → false
        $this->assertSame(0, $model->lastUpdate['value_bool'] ?? null);
    }

    public function testStringTypeBypassesBoundsCheck(): void
    {
        $model = new class extends GameSettingsModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                return [
                    'id'         => 1,
                    'value_type' => 'string',
                    'hard_min'   => null,
                    'hard_max'   => null,
                ];
            }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $svc = new GameSettingsService($model);
        $this->assertTrue($svc->set('test.str_key', 'hello world'));
        $this->assertSame('hello world', $model->lastUpdate['value_string'] ?? null);
    }
}
