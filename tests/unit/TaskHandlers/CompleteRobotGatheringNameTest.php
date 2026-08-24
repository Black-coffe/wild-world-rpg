<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\TaskHandlers\CompleteRobotGatheringHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use ReflectionMethod;

/**
 * Story chat-requests-batch-01 — «и у меня промышленник, но в сообщении добытчик»
 * (19.08.2026, Max Syskov). `CompleteRobotGatheringHandler` подписывал ВСЕ тексты
 * отчёта зашитой константой «Робот-добытчик», игнорируя реально запущенную машину
 * (`task_settings.crafted_item_id` → `crafted_items.name_rus` — тот же резолвер,
 * которым уже пользуются tier-бонусы `resolveRobotNameEn()`).
 *
 * Вставляем реальную строку `crafted_items` (не мок — `CraftedItemsModel` в
 * `resolveRobotDisplayName()`/`resolveRobotNameEn()` инстанцируется прямо в теле
 * метода, DI на неё нет — расширять конструктор ради инъекции вне скоупа story,
 * правится только текст отчёта). Локальная `wildworld_tests` — разреженная база
 * (`buildings`/`crafted_items` тут не migrated, memory
 * `reference_local_db_bootstrap_from_testbot`), а конструктор хендлера бьёт в
 * `buildings` живым запросом до того, как за что-либо можно зацепиться reflection'ом
 * — поэтому setUp/tearDown создают/дропают эти две таблицы САМИ, но только если их
 * ещё нет (`IF NOT EXISTS` / `tableExists()`-гейт): на окружении с полным дампом
 * (CI/testbot) обе таблицы уже есть и тест их не трогает.
 *
 * @internal
 */
final class CompleteRobotGatheringNameTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdBuildings    = false;
    private bool $createdCraftedItems = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->db->tableExists('buildings')) {
            $this->db->query('CREATE TABLE buildings (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191))');
            $this->createdBuildings = true;
        }
        if (! $this->db->tableExists('crafted_items')) {
            $this->db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255), name_eng VARCHAR(255))');
            $this->createdCraftedItems = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown(); // сначала откатывает hasInDatabase()-вставки, пока таблица ещё жива

        if ($this->createdCraftedItems) {
            $this->db->query('DROP TABLE IF EXISTS crafted_items');
        }
        if ($this->createdBuildings) {
            $this->db->query('DROP TABLE IF EXISTS buildings');
        }
    }

    private function handler(): CompleteRobotGatheringHandler
    {
        return new CompleteRobotGatheringHandler();
    }

    private function callResolve(CompleteRobotGatheringHandler $h, array $task): string
    {
        $m = new ReflectionMethod($h, 'resolveRobotDisplayName');
        $m->setAccessible(true);

        return $m->invoke($h, $task);
    }

    private function callFormat(CompleteRobotGatheringHandler $h, string $robotName): string
    {
        $m = new ReflectionMethod($h, 'formatGatheringResultMessage');
        $m->setAccessible(true);

        return $m->invoke($h, [], 1.0, 1, [], $robotName);
    }

    public function testResolvesRealRobotDisplayNameFromCraftedItem(): void
    {
        $this->hasInDatabase('crafted_items', [
            'name_rus' => 'Тестовый Db01 Промышленник',
            'name_eng' => 'TestDb01Industrialist',
        ]);
        $id = (int) $this->db->insertID();

        $name = $this->callResolve($this->handler(), [
            'task_settings' => json_encode(['crafted_item_id' => $id]),
        ]);

        $this->assertSame('Тестовый Db01 Промышленник', $name, 'вставленный робот называется своим именем, не константой');
    }

    public function testFallsBackToNeutralRobotWhenCraftedItemMissing(): void
    {
        $name = $this->callResolve($this->handler(), [
            'task_settings' => json_encode(['crafted_item_id' => 999999999]),
        ]);

        $this->assertSame('Робот', $name, 'несуществующая строка — нейтральный фолбэк, не чужое имя');
    }

    public function testFallsBackToNeutralRobotWhenTaskSettingsMissing(): void
    {
        $name = $this->callResolve($this->handler(), []);

        $this->assertSame('Робот', $name);
    }

    public function testFormatGatheringResultMessageUsesRobotNameInHeader(): void
    {
        $msg = $this->callFormat($this->handler(), 'Тестовый Db01 Промышленник');

        $this->assertStringContainsString('Тестовый Db01 Промышленник завершил работу', $msg);
        $this->assertStringNotContainsString('Робот-добытчик', $msg, 'старая константа не должна утекать в текст отчёта');
    }

    /**
     * Ревью-довесок: имя из `crafted_items.name_rus` идёт в legacy-`parse_mode: 'Markdown'`
     * внутри `*{$robotName}*` — непарные `*`/`_` в имени валят парсинг ВСЕГО сообщения
     * (400 → тихий не-сенд). `resolveRobotDisplayName()` санитайзит через
     * `MarkdownSafe::text()` — этот тест ловит и точку резолва, и то, что опасное имя
     * не протекает дальше в собранный текст отчёта.
     */
    public function testDisplayNameStripsMarkdownBreakingCharacters(): void
    {
        $this->hasInDatabase('crafted_items', [
            'name_rus' => 'Верстак_2 Сталь*',
            'name_eng' => 'TestMarkdownBreakingName',
        ]);
        $id = (int) $this->db->insertID();

        $name = $this->callResolve($this->handler(), [
            'task_settings' => json_encode(['crafted_item_id' => $id]),
        ]);

        $this->assertStringNotContainsString('_', $name, 'подчёркивание из имени вырезано');
        $this->assertStringNotContainsString('*', $name, 'звёздочка из имени вырезана');
        $this->assertSame('Верстак2 Сталь', $name);

        $msg = $this->callFormat($this->handler(), $name);
        $this->assertStringNotContainsString('_', $msg, 'опасное имя не протекает в итоговый текст отчёта');
        $this->assertSame(0, substr_count($msg, '*') % 2, 'непарная * ломает Legacy Markdown отправку молча');
    }
}
