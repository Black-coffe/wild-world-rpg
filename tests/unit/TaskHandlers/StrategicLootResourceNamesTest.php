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
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS resources');
        parent::tearDown();
    }

    /**
     * @param array<string, int> $resources
     */
    private function discoveryText(array $resources): string
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

    public function testUnknownResourceKeyStaysRawWithoutFatal(): void
    {
        $text = $this->discoveryText(['NoSuchResourceXyz' => 4, 'Wood' => 1]);

        $this->assertStringContainsString('— *NoSuchResourceXyz*: 4 шт.', $text);
        $this->assertStringContainsString('— *Древесина*: 1 шт.', $text);
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
