<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect;
use App\Controllers\Telegram\Commands\SystemCommands\GenericmessageCommand;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Telegram;

/**
 * chat-requests-batch-10 — «Своё число» на экране количества готовки.
 *
 * Образец — `SellResourceAction::promptCustomQuantity()` (кнопка/промпт) +
 * `GenericmessageCommand::execute()` (перехват маркера, `handleTradeReply()`
 * как образец приватного хендлера). Тесты бьют по ОБЕИМ сторонам маркера:
 * (A) `CampfireCookingSelect` реально шлёт промпт с маркером `COOK:<Key>`;
 * (B) `GenericmessageCommand` реально ловит этот маркер на ответе игрока и
 * доводит до `GenericCraftActionStart` — не скан исходника, а сквозной
 * прогон текста промпта из (A) через диспетчер (B).
 *
 * Гейт-путь (ADR-167, insufficient-input) в этом наборе — ТЕКСТОВЫЙ
 * (`sendError()`/`Request::sendMessage`), поэтому прогоняется целиком, без
 * ограничения photo-экранов из story 04 (`Request::encodeFile(base_url(...))`
 * реально ходит в сеть под `app.baseURL=http://example.com/` из
 * `phpunit.xml.dist` — то же ограничение среды, что и там, здесь оно просто
 * не задевает эти ветки: `handlePromptCustomQuantity()` и текстовые отказы
 * `GenericCraftActionStart` шлют `sendMessage`, не фото).
 *
 * @internal
 */
