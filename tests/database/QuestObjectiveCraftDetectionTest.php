<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\Quests\QuestObjectiveHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;
use ReflectionMethod;

/**
 * V13 (ADR-037) — регрессия для craft_item-objective.
 *
 * Финал strategic-цепочки целится в faction-weapon (output_type=weapon →
 * пишется в characters_weapons, минуя crafted_items_log; crafted_items строк
 * для оружия нет вовсе). craftedCount ОБЯЗАН суммировать владение по всем путям
 * материализации крафта (crafted_items_log + characters_weapons +
 * characters_outfits), иначе финалы цепочек V12/V13 (Bunker/Technopark/GhostCity/
 * IslandFarm) недостижимы (BUILT-BUT-DEAD).
 *
 * @internal
 */
final class QuestObjectiveCraftDetectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'crafted_items', 'crafted_items_log', 'weapons',
        'characters_weapons', 'outfits', 'characters_outfits',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, name_eng VARCHAR(150) NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, crafted_item_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1)');
        $db->query('CREATE TABLE weapons (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(150) NULL)');
        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, weapon_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1)');
        $db->query('CREATE TABLE outfits (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(150) NULL)');
        $db->query('CREATE TABLE characters_outfits (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, outfit_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function craftedCount(int $charId, string $target): int
    {
        // craftedCount не использует $this (только переданный $db) → можно без конструктора.
        $handler = (new ReflectionClass(QuestObjectiveHandler::class))->newInstanceWithoutConstructor();
        $method  = new ReflectionMethod($handler, 'craftedCount');
        $method->setAccessible(true);

        return (int) $method->invoke($handler, Database::connect('tests'), $charId, $target);
    }

    public function testWeaponOutputIsDetected(): void
    {
        // Главный кейс бага: faction-weapon в characters_weapons.
        $db = Database::connect('tests');
        $db->table('weapons')->insert(['name_en' => 'GhostCityKnife']);
        $wid = (int) $db->insertID();
        $db->table('characters_weapons')->insert(['character_id' => 999, 'weapon_id' => $wid, 'quantity' => 1]);

        $this->assertSame(1, $this->craftedCount(999, 'GhostCityKnife'));
    }

    public function testCraftedItemLogIsDetected(): void
    {
        $db = Database::connect('tests');
        $db->table('crafted_items')->insert(['name_eng' => 'Bandage']);
        $iid = (int) $db->insertID();
        $db->table('crafted_items_log')->insert(['character_id' => 999, 'crafted_item_id' => $iid, 'quantity' => 3]);

        $this->assertSame(3, $this->craftedCount(999, 'Bandage'));
    }

    public function testOutfitOutputIsDetected(): void
    {
        $db = Database::connect('tests');
        $db->table('outfits')->insert(['name_en' => 'ScrapArmor']);
        $oid = (int) $db->insertID();
        $db->table('characters_outfits')->insert(['character_id' => 999, 'outfit_id' => $oid, 'quantity' => 2]);

        $this->assertSame(2, $this->craftedCount(999, 'ScrapArmor'));
    }

    public function testAbsentTargetIsZero(): void
    {
        $this->assertSame(0, $this->craftedCount(999, 'Nonexistent'));
    }

    public function testOtherCharacterNotCounted(): void
    {
        $db = Database::connect('tests');
        $db->table('weapons')->insert(['name_en' => 'BunkerRifle']);
        $wid = (int) $db->insertID();
        $db->table('characters_weapons')->insert(['character_id' => 111, 'weapon_id' => $wid, 'quantity' => 1]);

        $this->assertSame(0, $this->craftedCount(999, 'BunkerRifle'));
    }
}
