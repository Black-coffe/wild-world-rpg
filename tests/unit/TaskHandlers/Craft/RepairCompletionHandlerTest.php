<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers\Craft;

use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\GameSettingsModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\Craft\RepairCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionProperty;

/**
 * S5b (v0.51.188) — RepairCompletionHandler.
 *
 * Использует анонимные mock-наследники моделей чтобы не открывать DB
 * connection (pattern из ToolDurabilityProcessorTest S4 +
 * GameSettingsServiceTest S5a).
 *
 * @internal
 * @covers \App\TaskHandlers\Craft\RepairCompletionHandler
 */
final class RepairCompletionHandlerTest extends CIUnitTestCase
{
    public function testRestoresDurabilityToTemplate100Percent(): void
    {
        $log = new class extends CraftedItemsLogModel {
            public array $lastUpdate = [];
            public ?int  $lastUpdateId = null;
            public function __construct() { }
            public function find($id = null)
            {
                return [
                    'id'               => 42,
                    'crafted_item_id'  => 18,
                    'durability_count' => 5,
                ];
            }
            public function update($id = null, $data = null): bool
            {
                $this->lastUpdateId = is_numeric($id) ? (int) $id : null;
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $items = new class extends CraftedItemsModel {
            public function __construct() { }
            public function find($id = null)
            {
                return [
                    'id'               => 18,
                    'name_rus'         => 'Топор дровосека',
                    'durability_count' => 30,
                ];
            }
        };

        $taskModel = new class extends CharacterTaskModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $tgUserModel = new class extends TelegramUserModel {
            public function __construct() { }
            public function find($id = null)
            {
                return null; // skip notification path
            }
        };

        $settingsModel = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                if ($key === 'repair.restore_durability_to_percent') {
                    return [
                        'id'         => 1,
                        'value_type' => 'int',
                        'value_int'  => 100,
                    ];
                }
                return null;
            }
        };

        $handler = $this->makeHandler($log, $items, $taskModel, $tgUserModel, $settingsModel);

        $handler->handle([
            'id'               => 999,
            'task_settings'    => '{"tool_log_id":42,"recipe":"LumberjackAxe"}',
            'status'           => 'in_work',
            'telegram_user_id' => 0,
        ]);

        $this->assertSame(42, $log->lastUpdateId);
        $this->assertSame(30, $log->lastUpdate['durability_count'] ?? null, '100% restore = template 30');
        $this->assertSame('completed', $taskModel->lastUpdate['status'] ?? null);
    }

    public function testRestoresDurabilityWith80Percent(): void
    {
        $log = new class extends CraftedItemsLogModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function find($id = null)
            {
                return ['id' => 42, 'crafted_item_id' => 18, 'durability_count' => 5];
            }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $items = new class extends CraftedItemsModel {
            public function __construct() { }
            public function find($id = null)
            {
                return ['id' => 18, 'name_rus' => 'Топор', 'durability_count' => 30];
            }
        };

        $taskModel = new class extends CharacterTaskModel {
            public function __construct() { }
            public function update($id = null, $data = null): bool { return true; }
        };

        $tgUserModel = new class extends TelegramUserModel {
            public function __construct() { }
            public function find($id = null) { return null; }
        };

        $settingsModel = new class extends GameSettingsModel {
            public function __construct() { }
            public function findByKey(string $key): ?array
            {
                if ($key === 'repair.restore_durability_to_percent') {
                    return ['id' => 1, 'value_type' => 'int', 'value_int' => 80];
                }
                return null;
            }
        };

        $handler = $this->makeHandler($log, $items, $taskModel, $tgUserModel, $settingsModel);

        $handler->handle([
            'id'               => 999,
            'task_settings'    => '{"tool_log_id":42}',
            'status'           => 'in_work',
            'telegram_user_id' => 0,
        ]);

        // 30 × 80 / 100 = 24
        $this->assertSame(24, $log->lastUpdate['durability_count'] ?? null);
    }

    public function testIdempotentSkipsCompleted(): void
    {
        $log = new class extends CraftedItemsLogModel {
            public bool $updateCalled = false;
            public function __construct() { }
            public function find($id = null) { return null; }
            public function update($id = null, $data = null): bool
            {
                $this->updateCalled = true;
                return true;
            }
        };

        $handler = $this->makeHandler(
            $log,
            new class extends CraftedItemsModel { public function __construct() { } },
            new class extends CharacterTaskModel {
                public bool $updateCalled = false;
                public function __construct() { }
                public function update($id = null, $data = null): bool
                {
                    $this->updateCalled = true;
                    return true;
                }
            },
            new class extends TelegramUserModel { public function __construct() { } public function find($id = null) { return null; } },
            new class extends GameSettingsModel { public function __construct() { } },
        );

        $handler->handle([
            'id'            => 999,
            'status'        => 'completed', // уже выполнен
            'task_settings' => '{"tool_log_id":42}',
        ]);

        $this->assertFalse($log->updateCalled, 'Idempotency: completed task — no update');
    }

    public function testGracefulOnMissingLog(): void
    {
        $log = new class extends CraftedItemsLogModel {
            public function __construct() { }
            public function find($id = null) { return null; } // лог удалён
            public function update($id = null, $data = null): bool { return true; }
        };

        $taskModel = new class extends CharacterTaskModel {
            public array $lastUpdate = [];
            public function __construct() { }
            public function update($id = null, $data = null): bool
            {
                if (is_array($data)) {
                    $this->lastUpdate = $data;
                }
                return true;
            }
        };

        $handler = $this->makeHandler(
            $log,
            new class extends CraftedItemsModel { public function __construct() { } },
            $taskModel,
            new class extends TelegramUserModel { public function __construct() { } public function find($id = null) { return null; } },
            new class extends GameSettingsModel { public function __construct() { } },
        );

        // Не должно бросить exception; должно завершить task как completed.
        $handler->handle([
            'id'            => 999,
            'status'        => 'in_work',
            'task_settings' => '{"tool_log_id":42}',
        ]);

        $this->assertSame('completed', $taskModel->lastUpdate['status'] ?? null);
    }

    private function makeHandler(
        CraftedItemsLogModel $log,
        CraftedItemsModel $items,
        CharacterTaskModel $taskModel,
        TelegramUserModel $tgUserModel,
        GameSettingsModel $settingsModel,
    ): RepairCompletionHandler {
        $handler = new RepairCompletionHandler();
        $this->setPrivate($handler, 'logModel', $log);
        $this->setPrivate($handler, 'itemsModel', $items);
        $this->setPrivate($handler, 'characterTaskModel', $taskModel);
        $this->setPrivate($handler, 'telegramUserModel', $tgUserModel);
        $this->setPrivate($handler, 'settings', new GameSettingsService($settingsModel));
        return $handler;
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $rp = new ReflectionProperty($obj, $prop);
        $rp->setAccessible(true);
        $rp->setValue($obj, $value);
    }
}
