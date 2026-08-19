<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageListAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CallbackRoutes;
use Config\Database;
use ReflectionClass;

/**
 * Story storage-craft-insurance-11 — гейт выборочной выдачи со склада базы
 * (storage-craft-insurance-03) держит ПОВЕДЕНИЕ, а не наличие подстрок в
 * исходнике. Прежняя версия файла читала `file_get_contents()` на класс и
 * искала строки вроде `'MAX_BUTTONS = 18'` — сломай `retrieveOne()` целиком,
 * все пять таких тестов остались бы зелёными.
 *
 * Три реальных дефекта, которые эти тесты обязаны ловить:
 *  1. Исход транзакции забора не проверялся — при откате игрок читал
 *     «Забрано», а ресурс физически оставался на складе.
 *  2. Имя ресурса подставлялось в разметку без экранирования — пустое имя
 *     давало `**`, что рвёт legacy-Markdown → 400 → тихий не-сенд ПОСЛЕ
 *     того, как ресурс уже переехал со склада в рюкзак.
 *  3. «…и ещё N видов» считало по одному списку (срез до MAX_BUTTONS), а
 *     кнопки строились из другого (тот же срез, но отфильтрованный) — число
 *     расходилось с фактическим числом кнопок.
 *
 * `retrieveOne()` вызывает Telegram (`Request::sendMessage`) — реальный
 * сетевой вызов в юнит-тесте недопустим (см. остальные Action-тесты в
 * проекте: ни один не зовёт `handle()` целиком). Поэтому логика забора
 * вынесена в приватные методы без Telegram-обвязки —
 * `performRetrieveOne()` / `formatRetrieveMessage()` / `buildResourceButtonRows()` —
 * и тестируется напрямую через рефлексию на реальных SQLite-таблицах
 * (`tests`-соединение, паттерн `GreenhouseProductionWaterTest`). Именно в
 * этих методах живёт вся «мякоть» бага: сломать `retrieveOne()` целиком
 * означает сломать их, а тонкая Telegram-обвязка вокруг (`isOnBase`-гейт,
 * финальный `Request::sendMessage`) — не то, что можно проверить юнит-тестом
 * без сети (Tier-3 smoke для неё уже есть в проектной практике).
 *
 * @internal
 */
