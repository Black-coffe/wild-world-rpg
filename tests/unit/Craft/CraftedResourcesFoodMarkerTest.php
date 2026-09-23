<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Controllers\Telegram\Commands\Actions\CraftedResourcesAction;
use App\Database\Migrations\CreateCraftedItemsLogTable;
use App\Database\Migrations\CreateCraftedItemsTable;
use App\Models\CraftedItemsLogModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;

/**
 * bugs-info-0923-02 — «В крафтовых предметах есть еда, которая не отображается в
 * аптечка-провизия». Аптечка и Провизия читают только `crafted_items.type='drug'`,
 * поэтому `type='food'` нигде не применяется. Экран «Крафтовые предметы» обязан
 * сказать это рядом с именем и дать путь к живой еде.
 *
 * Схема — прогоном реальных миграций через Forge (feedback_test_schema_must_come_from_migration),
 * рендер — реальными private-методами через reflection (не сканом исходника).
 *
 * @internal
 */
final class CraftedResourcesFoodMarkerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MARKER = 'не применяется, выводится из обращения';
    private const PATH   = '💊 Аптечка → 🍲 Провизия';

    private const CHARACTER_ID = 7001;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->dropTables();

        // Файлы миграций с датой в имени — PSR-4 их не найдёт.
        foreach ([
            CreateCraftedItemsTable::class    => '2024-04-16-100640_CreateCraftedItemsTable.php',
            CreateCraftedItemsLogTable::class => '2024-04-16-122053_CreateCraftedItemsLogTable.php',
        ] as $class => $file) {
            if (! class_exists($class, false)) {
                require_once APPPATH . 'Database/Migrations/' . $file;
            }
        }

        $forge = Database::forge('tests');
        $this->assertInstanceOf(Forge::class, $forge);

        // Миграция лога несёт FK на characters/tasks — сами таблицы экрану не нужны.
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        (new CreateCraftedItemsTable($forge))->up();
        (new CreateCraftedItemsLogTable($forge))->up();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');

        // Прод-набор food (id 23/24/25) + одно лекарство для контраста.
        $items = [
            23 => ['Мясо дикого кабана', 'food'],
            24 => ['Рыбный суп', 'food'],
            25 => ['Ягодный джем', 'food'],
            40 => ['Бинт', 'drug'],
        ];
        foreach ($items as $id => [$name, $type]) {
            $this->conn->table('crafted_items')->insert([
                'id' => $id, 'name_rus' => $name, 'name_eng' => $name, 'type' => $type, 'price' => 10,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function testFoodRowsCarryMarkerAndFoodBlockCarriesPath(): void
    {
        $this->own(23, 3);
        $this->own(24, 1);
        $this->own(25, 2);
        $this->own(40, 5);

        $text = $this->renderGrouped();

        foreach (['Мясо дикого кабана', 'Рыбный суп', 'Ягодный джем'] as $name) {
            $this->assertMatchesRegularExpression(
                '/\*' . preg_quote($name, '/') . '\* \| \d+ шт\. — ' . preg_quote(self::MARKER, '/') . '\n/u',
                $text,
                "food-предмет «{$name}» обязан нести пометку рядом с именем",
            );
        }

        $foodBlock = $this->block($text, '🍲 *Еда* 🍲');
        $this->assertStringContainsString(self::PATH, $foodBlock, 'в блоке еды обязан быть путь к живой еде');

        $drugBlock = $this->block($text, '💊 *Лекарства* 💊');
        $this->assertStringContainsString('*Бинт* | 5 шт.', $drugBlock);
        $this->assertStringNotContainsString(self::MARKER, $drugBlock, 'drug не несёт пометку');
        $this->assertStringNotContainsString(self::PATH, $drugBlock, 'drug-блок не несёт строку пути');

        $this->assertSame(0, substr_count($text, '*') % 2, 'Markdown: звёздочки парные');
        $this->assertStringNotContainsString('_', $text, 'Markdown: нет непарных подчёркиваний');
    }

    public function testDrugOnlyScreenHasNoMarkerAndNoPath(): void
    {
        $this->own(40, 5);

        $text = $this->renderGrouped();

        $this->assertStringContainsString('*Бинт* | 5 шт.' . "\n", $text);
        $this->assertStringNotContainsString(self::MARKER, $text);
        $this->assertStringNotContainsString(self::PATH, $text);
    }

    public function testFlatSortModeAlsoMarksFood(): void
    {
        $this->own(24, 1);
        $this->own(40, 5);

        $text = $this->invoke('renderFlat', $this->loadRows(), 'name');

        $this->assertStringContainsString('*Рыбный суп* | 1 шт. — ' . self::MARKER, $text);
        $this->assertStringContainsString('*Бинт* | 5 шт.' . "\n", $text);
        $this->assertStringContainsString(self::PATH, $text);
    }

    private function own(int $itemId, int $qty): void
    {
        // FK на characters/tasks из миграции: персонаж экрану не нужен, только его id.
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->table('crafted_items_log')->insert([
            'character_id' => self::CHARACTER_ID, 'crafted_item_id' => $itemId, 'quantity' => $qty,
        ]);
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function renderGrouped(): string
    {
        return $this->invoke('renderGrouped', $this->loadRows());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRows(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->invokeRaw('loadRows', self::CHARACTER_ID);

        return $rows;
    }

    private function invoke(string $method, mixed ...$args): string
    {
        $result = $this->invokeRaw($method, ...$args);
        $this->assertIsString($result);

        return $result;
    }

    private function invokeRaw(string $method, mixed ...$args): mixed
    {
        $refl   = new ReflectionClass(CraftedResourcesAction::class);
        $action = $refl->newInstanceWithoutConstructor();

        $prop = $refl->getProperty('craftedItemsLogModel');
        $prop->setValue($action, new CraftedItemsLogModel());

        return $refl->getMethod($method)->invoke($action, ...$args);
    }

    /** Блок экрана от заголовка до пустой строки. */
    private function block(string $text, string $heading): string
    {
        $pos = strpos($text, $heading);
        $this->assertNotFalse($pos, "заголовок {$heading} не найден");
        $end = strpos($text, "\n\n", $pos);

        return substr($text, $pos, $end === false ? null : $end - $pos);
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->query('DROP TABLE IF EXISTS crafted_items_log');
        $this->conn->query('DROP TABLE IF EXISTS crafted_items');
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        // CI4 кэширует listTables(): без сброса Forge видит снесённую таблицу живой.
        $this->conn->resetDataCache();
    }
}
