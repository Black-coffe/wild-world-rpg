<?php

namespace Tests\Database;

use App\Services\PVE\PvpEquipmentRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2.3b Step 1 — integration-тесты на PvpEquipmentRepository.
 *
 * Покрывает:
 *   - getEquippedWeapon (1+1 SQL, контракт идентичен legacy)
 *   - getEquippedOutfitsWithDetails (N+1 fix verification — 2 SQL)
 *   - getMapCell, getCharacterFaction
 *
 * @internal
 */
final class PvpEquipmentRepositoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private PvpEquipmentRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['characters_weapons', 'weapons', 'characters_outfits', 'outfits', 'map', 'character_factions', 'factions'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, weapon_id INT NOT NULL, equipped TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE weapons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, damage_value DECIMAL(10,2) NULL, range_value DECIMAL(10,2) NULL, damage_type VARCHAR(50) NULL, rarity VARCHAR(50) NULL, special_effect VARCHAR(100) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE characters_outfits (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, outfit_id INT NOT NULL, equipped TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE outfits (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, physical_resistance DECIMAL(10,2) NULL DEFAULT 0, fire_resistance DECIMAL(10,2) NULL DEFAULT 0, poison_resistance DECIMAL(10,2) NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE map (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, biome_id INT NOT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_factions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, faction_id INT NOT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE factions (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');

        $this->repo = new PvpEquipmentRepository();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['characters_weapons', 'weapons', 'characters_outfits', 'outfits', 'map', 'character_factions', 'factions'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    // ---- getEquippedWeapon ----

    public function testGetEquippedWeaponReturnsNullWhenNoEquipped(): void
    {
        $this->assertNull($this->repo->getEquippedWeapon(1));
    }

    public function testGetEquippedWeaponReturnsNullWhenWeaponRowMissing(): void
    {
        $db = Database::connect('tests');
        // characters_weapons указывает на несуществующий weapon.
        $db->query('INSERT INTO characters_weapons (character_id, weapon_id, equipped) VALUES (1, 999, 1)');
        $this->assertNull($this->repo->getEquippedWeapon(1));
    }

    public function testGetEquippedWeaponReturnsAssembledData(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO weapons (damage_value, range_value, damage_type, rarity) VALUES (25, 8, 'Physical', 'Rare')");
        $wId = (int) $db->insertID();
        $db->query('INSERT INTO characters_weapons (character_id, weapon_id, equipped) VALUES (1, ?, 1)', [$wId]);

        $weapon = $this->repo->getEquippedWeapon(1);

        $this->assertNotNull($weapon);
        $this->assertSame(25.0, $weapon['damage_value']);
        $this->assertSame(8.0,  $weapon['range_value']);
        $this->assertSame('Physical', $weapon['damage_type']);
        $this->assertSame('Rare',     $weapon['rarity']);
        $this->assertSame(0.0, $weapon['crit_chance']); // нет special_effect
    }

    public function testGetEquippedWeaponSpecialEffectGivesCritChance10(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO weapons (damage_value, range_value, damage_type, rarity, special_effect) VALUES (30, 5, 'Physical', 'Epic', 'bleed')");
        $wId = (int) $db->insertID();
        $db->query('INSERT INTO characters_weapons (character_id, weapon_id, equipped) VALUES (1, ?, 1)', [$wId]);

        $weapon = $this->repo->getEquippedWeapon(1);
        $this->assertSame(10.0, $weapon['crit_chance']);
    }

    public function testGetEquippedWeaponIgnoresUnequippedRows(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO weapons (damage_value, range_value, damage_type, rarity) VALUES (25, 8, 'Physical', 'Rare')");
        $wId = (int) $db->insertID();
        // equipped=0 — не должно подбираться.
        $db->query('INSERT INTO characters_weapons (character_id, weapon_id, equipped) VALUES (1, ?, 0)', [$wId]);
        $this->assertNull($this->repo->getEquippedWeapon(1));
    }

    // ---- getEquippedOutfitsWithDetails (N+1 FIX VERIFICATION) ----

    public function testGetEquippedOutfitsReturnsEmptyArrayWhenNothing(): void
    {
        $this->assertSame([], $this->repo->getEquippedOutfitsWithDetails(1));
    }

    public function testGetEquippedOutfitsReturnsAllEquipped(): void
    {
        $db = Database::connect('tests');
        // 3 outfit'а с разной защитой.
        $db->query('INSERT INTO outfits (physical_resistance, fire_resistance, poison_resistance) VALUES (10, 5, 0)');
        $o1 = (int) $db->insertID();
        $db->query('INSERT INTO outfits (physical_resistance, fire_resistance, poison_resistance) VALUES (15, 0, 10)');
        $o2 = (int) $db->insertID();
        $db->query('INSERT INTO outfits (physical_resistance, fire_resistance, poison_resistance) VALUES (5, 20, 5)');
        $o3 = (int) $db->insertID();

        $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (1, ?, 1)', [$o1]);
        $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (1, ?, 1)', [$o2]);
        $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (1, ?, 1)', [$o3]);

        $outfits = $this->repo->getEquippedOutfitsWithDetails(1);

        $this->assertCount(3, $outfits);
        $byId = array_column($outfits, null, 'id');
        $this->assertSame('10.00', $byId[$o1]['physical_resistance']);
        $this->assertSame('15.00', $byId[$o2]['physical_resistance']);
        $this->assertSame('5.00',  $byId[$o3]['physical_resistance']);
    }

    public function testGetEquippedOutfitsIgnoresUnequippedAndOtherCharacters(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO outfits (physical_resistance) VALUES (10)');
        $o1 = (int) $db->insertID();
        $db->query('INSERT INTO outfits (physical_resistance) VALUES (15)');
        $o2 = (int) $db->insertID();
        $db->query('INSERT INTO outfits (physical_resistance) VALUES (20)');
        $o3 = (int) $db->insertID();

        // char 1: o1 equipped, o2 unequipped.
        $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (1, ?, 1)', [$o1]);
        $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (1, ?, 0)', [$o2]);
        // char 2: o3 equipped — должен игнорироваться при запросе char 1.
        $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (2, ?, 1)', [$o3]);

        $outfits = $this->repo->getEquippedOutfitsWithDetails(1);
        $this->assertCount(1, $outfits);
        $this->assertSame($o1, (int) $outfits[0]['id']);
    }

    /**
     * Документация N+1 fix через verification что full equipment (5 слотов)
     * возвращает все 5 outfit'ов одним методом. CI4 не экспонирует надежный
     * query count metric, поэтому byte-equivalent SQL count проверяется
     * code review'ом (whereIn вместо foreach->find в репо).
     *
     * До F2.3b legacy делал 1 (characters_outfits) + 5 (per-outfit find) = 6 SQL.
     * После batch: 1 + 1 (whereIn) = 2 SQL независимо от количества слотов.
     */
    public function testGetEquippedOutfitsHandlesFullEquipmentInOneCall(): void
    {
        $db = Database::connect('tests');

        $outfitIds = [];
        for ($i = 0; $i < 5; $i++) {
            $db->query('INSERT INTO outfits (physical_resistance) VALUES (?)', [10 + $i]);
            $outfitIds[] = (int) $db->insertID();
        }
        foreach ($outfitIds as $oid) {
            $db->query('INSERT INTO characters_outfits (character_id, outfit_id, equipped) VALUES (1, ?, 1)', [$oid]);
        }

        $outfits = $this->repo->getEquippedOutfitsWithDetails(1);
        $this->assertCount(5, $outfits, 'все 5 equipped outfits возвращаются одним вызовом');

        // Все resistance values должны быть распарсены.
        $resistances = array_column($outfits, 'physical_resistance');
        sort($resistances);
        $this->assertSame(['10.00', '11.00', '12.00', '13.00', '14.00'], $resistances);
    }

    // ---- getMapCell ----

    public function testGetMapCellReturnsNullForUnknownCell(): void
    {
        $this->assertNull($this->repo->getMapCell(99999));
    }

    public function testGetMapCellReturnsRow(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (777, 12, 34, 5)');

        $cell = $this->repo->getMapCell(777);
        $this->assertNotNull($cell);
        $this->assertSame(12, (int) $cell['coordinate_x']);
        $this->assertSame(34, (int) $cell['coordinate_y']);
        $this->assertSame(5,  (int) $cell['biome_id']);
    }

    // ---- getCharacterFaction ----

    public function testGetCharacterFactionNullWhenNotInFaction(): void
    {
        $this->assertNull($this->repo->getCharacterFaction(1));
    }

    public function testGetCharacterFactionReturnsFactionRow(): void
    {
        $db = Database::connect('tests');
        $db->query("INSERT INTO factions (name) VALUES ('Wolves')");
        $fId = (int) $db->insertID();
        $db->query('INSERT INTO character_factions (character_id, faction_id) VALUES (1, ?)', [$fId]);

        $faction = $this->repo->getCharacterFaction(1);
        $this->assertNotNull($faction);
        $this->assertSame('Wolves', $faction['name']);
    }

    public function testGetCharacterFactionNullWhenFactionRowMissing(): void
    {
        $db = Database::connect('tests');
        // character_factions указывает на отсутствующую фракцию.
        $db->query('INSERT INTO character_factions (character_id, faction_id) VALUES (1, 999)');
        $this->assertNull($this->repo->getCharacterFaction(1));
    }
}
