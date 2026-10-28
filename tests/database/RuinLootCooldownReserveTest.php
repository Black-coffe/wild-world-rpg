<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateCharacterRuinLootTable;
use App\Models\CharacterRuinLootModel;
use App\Services\Settlement\RuinLootService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * s2-conditional-writes-04 (F10, ADR-181) — кулдаун руин и поясных аномалий резервируется
 * условной записью ДО выдачи лута.
 *
 * Форма теста спеки — не гонка, а устаревший снимок: модель кулдауна отдаёт сервису чтение
 * «ещё не лутил» (снимок второго запроса, сделанный до записи первого), а в БД строка уже
 * свежая. Сервис обязан отказать по записи (`affectedRows`), а не по чтению: `cooldown`,
 * ресурсы не изменились, строка кулдауна одна.
 *
 * `character_ruin_loot` строится настоящей миграцией (UNIQUE(character_id, settlement_id)).
 *
 * @internal
 */
final class RuinLootCooldownReserveTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['settlements', 'character_ruin_loot', 'resources', 'character_resources', 'game_settings'];

    private int $settlementId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->resetDataCache();

        require_once APPPATH . 'Database/Migrations/2026-07-03-110000_CreateCharacterRuinLootTable.php';
        (new CreateCharacterRuinLootTable(Database::forge('tests')))->up();

        $db->query('
            CREATE TABLE settlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(64) NULL, type VARCHAR(16) NULL, loot_config TEXT NULL
            )');
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NULL, name_en VARCHAR(120) NULL
            )');
        $db->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL, id_resources INT NOT NULL, quantity INT NOT NULL DEFAULT 0
            )');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL, value_string TEXT NULL,
                hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL
            )');

        $db->table('resources')->insert(['name' => 'Сера', 'name_en' => 'Sulfur']);
        $db->table('resources')->insert(['name' => 'Нефть', 'name_en' => 'Oil']);

        $db->table('settlements')->insert([
            'code'        => 'reserve_test',
            'type'        => 'ruins',
            'loot_config' => json_encode(['resources' => ['Sulfur' => 4, 'Oil' => 2]]),
        ]);
        $this->settlementId = (int) $db->insertID();

        foreach (['settlements.ruins', 'world.anomalies'] as $prefix) {
            $this->setSetting($prefix . '.enabled', 'bool', 'value_bool', 1);
            $this->setSetting($prefix . '.loot_cooldown_hours', 'int', 'value_int', 12);
            $this->setSetting($prefix . '.loot_amount_mult', 'float', 'value_float', 1.0);
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->resetDataCache();
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

    private function setSetting(string $key, string $type, string $col, int|float $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => $type,
            'hard_min'    => '0', 'hard_max' => '999999', $col => $val,
        ]);
        $this->cleanCache();
    }

    /** @return array<string,mixed> */
    private function settlementRow(): array
    {
        $row = Database::connect('tests')->table('settlements')->where('id', $this->settlementId)->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /** @return array<int,int> id_resources => quantity */
    private function quantities(int $charId): array
    {
        $rows = Database::connect('tests')->table('character_resources')
            ->where('id_characters', $charId)->orderBy('id_resources')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id_resources']] = (int) $r['quantity'];
        }

        return $out;
    }

    /** Модель кулдауна, чьё чтение — устаревший снимок «ещё не лутил». */
    private function staleSnapshotLog(): CharacterRuinLootModel
    {
        return new class () extends CharacterRuinLootModel {
            public function lastLootedAt(int $characterId, int $settlementId): ?string
            {
                return null;
            }
        };
    }

    /** @return iterable<string, array{string}> */
    public static function entrances(): iterable
    {
        yield 'ruins' => ['settlements.ruins'];
        yield 'belt anomaly' => ['world.anomalies'];
    }

    #[DataProvider('entrances')]
    public function testLootInsideCooldownWindowIsRefusedByTheWriteNotTheRead(string $prefix): void
    {
        $charId = 4242;
        $svc    = new RuinLootService(log: $this->staleSnapshotLog(), keyPrefix: $prefix);

        $first = $svc->loot($charId, $this->settlementRow());
        $this->assertTrue($first['ok'], 'первый лут обязан пройти');

        // Кулдаун «только что» — строка свежая, а чтение сервиса (снимок) её не видит.
        Database::connect('tests')->table('character_ruin_loot')
            ->where('character_id', $charId)->where('settlement_id', $this->settlementId)
            ->update(['last_looted_at' => date('Y-m-d H:i:s')]);

        $before = $this->quantities($charId);

        $second = $svc->loot($charId, $this->settlementRow());

        $this->assertFalse($second['ok'], 'ruin/anomaly loot issued inside the cooldown window');
        $this->assertSame('cooldown', $second['reason'] ?? null, 'ruin/anomaly loot issued inside the cooldown window');
        $this->assertSame($before, $this->quantities($charId), 'ruin/anomaly loot issued inside the cooldown window');
        $this->assertSame(
            1,
            Database::connect('tests')->table('character_ruin_loot')
                ->where('character_id', $charId)->where('settlement_id', $this->settlementId)->countAllResults(),
            'ruin/anomaly loot issued inside the cooldown window'
        );
    }
}
