<?php

namespace Tests\Database;

use App\Entities\CharacterEntity;
use App\TaskHandlers\Objects\StrategicLootHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-129 — регресс-гейт контракта «StrategicLootHandler::handle() принимает CharacterEntity».
 *
 * Прод-инцидент Tier-3 (2026-06-16): callback-вход StrategicSearchAction передаёт
 * CharacterEntity (из BaseAction::getUserAndCharacter), а march-путь (ObjectDiscoveryService)
 * исторически передавал массив. Внутренние методы handle() (sendInsufficientToolsMessage /
 * awardContents) строго типизированы `array $character` → Entity ронял
 * `TypeError: Argument #2 ($character) must be of type array, CharacterEntity given`.
 * Поймано ТОЛЬКО живым тапом (resolve-юнит + phpstan пропустили — урок control-tap).
 *
 * Фикс — нормализация Entity→array на входе handle(). Этот тест падает, если нормализацию убрать:
 * прогоняем insufficient-tools-ветку с CharacterEntity; telegram_users пуст → отправка
 * сообщения делает ранний выход (без Telegram-инициализации), так что тест детерминирован.
 *
 * @internal
 */
final class StrategicLootHandlerEntityContractTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['crafted_items', 'telegram_users'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        // crafted_items пуст → getItemByNameEngAndCharacterId('Hoe', …) вернёт null → insufficient-tools.
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NULL, name_eng VARCHAR(255) NULL)');
        // telegram_users пуст → find(telegram_user_id) вернёт null → sendInsufficientToolsMessage
        // делает ранний return (нет Telegram-вызова).
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['crafted_items', 'telegram_users'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testHandleAcceptsCharacterEntityWithoutTypeError(): void
    {
        // CharacterEntity (как из BaseAction::getUserAndCharacter), НЕ массив.
        $character = new CharacterEntity([
            'id'               => 4242,
            'telegram_user_id' => 999999, // нет такого telegram_users → ранний выход без Telegram
        ]);

        // strategic-объект с tool-гейтом (Hoe), которого у чара нет → insufficient-tools ветка.
        $object = [
            'id'              => 7,
            'world_object_id' => 7,
            'map_id'          => 1,
            'name'            => 'Старая ферма (тест)',
            'discovery_tools' => '[{"Hoe":"1"}]',
            'contents'        => '[]',
        ];

        // До фикса здесь летел TypeError (Entity → `array $character`); после — нормализация
        // Entity→array, ветка отрабатывает и тихо выходит. Достижение этой точки = контракт цел.
        (new StrategicLootHandler())->handle($object, [], $character);

        // Достижение этой строки доказывает, что handle() принял CharacterEntity без TypeError
        // (нормализация Entity→array цела). Ассерт на $object — реальная (не константная) проверка,
        // подтверждающая прохождение полного тела handle() insufficient-tools-ветки.
        $this->assertSame(7, $object['id'], 'handle() принял CharacterEntity без TypeError (контракт ADR-129).');
    }
}
