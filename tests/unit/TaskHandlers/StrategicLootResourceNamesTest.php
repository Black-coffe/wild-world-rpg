<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\Objects\StrategicLootHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Story bugs-info-0923-04 — «📦 Добытые ресурсы: — Crops / Fruit / Wood / Clay / Water …
 * нет перевода на старой ферме». `StrategicLootHandler::sendDiscoveryMessage()` проверял
 * строку `ResourceModel` через `is_array()`, а модель возвращает `ResourceEntity` —
 * условие всегда ложно, и в текст находки утекал сырой `name_en`.
 *
 * Схема `resources` строится тестом сам: `CREATE TEMPORARY TABLE` затеняет реальную
 * таблицу (если она есть в `wildworld_tests`) только для этого соединения и не трогает
 * её строки. Отправка в Telegram подменена: наследник перехватывает caption.
 *
 * @internal
 */
final class StrategicLootResourceNamesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('DROP TEMPORARY TABLE IF EXISTS resources');
        $this->db->query(
            'CREATE TEMPORARY TABLE resources ('
            . 'id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NOT NULL'
            . ') DEFAULT CHARSET=utf8mb4'
        );
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS character_resources');
        $this->db->query(
            'CREATE TEMPORARY TABLE character_resources ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, '
            . 'created_at DATETIME NULL, updated_at DATETIME NULL, '
            . 'UNIQUE KEY uq_cr_char_resource (id_characters, id_resources))'
        );
        $this->db->query(
            "INSERT INTO resources (id, name, name_en) VALUES
                (45, 'Зерновые культуры', 'Crops'),
                (48, 'Фрукты', 'Fruit'),
                (2, 'Древесина', 'Wood'),
                (13, 'Глина', 'Clay'),
                (17, 'Вода', 'Water')"
        );
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS character_resources');
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS resources');
        parent::tearDown();
    }

    /**
     * @param array<string, int> $resources
     */
    private function handler(): StrategicLootResourceNamesCapturingHandler
    {
        $handler = (new ReflectionClass(StrategicLootResourceNamesCapturingHandler::class))
            ->newInstanceWithoutConstructor();

        $set = static function (string $prop, object $value) use ($handler): void {
            $p = new ReflectionProperty(StrategicLootHandler::class, $prop);
            $p->setAccessible(true);
            $p->setValue($handler, $value);
        };
        $set('resourceModel', new ResourceModel());
        $set('telegramUserModel', new class () extends TelegramUserModel {
            public function find($id = null)
            {
                return ['telegram_id' => 1];
            }
        });

        return $handler;
    }

    /**
     * @param array<string, int> $resources
     */
    private function discoveryText(array $resources): string
    {
        $handler = $this->handler();

        $m = new ReflectionMethod(StrategicLootHandler::class, 'sendDiscoveryMessage');
        $m->setAccessible(true);
        $m->invoke(
            $handler,
            ['name' => 'Старая ферма', 'name_en' => 'IslandFarm'],
            ['id' => 1, 'telegram_user_id' => 1],
            ['resources' => $resources, 'crafted_items' => []]
        );

        return $handler->caption;
    }

    public function testOldFarmLootUsesRussianResourceNames(): void
    {
        $this->assertInstanceOf(
            \App\Entities\ResourceEntity::class,
            (new ResourceModel())->where('name_en', 'Crops')->first(),
            'тест обязан идти через настоящую сущность, не массив-заглушку'
        );

        $text = $this->discoveryText(['Crops' => 3, 'Fruit' => 2, 'Wood' => 6, 'Clay' => 29, 'Water' => 6]);

        foreach (['Зерновые культуры', 'Фрукты', 'Древесина', 'Глина', 'Вода'] as $ru) {
            $this->assertStringContainsString("*{$ru}*", $text);
        }
        foreach (['Crops', 'Fruit', 'Wood', 'Clay', 'Water'] as $en) {
            $this->assertStringNotContainsString($en, $text, "сырой ключ {$en} утёк в текст находки");
        }
    }

    /**
     * H6.1 (ask 2): ключ без ресурса не выдаётся — и в находке его нет; промах пишет ERROR с объектом и
     * ключом. Раньше `awardContents()` не смотрел на `false` от `addOrIncreaseResource()` и обещал
     * «— *NoSuchResourceXyz*: 4 шт.», не положив ничего.
     */
    public function testUnknownResourceKeyIsNotListedAndLogsError(): void
    {
        $text = $this->lootText(['resources' => ['NoSuchResourceXyz' => 4, 'Wood' => 1]]);

        $this->assertStringNotContainsString('NoSuchResourceXyz', $text, 'невыданный ключ показан игроку');
        $this->assertStringContainsString('— *Древесина*: 1 шт.', $text);
        $this->assertLogContains('error', 'StrategicLootHandler: IslandFarm resources key NoSuchResourceXyz not granted');

        $rows = $this->db->table('character_resources')->where('id_characters', 1)->get()->getResultArray();
        $this->assertCount(1, $rows, 'выдана только древесина');
        $this->assertSame(2, (int) $rows[0]['id_resources']);
        $this->assertSame(1, (int) $rows[0]['quantity']);
    }

    /**
     * Полный путь лута: `awardContents()` выдаёт, `sendDiscoveryMessage()` показывает выданное.
     *
     * @param array<string, mixed> $contents
     */
    private function lootText(array $contents): string
    {
        $handler = $this->handler();
        $object  = ['name' => 'Старая ферма', 'name_en' => 'IslandFarm'];
        $char    = ['id' => 1, 'telegram_user_id' => 1];

        $award = new ReflectionMethod(StrategicLootHandler::class, 'awardContents');
        $award->setAccessible(true);
        $found = $award->invoke($handler, $char, $contents, $object);

        $send = new ReflectionMethod(StrategicLootHandler::class, 'sendDiscoveryMessage');
        $send->setAccessible(true);
        $send->invoke($handler, $object, $char, $found);

        return $handler->caption;
    }
}

/**
 * Перехват Telegram-отправки: caption оседает в свойстве, в сеть ничего не уходит.
 *
 * @internal
 */
final class StrategicLootResourceNamesCapturingHandler extends StrategicLootHandler
{
    public string $caption = '';

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        $this->caption = $caption;
    }
}
