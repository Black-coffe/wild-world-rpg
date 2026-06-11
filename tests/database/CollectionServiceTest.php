<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\CollectionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E19 (ADR-119) — CollectionService: killswitch + min_level гейт + donate (списание/заполнение слота/
 * завершение→титул) + isComplete + rarity. Списание ресурса и уровень подменены seam'ами (без
 * resources/character_resources схемы). Award-титула — реальный путь (titles + character_titles).
 *
 * @internal
 */
final class CollectionServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'collections', 'collection_slots', 'character_collection_slots', 'titles', 'character_titles', 'characters'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE collections (id INT AUTO_INCREMENT PRIMARY KEY, collection_key VARCHAR(64), name VARCHAR(128), description VARCHAR(512) NULL, theme VARCHAR(64) NULL, min_level INT DEFAULT 50, reward_title_key VARCHAR(64) NULL, icon VARCHAR(16) NULL, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE collection_slots (id INT AUTO_INCREMENT PRIMARY KEY, collection_id INT, resource_name VARCHAR(128), required_qty INT DEFAULT 1, sort_order INT DEFAULT 0)');
        $db->query('CREATE TABLE character_collection_slots (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, collection_slot_id INT, donated_at DATETIME NULL, UNIQUE KEY uniq_ccs (character_id, collection_slot_id))');
        $db->query('CREATE TABLE titles (id INT AUTO_INCREMENT PRIMARY KEY, title_key VARCHAR(64), name VARCHAR(64), description VARCHAR(255) NULL, source_type VARCHAR(16), source_ref VARCHAR(64), icon VARCHAR(16) NULL, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE character_titles (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, title_id INT, unlocked_at DATETIME NULL, UNIQUE KEY uniq_ct (character_id, title_id))');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, level INT DEFAULT 1, active_title_id INT NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
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

    private function enable(): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'collections.enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $this->cleanCache();
    }

    private function seedCollection(string $key, int $minLevel = 50, ?string $rewardKey = null, int $sort = 0, int $enabled = 1): int
    {
        $db = Database::connect('tests');
        $db->table('collections')->insert([
            'collection_key' => $key, 'name' => $key, 'description' => 'd', 'theme' => 't',
            'min_level' => $minLevel, 'reward_title_key' => $rewardKey, 'icon' => '🏛', 'sort_order' => $sort, 'enabled' => $enabled,
        ]);
        return (int) $db->insertID();
    }

    private function seedSlot(int $collectionId, string $resource, int $qty = 1, int $sort = 0): int
    {
        $db = Database::connect('tests');
        $db->table('collection_slots')->insert(['collection_id' => $collectionId, 'resource_name' => $resource, 'required_qty' => $qty, 'sort_order' => $sort]);
        return (int) $db->insertID();
    }

    private function seedChar(int $level = 60): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['level' => $level]);
        return (int) $db->insertID();
    }

    public function testKillswitchDefaultOff(): void
    {
        $this->assertFalse((new CollectionService())->isEnabled());
    }

    public function testKillswitchOnAndMinLevelDefault(): void
    {
        $this->enable();
        $svc = new CollectionService();
        $this->assertTrue($svc->isEnabled());
        $this->assertSame(50, $svc->minLevel());
    }

    public function testDefinitionsOnlyEnabledOrdered(): void
    {
        $this->seedCollection('b', 50, null, 20);
        $this->seedCollection('a', 50, null, 10);
        $this->seedCollection('off', 50, null, 5, 0);
        $keys = array_map(static fn ($d) => $d['collection_key'], (new CollectionService())->definitions());
        $this->assertSame(['a', 'b'], $keys);
    }

    public function testDonateRejectedWhenDisabled(): void
    {
        $cid  = $this->seedCollection('c');
        $slot = $this->seedSlot($cid, 'Древние реликвии', 1);
        $char = $this->seedChar();
        $res  = $this->makeService()->donate($char, $slot);
        $this->assertFalse($res['ok']);
        $this->assertSame('disabled', $res['reason']);
    }

    public function testDonateRejectedBelowMinLevel(): void
    {
        $this->enable();
        $cid  = $this->seedCollection('c', 50);
        $slot = $this->seedSlot($cid, 'Древние реликвии', 1);
        $char = $this->seedChar(40);
        $svc  = $this->makeService();
        $svc->levelReturns = 40;
        $res = $svc->donate($char, $slot);
        $this->assertFalse($res['ok']);
        $this->assertSame('level', $res['reason']);
    }

    public function testDonateInsufficientDoesNotFillSlot(): void
    {
        $this->enable();
        $cid  = $this->seedCollection('c', 50);
        $slot = $this->seedSlot($cid, 'Древние реликвии', 3);
        $char = $this->seedChar(60);
        $svc  = $this->makeService();
        $svc->deductReturns = false; // нехватка
        $res = $svc->donate($char, $slot);
        $this->assertFalse($res['ok']);
        $this->assertSame('insufficient', $res['reason']);
        $this->assertSame([], $svc->filledSlotIds($char));
    }

    public function testDonateFillsSlotButNotComplete(): void
    {
        $this->enable();
        $cid = $this->seedCollection('c', 50);
        $s1  = $this->seedSlot($cid, 'Древние реликвии', 1, 1);
        $this->seedSlot($cid, 'Пепел Предтеч', 1, 2);
        $char = $this->seedChar(60);
        $svc  = $this->makeService();

        $res = $svc->donate($char, $s1);
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['collectionCompleted']);
        $this->assertSame([$s1], $svc->filledSlotIds($char));
        $this->assertFalse($svc->isComplete($char, $cid));
    }

    public function testDonateCompletingAwardsTitle(): void
    {
        $this->enable();
        // титул-награда
        $db = Database::connect('tests');
        $db->table('titles')->insert(['title_key' => 'keeper', 'name' => 'Хранитель', 'source_type' => 'collection', 'source_ref' => 'rel', 'icon' => '🏺', 'enabled' => 1]);
        $titleId = (int) $db->insertID();

        $cid = $this->seedCollection('rel', 50, 'keeper');
        $s1  = $this->seedSlot($cid, 'A', 1, 1);
        $s2  = $this->seedSlot($cid, 'B', 1, 2);
        $char = $this->seedChar(60);
        $svc  = $this->makeService();

        $svc->donate($char, $s1);
        $res = $svc->donate($char, $s2); // закрывает коллекцию
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['collectionCompleted']);
        $this->assertSame('Хранитель', $res['titleAwarded']);
        $this->assertTrue($svc->isComplete($char, $cid));
        // титул реально выдан
        $owns = $db->table('character_titles')->where('character_id', $char)->where('title_id', $titleId)->countAllResults();
        $this->assertSame(1, $owns);
    }

    public function testDonateAlreadyFilled(): void
    {
        $this->enable();
        $cid  = $this->seedCollection('c', 50);
        $slot = $this->seedSlot($cid, 'A', 1);
        $char = $this->seedChar(60);
        $svc  = $this->makeService();

        $this->assertTrue($svc->donate($char, $slot)['ok']);
        $res = $svc->donate($char, $slot);
        $this->assertFalse($res['ok']);
        $this->assertSame('already', $res['reason']);
    }

    public function testRarityPercentMap(): void
    {
        $this->enable();
        $cid = $this->seedCollection('c', 50);
        $s1  = $this->seedSlot($cid, 'A', 1);
        $c1  = $this->seedChar(60);
        $this->seedChar(60);
        $this->seedChar(60);
        $this->seedChar(60); // 4 чара
        $svc = $this->makeService();
        $svc->donate($c1, $s1); // c1 завершил (1 слот) → 1/4 = 25%

        $map = $svc->rarityPercentMap();
        $this->assertEqualsWithDelta(25.0, $map[$cid], 0.01);
    }

    private function makeService(): TestableCollectionService
    {
        return new TestableCollectionService();
    }
}

/**
 * Подкласс с подменёнными seam'ами: списание ресурса и уровень — без resources-схемы.
 *
 * @internal
 */
final class TestableCollectionService extends CollectionService
{
    public bool $deductReturns = true;
    public int $levelReturns   = 60;

    protected function deductResource(string $resourceName, int $characterId, int $qty): bool
    {
        return $this->deductReturns;
    }

    protected function characterLevel(int $characterId): int
    {
        return $this->levelReturns;
    }
}
