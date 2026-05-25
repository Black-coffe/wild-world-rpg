<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\Gather\BiomeGatherProfileService;
use Config\GatherBiomeProfiles;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V22 (ADR-054) — BiomeGatherProfileService: biome-driven добыча.
 *
 * GameSettings читаются из game_settings (как FarmingServiceTest). Дефолты проверяются
 * БЕЗ строки (get → default), tuned — с seeded-строкой. Детерминированно (0 RNG).
 *
 * @internal
 */
final class BiomeGatherProfileServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var array<int,string> resource_id → RU-имя для тестов */
    private array $names = [
        2  => 'Древесина', // forest signature
        10 => 'Камни',     // forest scarce / mountains signature
        17 => 'Вода',      // forest neutral / mountains+deserts scarce
        30 => 'Кристаллы', // caves signature
        50 => 'Обсидиан',  // volcanic signature
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'resources', 'value_type' => 'float', 'value_float' => $float,
        ]);
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

    /** @return list<array<string,mixed>> */
    private function found(int ...$ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = ['resource_id' => $id, 'amount' => 10, 'type' => ''];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows */
    private function amountOf(array $rows, int $id): ?int
    {
        foreach ($rows as $r) {
            if ((int) $r['resource_id'] === $id) {
                return (int) $r['amount'];
            }
        }
        return null;
    }

    // ── Config data-shape ────────────────────────────────────────────────

    public function testConfigCoversAllNineBiomes(): void
    {
        $this->assertCount(9, GatherBiomeProfiles::PROFILES);
        $slugs = [];
        foreach (GatherBiomeProfiles::PROFILES as $biomeId => $p) {
            $this->assertIsInt($biomeId);
            $this->assertArrayHasKey('slug', $p);
            $this->assertArrayHasKey('signature', $p);
            $this->assertArrayHasKey('scarce', $p);
            $this->assertNotSame([], $p['signature'], "biome {$biomeId} без signature");
            $slugs[] = $p['slug'];
        }
        // slugs уникальны.
        $this->assertSame($slugs, array_values(array_unique($slugs)));
    }

    public function testConfigHelpers(): void
    {
        $this->assertSame('forest', GatherBiomeProfiles::slugFor(1));
        $this->assertSame('caves', GatherBiomeProfiles::slugFor(7));
        $this->assertNull(GatherBiomeProfiles::slugFor(999));
        $this->assertNull(GatherBiomeProfiles::slugFor(null));

        $this->assertContains('Древесина', GatherBiomeProfiles::signatureFor(1));
        $this->assertContains('Камни', GatherBiomeProfiles::scarceFor(1));
        $this->assertSame([], GatherBiomeProfiles::signatureFor(999));
        $this->assertSame([], GatherBiomeProfiles::scarceFor(null));
        // новые биомы — без scarce (pure non-hostile).
        $this->assertSame([], GatherBiomeProfiles::scarceFor(7)); // caves
        $this->assertSame([], GatherBiomeProfiles::scarceFor(8)); // volcanic
    }

    // ── множители (дефолты) ──────────────────────────────────────────────

    public function testSignatureMultiplierDefaults(): void
    {
        $svc = new BiomeGatherProfileService();
        $this->assertSame(10.0, $svc->signatureMultiplier('forest'));
        $this->assertSame(10.0, $svc->signatureMultiplier('deserts'));
        $this->assertSame(3.0, $svc->signatureMultiplier('caves'));
        $this->assertSame(3.0, $svc->signatureMultiplier('volcanic'));
        $this->assertSame(0.5, $svc->offbiomeMultiplier());
        $this->assertTrue($svc->isEnabled());
    }

    public function testSignatureMultiplierTuned(): void
    {
        $this->seedFloat('gather.biome.caves.signature_multiplier', 5.0);
        $svc = new BiomeGatherProfileService();
        $this->assertSame(5.0, $svc->signatureMultiplier('caves'));
        // непереопределённый остаётся дефолтным.
        $this->assertSame(10.0, $svc->signatureMultiplier('forest'));
    }

    public function testInvalidMultiplierFallsBackToDefault(): void
    {
        $this->seedFloat('gather.biome.caves.signature_multiplier', 0.0); // <=0 → default
        $svc = new BiomeGatherProfileService();
        $this->assertSame(3.0, $svc->signatureMultiplier('caves'));
    }

    // ── modifyResourcesByBiome ───────────────────────────────────────────

    public function testForestLegacyPreserved(): void
    {
        $svc  = new BiomeGatherProfileService();
        $rows = $svc->modifyResourcesByBiome(1, $this->found(2, 10, 17), $this->names);
        // Древесина signature ×10, Камни scarce ×0.5, Вода нейтральна.
        $this->assertSame(100, $this->amountOf($rows, 2));  // 10 × 10.0
        $this->assertSame(5, $this->amountOf($rows, 10));   // 10 × 0.5
        $this->assertSame(10, $this->amountOf($rows, 17));  // unchanged
    }

    public function testNewBiomeSignatureBoost(): void
    {
        $svc  = new BiomeGatherProfileService();
        // Пещеры (7): Кристаллы signature ×3, scarce=[].
        $rows = $svc->modifyResourcesByBiome(7, $this->found(30, 17), $this->names);
        $this->assertSame(30, $this->amountOf($rows, 30)); // 10 × 3.0
        $this->assertSame(10, $this->amountOf($rows, 17)); // neutral, нет scarce
        // Вулкан (8): Обсидиан signature ×3.
        $rows2 = $svc->modifyResourcesByBiome(8, $this->found(50), $this->names);
        $this->assertSame(30, $this->amountOf($rows2, 50));
    }

    public function testKillswitchOffReturnsUnchanged(): void
    {
        $this->seedBool('gather.biome.rebalance_enabled', 0);
        $svc  = new BiomeGatherProfileService();
        $this->assertFalse($svc->isEnabled());
        $rows = $svc->modifyResourcesByBiome(1, $this->found(2, 10), $this->names);
        $this->assertSame(10, $this->amountOf($rows, 2));  // без буста
        $this->assertSame(10, $this->amountOf($rows, 10)); // без дефицита
        // и хинт пуст.
        $this->assertSame([], $svc->signatureNamesFor(1));
    }

    public function testUnknownBiomeUnchanged(): void
    {
        $svc = new BiomeGatherProfileService();
        $this->assertSame($this->found(2, 10), $svc->modifyResourcesByBiome(999, $this->found(2, 10), $this->names));
        $this->assertSame($this->found(2), $svc->modifyResourcesByBiome(null, $this->found(2), $this->names));
    }

    public function testEmptyResourcesUnchanged(): void
    {
        $svc = new BiomeGatherProfileService();
        $this->assertSame([], $svc->modifyResourcesByBiome(1, [], $this->names));
    }

    public function testResourceWithoutNameSkipped(): void
    {
        $svc = new BiomeGatherProfileService();
        // resource_id 2 (Древесина) есть в names, 999 — нет.
        $rows = $svc->modifyResourcesByBiome(1, $this->found(2, 999), $this->names);
        $this->assertSame(100, $this->amountOf($rows, 2));   // boosted
        $this->assertSame(10, $this->amountOf($rows, 999));  // нет имени → unchanged
    }

    public function testTunedOffbiomeMultiplier(): void
    {
        $this->seedFloat('gather.biome.offbiome_multiplier', 0.25);
        $svc  = new BiomeGatherProfileService();
        $rows = $svc->modifyResourcesByBiome(1, $this->found(10), $this->names); // Камни scarce
        $this->assertSame(3, $this->amountOf($rows, 10)); // round(10 × 0.25)=2.5→3
    }

    public function testSignatureNamesForHint(): void
    {
        $svc = new BiomeGatherProfileService();
        $this->assertContains('Древесина', $svc->signatureNamesFor(1));
        $this->assertContains('Кристаллы', $svc->signatureNamesFor(7));
        $this->assertSame([], $svc->signatureNamesFor(999));
        $this->assertSame([], $svc->signatureNamesFor(null));
    }

    public function testAllSignatureScarceResourcesAreAvailableInBiome(): void
    {
        // Anti-drift: каждый signature/scarce ресурс ДОЛЖЕН быть доступен в своём биоме
        // (resources.biome_id FIND_IN_SET), иначе модификатор — no-op. Проверяем по живой схеме.
        $db = Database::connect();
        if (! $db->tableExists('resources') || $db->table('resources')->countAllResults() === 0) {
            $this->markTestSkipped('resources table недоступна/пуста в этом окружении (anti-drift гоняется на testbot/prod)');
        }
        foreach (GatherBiomeProfiles::PROFILES as $biomeId => $p) {
            foreach (array_merge($p['signature'], $p['scarce']) as $name) {
                $row = $db->table('resources')->select('biome_id')->where('name', $name)->get()->getRowArray();
                $this->assertNotNull($row, "ресурс «{$name}» (биом {$biomeId}) не найден в resources");
                $csv = array_map('trim', explode(',', (string) ($row['biome_id'] ?? '')));
                $this->assertContains((string) $biomeId, $csv, "«{$name}» недоступен в биоме {$biomeId} (biome_id={$row['biome_id']}) → модификатор был бы no-op");
            }
        }
    }
}