final class BaseStorageRetrieveTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['resources', 'character_resources', 'base_storage'];

    protected function setUp(): void
    {
        parent::setUp();
        ResourceIconHelper::reset();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191), icon_text VARCHAR(16))');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE base_storage (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, resource_id INT, quantity INT DEFAULT 0, arrived_from_cell INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        ResourceIconHelper::reset();
        parent::tearDown();
    }

    private function seedResource(int $id, string $name, string $icon = '💧'): void
    {
        Database::connect('tests')->table('resources')->insert(['id' => $id, 'name' => $name, 'icon_text' => $icon]);
    }

    private function seedStorage(int $characterId, int $resourceId, int $qty): void
    {
        Database::connect('tests')->table('base_storage')->insert([
            'character_id' => $characterId,
            'resource_id'  => $resourceId,
            'quantity'     => $qty,
        ]);
    }

    private function storageQty(int $characterId, int $resourceId): int
    {
        $row = Database::connect('tests')->table('base_storage')
            ->where('character_id', $characterId)->where('resource_id', $resourceId)->get()->getRowArray();
        return $row ? (int) $row['quantity'] : 0;
    }

    private function backpackQty(int $characterId, int $resourceId): int
    {
        $row = Database::connect('tests')->table('character_resources')
            ->where('id_characters', $characterId)->where('id_resources', $resourceId)->get()->getRowArray();
        return $row ? (int) $row['quantity'] : 0;
    }

    /** Реальный экземпляр action без Telegram-конструктора — доступ к приватным методам через рефлексию. */
    private function action(?CharacterResourceModel $resourceModel = null): BaseStorageListAction
    {
        $ref = new ReflectionClass(BaseStorageListAction::class);
        /** @var BaseStorageListAction $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $storageProp = $ref->getProperty('storageModel');
        $storageProp->setAccessible(true);
        $storageProp->setValue($instance, new BaseStorageModel());

        $resourceProp = $ref->getProperty('resourceModel');
        $resourceProp->setAccessible(true);
        $resourceProp->setValue($instance, $resourceModel ?? new CharacterResourceModel());

        return $instance;
    }

    /** @param array<int,mixed> $args @return mixed */
    private function callPrivate(object $instance, string $method, array $args)
    {
        $ref = new ReflectionClass($instance);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($instance, ...$args);
    }

    // ── Роутинг: все формы callback'а обязаны попасть в один и тот же action ──

    public function testRetrieveCallbacksResolveToTheAction(): void
    {
        $routes = new CallbackRoutes();

        foreach ([
            'baseStorageList',
            'baseStorageList_all',
            'baseStorageList_res_42_recent',
            'baseStorageList_sort_name',
        ] as $callbackData) {
            $action = explode('_', $callbackData)[0];

            $this->assertSame(
                BaseStorageListAction::class,
                $routes->resolve($action),
                "callback_data '{$callbackData}' не резолвится в экран склада — кнопка будет мёртвой."
            );
        }
    }

    // ── Дефект 1: исход транзакции ──────────────────────────────────────────

    public function testPerformRetrieveOneMovesResourceFromStorageToBackpack(): void
    {
        $this->seedResource(1, 'Вода');
        $this->seedStorage(7, 1, 50);

        $outcome = $this->callPrivate($this->action(), 'performRetrieveOne', [7, 1]);

        $this->assertNotNull($outcome, 'успешная транзакция не должна отдавать null');
        $this->assertSame(50, $outcome['withdrawn']);
        $this->assertSame(0, $this->storageQty(7, 1), 'ресурс должен физически уйти со склада');
        $this->assertSame(50, $this->backpackQty(7, 1), 'и физически появиться в рюкзаке');
    }

    /**
     * Сердце дефекта 1. Раньше `$withdrawn` (посчитанный ДО завершения транзакции)
     * использовался как есть — сорвавшаяся `increaseResources()` не мешала коду
     * доложить об успехе. Двойник `resourceModel`, бросающий исключение внутри
     * транзакции, доказывает: результат обязан быть `null`, а СКЛАД — физически
     * НЕ тронут (реальный откат в SQLite, не флаг в памяти PHP).
     */
    public function testPerformRetrieveOneRollsBackAndReturnsNullWhenCreditingBackpackFails(): void
    {
        $this->seedResource(1, 'Вода');
        $this->seedStorage(7, 1, 50);

        $failingResourceModel = new class () extends CharacterResourceModel {
            public function increaseResources($characterId, $resourceId, $amount)
            {
                throw new \RuntimeException('симулированный сбой зачисления в рюкзак');
            }
        };

        $outcome = $this->callPrivate($this->action($failingResourceModel), 'performRetrieveOne', [7, 1]);

        $this->assertNull($outcome, 'сорвавшаяся транзакция обязана вернуть null, а не "успех"');
        $this->assertSame(50, $this->storageQty(7, 1), 'откат обязан вернуть ресурс на склад физически');
        $this->assertSame(0, $this->backpackQty(7, 1), 'рюкзак не должен получить ничего при откате');
    }

    public function testPerformRetrieveOneReturnsZeroWhenNothingLeftOnStorage(): void
    {
        $this->seedResource(1, 'Вода');
        // на складе ничего нет для этого ресурса

        $outcome = $this->callPrivate($this->action(), 'performRetrieveOne', [7, 1]);

        $this->assertNotNull($outcome);
        $this->assertSame(0, $outcome['withdrawn']);
    }

    // ── Дефект 2: экранирование имени ───────────────────────────────────────

    public function testFormatRetrieveMessageEscapesEmptyNameInsteadOfBreakingMarkdown(): void
    {
        $action = $this->action();

        $text = $this->callPrivate($action, 'formatRetrieveMessage', ['', 5]);

        // Пустое имя раньше давало "**" (непарный маркер жирного) — рвало
        // legacy-Markdown у ВСЕГО сообщения. Фолбэк MarkdownSafe::name() не
        // должен оставлять два "*" подряд без текста между ними.
        $this->assertStringNotContainsString('**', $text);
        $this->assertStringContainsString('ресурс', $text, 'пустое имя обязано подставить безопасный фолбэк');
    }

    public function testFormatRetrieveMessageStripsBreakingMarkdownCharactersFromName(): void
    {
        $action = $this->action();

        $dirty = $this->callPrivate($action, 'formatRetrieveMessage', ['Вода*кожа_шкура', 3]);
        $clean = $this->callPrivate($action, 'formatRetrieveMessage', ['Водакожашкура', 3]);

        // Столько же "*", сколько дала бы уже-чистая версия того же имени —
        // символ "*"/"_" из самого имени вырезан
        // ({@see \App\Services\Display\MarkdownSafe::name()}, как и на экране
        // страховки), а не оставлен ломать разметку всего caption'а непарным
        // маркером.
        $this->assertSame(substr_count($clean, '*'), substr_count($dirty, '*'), 'непарные "*" из имени ресурса ломают весь caption');
        $this->assertStringContainsString('Водакожашкура', $dirty);
        $this->assertStringNotContainsString('_', $dirty);
    }

    // ── Дефект 3: «…и ещё N видов» должно совпадать с числом кнопок ─────────

    public function testBuildResourceButtonRowsHiddenCountMatchesActuallyShownButtons(): void
    {
        // 20 записей: первые 18 (срез MAX_BUTTONS) содержат 3 мусорные строки
        // (пустое имя / нулевое количество) — они НЕ становятся кнопками, но
        // раньше "…и ещё N" считало count(entries) - MAX_BUTTONS = 2, хотя
        // реально показанных кнопок было 15, а "потерянных" видов — 5.
        $entries = [];
        for ($i = 1; $i <= 20; $i++) {
            $isJunk = $i <= 18 && in_array($i, [3, 7, 12], true);
            $entries[] = [
                'resource_id' => $isJunk ? 0 : $i,
                'name'        => $isJunk ? '' : "Ресурс{$i}",
                'quantity'    => $isJunk ? 0 : 10,
            ];
        }

        $built = $this->callPrivate($this->action(), 'buildResourceButtonRows', [$entries, 'recent']);

        $shownButtons = array_sum(array_map('count', $built['rows']));

        $this->assertSame(15, $shownButtons, '18 в срезе минус 3 мусорных = 15 реальных кнопок');
        $this->assertSame(
            20 - $shownButtons,
            $built['hiddenCount'],
            '«…и ещё N видов» обязано считать от того же множества, что и кнопки'
        );
        $this->assertSame(5, $built['hiddenCount']);
    }

    public function testBuildResourceButtonRowsCarriesSortModeAndNeverPacksALonelyButton(): void
    {
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entries[] = ['resource_id' => $i, 'name' => "Ресурс{$i}", 'quantity' => 1];
        }

        $built = $this->callPrivate($this->action(), 'buildResourceButtonRows', [$entries, 'name']);

        $flat = array_merge(...$built['rows']);
        $this->assertCount(5, $flat);
        foreach ($flat as $btn) {
            $this->assertStringEndsWith('_name', $btn['callback_data'], 'режим сортировки обязан быть в callback_data кнопки');
        }
        foreach ($built['rows'] as $row) {
            $this->assertGreaterThan(1, count($row), 'ButtonPacker не должен оставлять одинокую кнопку в ряду');
        }
    }

    // ── Отказ «не на базе» — не голое «ошибка» ──────────────────────────────

    public function testOffBaseDenialExplainsWhatToDo(): void
    {
        $text = $this->callPrivate($this->action(), 'offBaseDenialText', []);

        $this->assertNotSame('ошибка', mb_strtolower(trim($text)));
        $this->assertStringContainsString('баз', mb_strtolower($text));
    }
}
