<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\EquipmentLoadoutService;
use App\Services\Web\WebNativeScreenService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;

/**
 * W2.N1-03 (ADR-190) — единый сервис надевания для бота и веба: атомарная смена в слоте,
 * отказы прежних `ToggleEquip*Action`, снятие вне базы, lock без Арсенала, дедуп веб-намерения.
 *
 * Схема — из настоящих миграций; персонаж 1 стоит на клетке своей базы с Арсеналом.
 *
 * @internal
 */
final class EquipmentLoadoutServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2025-02-08-194808_CreateOutfitsTable',
        '2025-02-08-195713_CreateWeaponsTable',
        '2025-02-10-224703_CreateCharactersOutfitsTable',
        '2025-02-11-115603_CreateCharactersWeaponsTable',
        '2026-08-28-100000_Adr137SoulboundGearColumns',
        '2026-09-06-110000_Adr137SoulboundProvenanceColumns',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'claimed_cells', 'buildings', 'character_buildings',
        'outfits', 'weapons', 'characters_outfits', 'characters_weapons', 'game_settings',
        'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const CHAR     = 1;
    private const STRANGER = 2;

    private BaseConnection $conn;

    private EquipmentLoadoutService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->mockCache();
        $this->svc = new EquipmentLoadoutService($this->conn);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('cache');
        $this->dropTables();
        parent::tearDown();
    }

    public function testEquipSecondWeaponLeavesOnlyItEquipped(): void
    {
        $a = $this->weapon(self::CHAR, 'Нож');
        $b = $this->weapon(self::CHAR, 'Топор');

        $this->assertSame(EquipmentLoadoutService::EQUIPPED, $this->svc->equip(self::CHAR, 'weapon', $a)['code']);
        $this->assertSame(EquipmentLoadoutService::EQUIPPED, $this->svc->equip(self::CHAR, 'weapon', $b)['code']);

        $this->assertSame([$b], $this->equipped('characters_weapons'));
    }

    public function testEquipArmorHealsDoubleEquippedSlotAndLeavesOtherSlot(): void
    {
        $helmet  = $this->outfitCatalog('Каска', 'head');
        $vest    = $this->outfitCatalog('Жилет', 'body');
        $noSlot  = $this->outfitCatalog('Старая каска', null);
        $a       = $this->armor(self::CHAR, $helmet, equipped: true);
        $b       = $this->armor(self::CHAR, $noSlot, equipped: true, rowSlot: 'head'); // слот только у строки склада
        $c       = $this->armor(self::CHAR, $helmet);
        $body    = $this->armor(self::CHAR, $vest, equipped: true);

        $out = $this->svc->equip(self::CHAR, 'armor', $c);

        $this->assertTrue($out['ok']);
        $this->assertSame('head', $out['slot']);
        $this->assertSame([$c, $body], $this->equipped('characters_outfits'));
        $this->assertNotContains($a, $this->equipped('characters_outfits'));
        $this->assertNotContains($b, $this->equipped('characters_outfits'));
    }

    public function testRefusalWithoutArsenalInCatalog(): void
    {
        $w = $this->weapon(self::CHAR, 'Нож');
        $this->conn->query('DELETE FROM buildings');

        $this->assertRefused(EquipmentLoadoutService::NO_ARSENAL_CATALOG, $this->svc->equip(self::CHAR, 'weapon', $w));
    }

    public function testRefusalWithoutOwnArsenal(): void
    {
        $w = $this->weapon(self::CHAR, 'Нож');
        $this->conn->query('DELETE FROM character_buildings');

        $this->assertRefused(EquipmentLoadoutService::NO_ARSENAL, $this->svc->equip(self::CHAR, 'weapon', $w));
        $this->assertRefused(EquipmentLoadoutService::NO_ARSENAL, $this->svc->unequip(self::CHAR, 'weapon', $w));
    }

    public function testRefusalForeignItem(): void
    {
        $w = $this->weapon(self::STRANGER, 'Чужой нож');

        $this->assertRefused(EquipmentLoadoutService::NOT_FOUND, $this->svc->equip(self::CHAR, 'weapon', $w));
        $this->assertNull($this->svc->item(self::CHAR, 'weapon', $w));
    }

    public function testRefusalWeaponWithZeroQuantity(): void
    {
        $w = $this->weapon(self::CHAR, 'Нож', quantity: 0);

        $this->assertRefused(EquipmentLoadoutService::NO_QUANTITY, $this->svc->equip(self::CHAR, 'weapon', $w));
    }

    public function testRefusalSoulboundMark(): void
    {
        $w = $this->weapon(self::CHAR, 'Метка', soulbound: true);

        $this->assertRefused(EquipmentLoadoutService::SOULBOUND, $this->svc->equip(self::CHAR, 'weapon', $w));
        $this->assertSame([], $this->equipped('characters_weapons'));
    }

    public function testRefusalNotOnBase(): void
    {
        $w = $this->weapon(self::CHAR, 'Нож');
        $this->conn->query('UPDATE characters SET cell_number = 999 WHERE id = ?', [self::CHAR]);

        $this->assertRefused(EquipmentLoadoutService::NOT_ON_BASE, $this->svc->equip(self::CHAR, 'weapon', $w));
    }

    public function testRefusalArmorWithoutSlot(): void
    {
        $a = $this->armor(self::CHAR, $this->outfitCatalog('Тряпка', null));

        $this->assertRefused(EquipmentLoadoutService::NO_SLOT, $this->svc->equip(self::CHAR, 'armor', $a));
    }

    public function testRefusalTextsKeepOldWording(): void
    {
        $this->assertSame(
            'У тебя нет здания «Арсенал» на базе, поэтому нельзя менять экипировку!',
            EquipmentLoadoutService::refusal('weapon', EquipmentLoadoutService::NO_ARSENAL, 'x')
        );
        $this->assertSame(
            "Снаряжение хранится на базе.\nТы не на базе, не можешь надеть \"Каска\"!",
            EquipmentLoadoutService::refusal('armor', EquipmentLoadoutService::NOT_ON_BASE, 'Каска')
        );
    }

    public function testUnequipWorksOffBase(): void
    {
        $w = $this->weapon(self::CHAR, 'Нож', equipped: true);
        $this->conn->query('UPDATE characters SET cell_number = 999 WHERE id = ?', [self::CHAR]);

        $this->assertSame(EquipmentLoadoutService::UNEQUIPPED, $this->svc->unequip(self::CHAR, 'weapon', $w)['code']);
        $this->assertSame([], $this->equipped('characters_weapons'));
    }

    public function testLoadoutWithoutArsenalCarriesLock(): void
    {
        $this->weapon(self::CHAR, 'Нож');
        $this->conn->query('DELETE FROM character_buildings');

        $model = $this->svc->forCharacter(self::CHAR);

        $this->assertFalse($model['arsenal']);
        $this->assertNotNull($model['lock']);
        $this->assertGreaterThanOrEqual(1, $model['lock']['required_level']);
        $this->assertSame('genericBuildInfo_Arsenal', $model['lock']['callback']);
        $this->assertCount(1, $model['weapons']);
    }

    public function testLoadoutWithArsenalHasNoLock(): void
    {
        $model = $this->svc->forCharacter(self::CHAR);

        $this->assertTrue($model['arsenal']);
        $this->assertNull($model['lock']);
        $this->assertTrue($model['on_base']);
    }

    public function testWebGearChangeIsIdempotentPerIntentAndVisibleToBot(): void
    {
        $a   = $this->weapon(self::CHAR, 'Нож');
        $b   = $this->weapon(self::CHAR, 'Топор');
        $web = new WebNativeScreenService(null, null, null, $this->svc);

        $first = $web->gearChange(7, self::CHAR, 'equip', 'weapon', $a, 'intent-1');
        $this->assertSame('Надето: «Нож». Остальное оружие снято.', $first);

        // Между повторами бот надел другое — повтор того же намерения ничего не трогает.
        $this->svc->toggle(self::CHAR, 'weapon', $b);
        $this->assertNull($web->gearChange(7, self::CHAR, 'equip', 'weapon', $a, 'intent-1'));
        $this->assertSame([$b], $this->equipped('characters_weapons'));

        // Веб-смена видна боту: экран деталей читает тот же item().
        $web->gearChange(7, self::CHAR, 'equip', 'weapon', $a, 'intent-2');
        $item = $this->svc->item(self::CHAR, 'weapon', $a);
        $this->assertNotNull($item);
        $this->assertTrue($item['equipped']);
    }

    /** @param array{ok:bool, code:string} $out */
    private function assertRefused(string $code, array $out): void
    {
        $this->assertFalse($out['ok']);
        $this->assertSame($code, $out['code']);
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->query("INSERT INTO map (id, cell_number, coordinate_x, coordinate_y) VALUES (10, 10, 1, 1)");
        $this->conn->query("INSERT INTO characters (id, name, cell_number) VALUES (?, 'Тест', 10), (?, 'Чужак', 10)", [self::CHAR, self::STRANGER]);
        $this->conn->query(
            "INSERT INTO claimed_cells (character_id, map_cell_id, claimed_at, status) VALUES (?, 10, ?, 'active')",
            [self::CHAR, $now]
        );
        $this->conn->query(
            "INSERT INTO buildings (id, name_ru, name_en, building_type, hp, construction_time, tax, `usage`, required_resources, min_character_level, effects)"
            . " VALUES (5, 'Арсенал', 'Arsenal', 'military', 100, 60, 0, 'personal', '{}', 1, '{}')"
        );
        $this->conn->query(
            'INSERT INTO character_buildings (character_id, building_id, map_cell_id, character_level_during_construction, hp, built_at, building_type, tax, `usage`, tax_collection_status)'
            . " VALUES (?, 5, 10, 1, 100, ?, 'military', 0, 'personal', 'SUCCESS')",
            [self::CHAR, $now]
        );
    }

    private function weapon(int $char, string $name, int $quantity = 1, bool $equipped = false, bool $soulbound = false): int
    {
        $this->conn->query("INSERT INTO weapons (name, weapon_type, damage_type) VALUES (?, 'Melee', 'Physical')", [$name]);
        $catalogId = (int) $this->conn->insertID();
        $this->conn->query(
            'INSERT INTO characters_weapons (character_id, weapon_id, quantity, equipped, is_soulbound) VALUES (?, ?, ?, ?, ?)',
            [$char, $catalogId, $quantity, (int) $equipped, (int) $soulbound]
        );

        return (int) $this->conn->insertID();
    }

    private function outfitCatalog(string $name, ?string $slot): int
    {
        $this->conn->query('INSERT INTO outfits (name, slot) VALUES (?, ?)', [$name, $slot]);

        return (int) $this->conn->insertID();
    }

    private function armor(int $char, int $outfitId, bool $equipped = false, ?string $rowSlot = null): int
    {
        $this->conn->query(
            'INSERT INTO characters_outfits (character_id, outfit_id, equipped, slot) VALUES (?, ?, ?, ?)',
            [$char, $outfitId, (int) $equipped, $rowSlot]
        );

        return (int) $this->conn->insertID();
    }

    /** @return list<int> id надетых строк персонажа 1 */
    private function equipped(string $table): array
    {
        $rows = $this->conn->query("SELECT id FROM {$table} WHERE character_id = ? AND equipped = 1 ORDER BY id", [self::CHAR])->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
