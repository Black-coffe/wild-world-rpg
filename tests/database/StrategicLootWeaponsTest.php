<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\TelegramUserModel;
use App\TaskHandlers\Objects\StrategicLootHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use ReflectionMethod;
use ReflectionProperty;

/**
 * H6.1 (ask 3) — раздел `weapons` в `world_objects.contents` стратегического объекта: Бункер выдаёт
 * Трубчатый пистолет и Металлическое копьё как оружие (`characters_weapons`, полная прочность, не
 * экипировано) тем же upsert'ом, что PvE-лут, и показывает их русским именем с количеством.
 * Раньше раздела не было: оружие лежало в `crafted_items`, где такого предмета нет, и молча пропадало.
 *
 * Схема строится тестом: `CREATE TEMPORARY TABLE` затеняет реальные таблицы только для этого соединения.
 *
 * @internal
 */
final class StrategicLootWeaponsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAR_ID = 77;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTemp();
        $this->db->query(
            'CREATE TEMPORARY TABLE weapons (id INT PRIMARY KEY, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) NULL, '
            . 'rarity VARCHAR(50) NULL, durability INT DEFAULT 100) DEFAULT CHARSET=utf8mb4'
        );
        $this->db->query(
            'CREATE TEMPORARY TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, '
            . 'weapon_id INT, quantity INT DEFAULT 1, current_durability INT DEFAULT 0, equipped TINYINT DEFAULT 0, '
            . 'slot VARCHAR(50) NULL)'
        );
        $this->db->query(
            'CREATE TEMPORARY TABLE crafted_items (id INT PRIMARY KEY, name_rus VARCHAR(255) NOT NULL, '
            . 'name_eng VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4'
        );
        $this->db->query(
            'CREATE TEMPORARY TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, '
            . 'crafted_item_id INT, quantity INT DEFAULT 0, task_id INT NULL)'
        );
        $this->db->query("INSERT INTO crafted_items (id, name_rus, name_eng) VALUES (2, 'Аптечка базовая', 'FirstAidKit')");
        $this->db->query(
            "INSERT INTO weapons (id, name, name_en, rarity, durability) VALUES
                (3, 'Трубчатый пистолет', 'PipeGun', 'Common', 60),
                (1, 'Металлическое копьё', 'MetalSpear', 'Common', 90)"
        );
    }

    protected function tearDown(): void
    {
        $this->dropTemp();
        parent::tearDown();
    }

    private function dropTemp(): void
    {
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS crafted_items_log');
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS crafted_items');
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS characters_weapons');
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS weapons');
    }

    /**
     * @param array<string, mixed> $contents
     */
    private function lootText(array $contents): string
    {
        $handler = new StrategicLootWeaponsCapturingHandler();
        $p = new ReflectionProperty(StrategicLootHandler::class, 'telegramUserModel');
        $p->setAccessible(true);
        $p->setValue($handler, new class () extends TelegramUserModel {
            public function find($id = null)
            {
                return ['telegram_id' => 1];
            }
        });

        $object = ['name' => 'Бункер', 'name_en' => 'Bunker'];
        $char   = ['id' => self::CHAR_ID, 'telegram_user_id' => 1];

        $award = new ReflectionMethod(StrategicLootHandler::class, 'awardContents');
        $award->setAccessible(true);
        $found = $award->invoke($handler, $char, $contents, $object);

        $send = new ReflectionMethod(StrategicLootHandler::class, 'sendDiscoveryMessage');
        $send->setAccessible(true);
        $send->invoke($handler, $object, $char, $found);

        return $handler->caption;
    }

    /**
     * @return array<string, mixed>
     */
    private function weaponRow(int $weaponId): array
    {
        $row = $this->db->table('characters_weapons')
            ->where('character_id', self::CHAR_ID)
            ->where('weapon_id', $weaponId)
            ->get()
            ->getRowArray();
        $this->assertIsArray($row, "оружие #{$weaponId} не выдано");

        return $row;
    }

    public function testBunkerWeaponsAreGrantedAndListedByRussianName(): void
    {
        $text = $this->lootText(['weapons' => ['PipeGun' => 2, 'MetalSpear' => 3]]);

        $pipe = $this->weaponRow(3);
        $qPipe = (int) $pipe['quantity'];
        $this->assertGreaterThanOrEqual(1, $qPipe);
        $this->assertLessThanOrEqual(2, $qPipe);
        $this->assertSame(60, (int) $pipe['current_durability'], 'полная прочность из определения');
        $this->assertSame(0, (int) $pipe['equipped'], 'не экипировано');

        $spear = $this->weaponRow(1);
        $qSpear = (int) $spear['quantity'];
        $this->assertGreaterThanOrEqual(1, $qSpear);
        $this->assertLessThanOrEqual(3, $qSpear);
        $this->assertSame(90, (int) $spear['current_durability']);
        $this->assertSame(0, (int) $spear['equipped']);

        $this->assertStringContainsString("— *Трубчатый пистолет*: {$qPipe} шт.", $text);
        $this->assertStringContainsString("— *Металлическое копьё*: {$qSpear} шт.", $text);
        $this->assertStringNotContainsString('PipeGun', $text);
        $this->assertStringNotContainsString('MetalSpear', $text);
        $this->assertStringNotContainsString('ничего ценного', $text);
    }

    public function testRepeatLootStacksWeaponQuantity(): void
    {
        $this->lootText(['weapons' => ['PipeGun' => 1]]);
        $this->lootText(['weapons' => ['PipeGun' => 1]]);

        $this->assertSame(2, (int) $this->weaponRow(3)['quantity'], 'upsert, не дубль-строка');
        $this->assertSame(1, $this->db->table('characters_weapons')->countAllResults());
    }

    public function testUnknownWeaponKeyIsNotListedAndLogsError(): void
    {
        $text = $this->lootText(['weapons' => ['NoSuchGunXyz' => 2, 'PipeGun' => 1]]);

        $this->assertStringNotContainsString('NoSuchGunXyz', $text);
        $this->assertStringContainsString('— *Трубчатый пистолет*: 1 шт.', $text);
        $this->assertLogContains('error', 'StrategicLootHandler: Bunker weapons key NoSuchGunXyz not granted');
        $this->assertSame(1, $this->db->table('characters_weapons')->countAllResults());
    }

    /** Ask 2 для предметов: `BasicMedKit` (такого предмета нет) молча пропадал — теперь ERROR. */
    public function testUnknownCraftedKeyIsNotListedAndLogsError(): void
    {
        $text = $this->lootText(['crafted_items' => ['BasicMedKit' => 5, 'FirstAidKit' => 1]]);

        $this->assertStringNotContainsString('BasicMedKit', $text);
        $this->assertStringContainsString('— *Аптечка базовая*: 1 шт.', $text);
        $this->assertLogContains('error', 'StrategicLootHandler: Bunker crafted_items key BasicMedKit not granted');
        $this->assertSame(1, $this->db->table('crafted_items_log')->countAllResults());
    }
}

/**
 * Перехват Telegram-отправки: caption оседает в свойстве, в сеть ничего не уходит.
 *
 * @internal
 */
final class StrategicLootWeaponsCapturingHandler extends StrategicLootHandler
{
    public string $caption = '';

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        $this->caption = $caption;
    }
}
