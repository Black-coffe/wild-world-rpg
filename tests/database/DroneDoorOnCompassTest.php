<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\World\MoveSurfaceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Database;
use Config\Services;

/**
 * Story drone-discoverability-07 — покрытие ветки показа кнопки «🚁 Дрон» на
 * компас-экране карты (App\Services\World\MoveSurfaceService::buildDirectionsKeyboard()).
 *
 * Три условия должны совпасть, иначе кнопки нет:
 *  1) killswitch drone.scout.enabled=true (game_settings);
 *  2) у чара есть строка crafted_items_log на DroneScout;
 *  3) quantity > 0.
 *
 * @internal
 */
final class DroneDoorOnCompassTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'crafted_items', 'crafted_items_log', 'characters'];

    protected function setUp(): void
    {
        parent::setUp();
        // Реальный cache-handler проекта — 'file' (Config\Cache::$handler), общий на диске
        // между ВСЕМИ параллельно бегущими процессами PHPUnit на этой машине. cache()->clean()
        // чистит его лишь в момент вызова: сосед-процесс может тут же записать свежую запись
        // 'game_settings_drone_scout_enabled' с TTL 60с (память проекта: «GameSettings кэш 60с»),
        // и результат теста станет зависеть от порядка/параллелизма чужих прогонов — ровно та
        // ловушка, ради которой заведена story. MockCache — in-memory, привязан к ЭТОМУ PHP-
        // процессу, не пишется на диск и не виден другим процессам; свежий экземпляр на каждый
        // testMethod убирает саму возможность межпроцессной утечки (паттерн уже в проекте:
        // tests/unit/Services/Seo/GoogleSearchConsoleServiceTest.php).
        Services::injectMock('cache', new MockCache());

        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, category VARCHAR(32) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_eng VARCHAR(100) NULL, name_rus VARCHAR(100) NULL, type VARCHAR(100) NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, crafted_item_id INT, quantity INT DEFAULT 1)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function setKillswitch(bool $on): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'drone.scout.enabled', 'value_type' => 'bool', 'value_bool' => $on ? 1 : 0,
        ]);
        // Инвалидация в рамках ЭТОГО теста: MockCache живёт только в текущем PHP-процессе
        // (см. setUp()), поэтому clean() здесь безопасен и не задевает соседние процессы.
        $this->cleanCache();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function seedChar(): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['telegram_user_id' => 1]);

        return (int) $db->insertID();
    }

    private function seedDroneItem(): int
    {
        $db = Database::connect('tests');
        $db->table('crafted_items')->insert(['name_eng' => 'DroneScout', 'name_rus' => 'Дрон-разведчик', 'type' => 'drones']);

        return (int) $db->insertID();
    }

    private function seedDroneLog(int $characterId, int $droneItemId, int $quantity): void
    {
        Database::connect('tests')->table('crafted_items_log')->insert([
            'character_id' => $characterId, 'crafted_item_id' => $droneItemId, 'quantity' => $quantity,
        ]);
    }

    /**
     * Наследник-обёртка: buildDirectionsKeyboard() protected, world/island killswitch'и
     * зафиксированы через overridable seam-методы, чтобы тест не зависел от их значений
     * в game_settings и держал ряд «состояние мира» простым для поиска кнопки дрона.
     */
    private function svc(): MoveSurfaceService
    {
        return new class extends MoveSurfaceService {
            protected function finalGridEnabled(): bool
            {
                return false;
            }

            protected function worldHubEnabled(): bool
            {
                return false;
            }

            public function keyboard(array $character): array
            {
                return $this->buildDirectionsKeyboard(false, $character);
            }
        };
    }

    /** @param array<int, array<int, array<string, string>>> $rows */
    private function findDroneButton(array $rows): ?array
    {
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? null) === 'droneScoutList') {
                    return $button;
                }
            }
        }

        return null;
    }

    public function testKillswitchOffNoButtonEvenWithDrone(): void
    {
        $this->setKillswitch(false);
        $cid     = $this->seedChar();
        $droneId = $this->seedDroneItem();
        $this->seedDroneLog($cid, $droneId, 5);

        $rows = $this->svc()->keyboard(['id' => $cid]);
        $this->assertNull($this->findDroneButton($rows));
    }

    public function testKillswitchOnNoDroneOwnedNoButton(): void
    {
        $this->setKillswitch(true);
        $cid = $this->seedChar();
        $this->seedDroneItem(); // предмет существует в каталоге, но лога у чара нет

        $rows = $this->svc()->keyboard(['id' => $cid]);
        $this->assertNull($this->findDroneButton($rows));
    }

    public function testKillswitchOnDroneOwnedButtonPresentWithCorrectCallback(): void
    {
        $this->setKillswitch(true);
        $cid     = $this->seedChar();
        $droneId = $this->seedDroneItem();
        $this->seedDroneLog($cid, $droneId, 3);

        $rows   = $this->svc()->keyboard(['id' => $cid]);
        $button = $this->findDroneButton($rows);
        $this->assertNotNull($button);
        $this->assertSame('droneScoutList', $button['callback_data']);
        $this->assertSame('🚁 Дроны', $button['text']);
    }

    public function testKillswitchOnDroneQuantityZeroNoButton(): void
    {
        $this->setKillswitch(true);
        $cid     = $this->seedChar();
        $droneId = $this->seedDroneItem();
        $this->seedDroneLog($cid, $droneId, 0); // граница: строка лога есть, quantity=0

        $rows = $this->svc()->keyboard(['id' => $cid]);
        $this->assertNull($this->findDroneButton($rows));
    }
}