final class CampfireCustomQuantityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    /** @var list<string> таблицы, созданные ЭТИМ тестом — только их и дропаем. */
    private array $createdTables = [];

    private int $telegramUserId  = 0;
    private int $characterId     = 0;
    private int $cookTaskId      = 0;
    private int $blockingTaskId  = 0;
    private int $blockingCharTaskId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Фейковая инициализация Request — без неё Request::send() падает на
        // null Telegram-инстансе (паттерн story 04 / VehicleCraftWiringTest).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->conn = Database::connect('tests');

        $this->createTableIfMissing('telegram_users', '
            CREATE TABLE telegram_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL
            )
        ');
        $this->createTableIfMissing('characters', '
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                level INT NOT NULL DEFAULT 1
            )
        ');
        // `tasks`/`character_tasks` обычно уже есть в тестовой БД (используем чужую
        // схему — только строки), но соседние тесты (напр. `VehicleCraftWiringTest`)
        // временно DROP+CREATE `tasks` под СВОЮ изолированную схему и не всегда
        // восстанавливают исходную до своего tearDown — при прогоне суммарного
        // набора `tasks` может не существовать именно в момент запуска ЭТОГО теста.
        // Гвард спасает воспроизводимость независимо от порядка/параллельности.
        $this->createTableIfMissing('tasks', '
            CREATE TABLE tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                handler_key VARCHAR(100) NULL,
                name_rus VARCHAR(150) NULL,
                description TEXT NULL,
                min_duration INT NULL,
                max_duration INT NULL,
                type VARCHAR(50) NULL,
                difficulty_level INT NULL,
                execution_limit INT NULL,
                parallel_execution_allowed TINYINT NULL,
                interruptible TINYINT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('character_tasks', '
            CREATE TABLE character_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                telegram_user_id INT NOT NULL,
                task_id INT NOT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status VARCHAR(20) NOT NULL,
                task_settings TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $this->conn->table('telegram_users')->insert(['telegram_id' => 555010]);
        $this->telegramUserId = (int) $this->conn->insertID();

        $this->conn->table('characters')->insert(['telegram_user_id' => $this->telegramUserId]);
        $this->characterId = (int) $this->conn->insertID();

        $this->conn->table('tasks')->insert([
            'name'                       => 'craftMushroomSoup',
            'name_rus'                   => 'Готовка Грибной похлёбки',
            'parallel_execution_allowed' => 0,
        ]);
        $this->cookTaskId = (int) $this->conn->insertID();

        $this->conn->table('tasks')->insert([
            'name'                       => 'gatherTestBlocking',
            'name_rus'                   => 'Добыча (тестовая блокирующая)',
            'parallel_execution_allowed' => 0,
        ]);
        $this->blockingTaskId = (int) $this->conn->insertID();
    }

    protected function tearDown(): void
    {
        if ($this->blockingCharTaskId > 0) {
            $this->conn->table('character_tasks')->where('id', $this->blockingCharTaskId)->delete();
        }
        $this->conn->table('tasks')->where('id', $this->cookTaskId)->delete();
        $this->conn->table('tasks')->where('id', $this->blockingTaskId)->delete();
        $this->conn->table('characters')->where('id', $this->characterId)->delete();
        $this->conn->table('telegram_users')->where('id', $this->telegramUserId)->delete();

        foreach (array_reverse($this->createdTables) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        parent::tearDown();
    }

    private function createTableIfMissing(string $table, string $ddl): void
    {
        if (! in_array($table, $this->conn->listTables(), true)) {
            $this->conn->query($ddl);
            $this->createdTables[] = $table;
        }
    }

    // ── (A) Кнопка/промпт: костёр реально шлёт форс-реплай с маркером ──

    public function testCampfireSendsForceReplyPromptWithRoutableMarker(): void
    {
        $response = (new CampfireCookingSelect($this->callbackQuery('cook_qtyCustom_MushroomSoup')))->handle();

        $this->assertTrue($response->isOk());
        $text = $this->responseText($response);

        $this->assertStringContainsString('COOK:MushroomSoup', $text, 'промпт обязан нести маркер, который ловит GenericmessageCommand');

        $markup = $this->responseReplyMarkup($response);
        $this->assertTrue((bool) ($markup['force_reply'] ?? false), 'промпт обязан быть ForceReply');
    }

    /**
     * Неизвестное/устаревшее блюдо не должно порождать промпт с маркером —
     * та же проверка (`resolveMenuRecipe()`/`isKnownCookingRecipe()`), которой
     * `GenericmessageCommand` доверяет на обратной стороне (см. ниже).
     * Список блюд (`handleDishList()`, фолбэк для неизвестного ключа) —
     * photo-экран, вне зоны действия end-to-end тестов этого набора (то же
     * ограничение среды, что в story 04: `Request::encodeFile(base_url(...))`
     * реально ходит в сеть под тестовым `app.baseURL`), поэтому проверяется
     * чистой функцией, а не через `handle()`.
     */
    public function testUnknownDishIsNotAKnownCookingRecipe(): void
    {
        $this->assertFalse(CampfireCookingSelect::isKnownCookingRecipe('NotARealDish'));
        $this->assertTrue(CampfireCookingSelect::isKnownCookingRecipe('MushroomSoup'));
    }

    // ── (B) Диспетчер: GenericmessageCommand реально ловит маркер из (A) ──

    /**
     * Сквозной прогон: маркер из промпта `CampfireCookingSelect` (A) кормится
     * в `GenericmessageCommand::execute()` (B) как `reply_to_message.text`.
     * До фикса (без case `COOK:` в `execute()`) это уходило бы в fallback
     * «Не понял команду» — реальная проверка, не совпадение по регэкспу.
     */
    public function testGarbageReplyDoesNotStartCraftAndAnswersUnderstandably(): void
    {
        $promptText = $this->campfirePromptText('MushroomSoup');

        foreach (['три', '', '0', '-5', '5 шт', '3.5'] as $garbage) {
            $before   = (int) $this->conn->table('character_tasks')->countAllResults();
            $response = $this->dispatchReply($promptText, $garbage);

            $this->assertTrue($response->isOk(), "мусорный ввод «{$garbage}» не должен валить обработчик исключением");
            $text = $this->responseText($response);
            $this->assertNotSame('', $text, "мусорный ввод «{$garbage}» не должен молчать");
            $this->assertStringContainsString('❌', $text, "мусорный ввод «{$garbage}» обязан получить понятный отказ");

            $after = (int) $this->conn->table('character_tasks')->countAllResults();
            $this->assertSame($before, $after, "мусорный ввод «{$garbage}» не должен создавать задачу");
        }
    }

    public function testUnknownRecipeMarkerIsRejectedGracefully(): void
    {
        $response = $this->dispatchReply('код заявки: COOK:NotARealDish', '5');

        $this->assertTrue($response->isOk());
        $this->assertStringContainsString('больше не готовят', $this->responseText($response));
    }

    /**
     * ADR-167: 🔒 не стартует поверх 🔒 — и на введённом руками числе тоже.
     * Персонаж занят посторонней 🔒-задачей → валидное «своё число» обязано
     * получить отказ (тем же текстом, что и кнопочный путь), а НЕ создать
     * вторую задачу параллельно.
     */
    public function testValidCustomQuantityRespectsExclusiveLockGate(): void
    {
        $this->conn->query(sprintf(
            "INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, created_at, updated_at)
             VALUES (%d, %d, %d, NOW(), NOW() + INTERVAL 30 MINUTE, 'in_work', NOW(), NOW())",
            $this->characterId,
            $this->telegramUserId,
            $this->blockingTaskId,
        ));
        $this->blockingCharTaskId = (int) $this->conn->insertID();

        $before   = (int) $this->conn->table('character_tasks')->countAllResults();
        $response = $this->dispatchReply($this->campfirePromptText('MushroomSoup'), '5');
        $after    = (int) $this->conn->table('character_tasks')->countAllResults();

        $this->assertTrue($response->isOk());
        $text = $this->responseText($response);
        $this->assertStringContainsString('🔒', $text, 'ответ обязан объяснить занятость тем же гейтом ADR-167');
        $this->assertStringContainsString('Добыча (тестовая блокирующая)', $text);
        $this->assertSame($before, $after, 'введённое руками число не должно обходить 🔒-гейт и создавать вторую задачу');
    }

    // ── Ступени количества гейтятся ресурсами — та же формула, что у обычного крафта ──

    /**
     * `$q <= $maxCraftableItems` — тот же критерий, что
     * `LumberjackAxeCraft1Action::getAvailableQuantityButtons()`. Грибы×4/Вода×2
     * на 1 шт.: при 24 Грибах / 20 Воде — максимум 6 шт. (лимитирует Грибы),
     * значит доступны только ступени 1 и 5 из [1,5,10,25,50,100].
     */
    public function testAffordableStepsMatchRegularCraftGatingFormula(): void
    {
        $required = ['Грибы' => 4, 'Вода' => 2];

        $this->assertSame(
            [1, 5],
            CampfireCookingSelect::affordableSteps(['Грибы' => 24, 'Вода' => 20], $required),
        );
        $this->assertSame(
            CampfireCookingSelect::QUANTITY_STEPS,
            CampfireCookingSelect::affordableSteps(['Грибы' => 1000, 'Вода' => 1000], $required),
            'обильных ресурсов достаточно на все стандартные ступени',
        );
        $this->assertSame(
            [],
            CampfireCookingSelect::affordableSteps(['Грибы' => 0, 'Вода' => 0], $required),
            'нулевые ресурсы не должны давать НИ ОДНОЙ ступени',
        );
    }

    /** Реальный экран количества гейтит кнопки этой же функцией — не дублирует формулу. */
    public function testQuantityButtonsAcceptPreFilteredStepsFromAffordableSteps(): void
    {
        $steps   = CampfireCookingSelect::affordableSteps(['Грибы' => 24, 'Вода' => 20], ['Грибы' => 4, 'Вода' => 2]);
        $buttons = CampfireCookingSelect::quantityButtons('MushroomSoup', $steps);

        $this->assertSame(
            ['genericCraft_MushroomSoup_1', 'genericCraft_MushroomSoup_5'],
            array_column($buttons, 'callback_data'),
        );
    }

    // ── Хелперы ──

    /** Реальный текст промпта, который шлёт `CampfireCookingSelect` (переиспользуется в (B)). */
    private function campfirePromptText(string $recipeKey): string
    {
        $response = (new CampfireCookingSelect($this->callbackQuery("cook_qtyCustom_{$recipeKey}")))->handle();

        return $this->responseText($response);
    }

    /** Гоняет `promptText` → ответ игрока через РЕАЛЬНЫЙ `GenericmessageCommand::execute()`. */
    private function dispatchReply(string $promptText, string $rawReply): ServerResponse
    {
        $telegram = new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');
        $update   = new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message'   => [
                'message_id' => 2,
                'date'       => time(),
                'chat'       => ['id' => 555010, 'type' => 'private'],
                'from'       => ['id' => 555010, 'is_bot' => false, 'first_name' => 'Тест'],
                'text'       => $rawReply,
                'reply_to_message' => [
                    'message_id' => 1,
                    'date'       => time(),
                    'chat'       => ['id' => 555010, 'type' => 'private'],
                    'text'       => $promptText,
                ],
            ],
        ], 'test_bot');

        return (new GenericmessageCommand($telegram, $update))->execute();
    }

    private function callbackQuery(string $data): CallbackQuery
    {
        $tgId = 555010;

        return new CallbackQuery([
            'id'   => 'cbq_1',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1,
                'date'       => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_1',
            'data'          => $data,
        ]);
    }

    private function responseText(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result) || ! method_exists($result, 'getText')) {
            return '';
        }

        return (string) ($result->getText() ?? '');
    }

    /**
     * Читает `reply_markup` НАПРЯМУЮ из `raw_data` (`__get`), а не через
     * `getReplyMarkup()`: у Entity нет `__isset`, а сам геттер по имени поля
     * пытается резолвить его в sub-Entity (`Keyboard`), которая ждёт массив,
     * а `Request::send()` кладёт JSON-строку как есть (см. `KeyboardNormalizer`).
     *
     * @return array<string,mixed>
     */
    private function responseReplyMarkup(ServerResponse $response): array
    {
        $result = $response->getResult();
        $raw    = is_object($result) ? $result->reply_markup : null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
