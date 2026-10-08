<?php

declare(strict_types=1);

use App\Database\Migrations\DuelBaselineWeaponSettings;
use App\Services\PVE\DuelEquipmentRepository;
use App\Services\PVE\DuelService;
use App\Services\PVE\PvpDamageCalculator;
use App\Services\PVE\PvpEquipmentRepository;
use App\Services\PVE\PvpFormulaService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * duel-baseline-weapon — исход дуэли решает бой (docs/defects/duel-outcome-not-decided-by-fight.md).
 *
 * Настройки — из настоящих миграций (W17 + duel-baseline-weapon), бой — `DuelService::prepare()` + `simulate()`,
 * тот же путь, что у арены (настоящие `PvpRoundOrchestrator` и `PvpDamageCalculator`), снаряжение — заглушка без БД
 * под `DuelEquipmentRepository`. Бойцы стоят на реальных
 * клетках в 457 клетках друг от друга (как 491 и 522 на testbot) — дуэль ставит их на одну площадку.
 * Доли побед меряются на сериях `mt_srand` (N боёв на сценарий), границы — с допуском вокруг модели.
 *
 * @internal
 */
final class DuelOutcomeTest extends CIUnitTestCase
{
    private const MIGRATIONS = [
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-06-04-240000_W17SeedPvpDuelGameSettings',
    ];

    private const N = 600;

    /** Клетки 491 и 522 на testbot: (328, 453) и (312, 910). */
    private const CELL_A = 328454;
    private const CELL_B = 312911;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->conn->query('DROP TABLE IF EXISTS game_settings');
        $forge = Database::forge();
        foreach (self::MIGRATIONS as $file) {
            require_once APPPATH . 'Database/Migrations/' . $file . '.php';
            $class = 'App\\Database\\Migrations\\' . substr($file, 18);
            $m     = new $class($forge instanceof Forge ? $forge : null);
            $this->assertInstanceOf(Migration::class, $m);
            $m->up();
        }
        $this->conn->resetDataCache();
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        $this->conn->query('DROP TABLE IF EXISTS game_settings');
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    // ── миграция настроек ───────────────────────────────────────────────────

    public function testMigrationSeedsDefaultsThatTheCodeReads(): void
    {
        $this->migrate();
        $duels = new DuelService();

        $this->assertSame(DuelService::DEFAULT_HEALTH, $duels->baselineHealth());
        $this->assertSame(DuelService::DEFAULT_WEAPON_DAMAGE, $duels->baselineWeaponDamage());
        $this->assertSame(DuelService::DEFAULT_WEAPON_WEIGHT, $duels->weaponAdvantageWeight());
        $this->assertSame(DuelService::DEFAULT_DODGE_PERCENT, $duels->dodgePercent());
        $this->assertSame([200, 10.0, 0.5, 40.0], [
            DuelService::DEFAULT_HEALTH, DuelService::DEFAULT_WEAPON_DAMAGE,
            DuelService::DEFAULT_WEAPON_WEIGHT, DuelService::DEFAULT_DODGE_PERCENT,
        ]);

        foreach (['pvp.duel.baseline_weapon_damage', 'pvp.duel.weapon_advantage_weight', 'pvp.duel.dodge_percent', 'pvp.duel.baseline_health'] as $key) {
            $row = $this->row($key);
            $this->assertSame('combat', $row['category'], $key);
            foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text', 'default_value_text', 'recommended_min', 'recommended_max', 'hard_min', 'hard_max'] as $f) {
                $this->assertIsString($row[$f], "{$key}.{$f}");
                $this->assertNotSame('', trim($row[$f]), "{$key}.{$f}");
            }
            $value = (float) $row['default_value_text'];
            $this->assertGreaterThanOrEqual((float) $row['recommended_min'], $value, "{$key}: умолчание в мягких границах");
            $this->assertLessThanOrEqual((float) $row['recommended_max'], $value, "{$key}: умолчание в мягких границах");
            $this->assertGreaterThanOrEqual((float) $row['hard_min'], (float) $row['recommended_min'], $key);
            $this->assertLessThanOrEqual((float) $row['hard_max'], (float) $row['recommended_max'], $key);
        }
        $this->assertStringNotContainsString('agility', (string) $this->row('pvp.duel.baseline_stat')['effect_text']);
    }

    public function testMigrationIsIdempotentAndRollsBack(): void
    {
        $this->migrate();
        $this->migrate();
        $count = $this->conn->query("SELECT COUNT(*) AS n FROM game_settings WHERE setting_key LIKE 'pvp.duel.%'")->getRowArray();
        $this->assertSame(7, (int) ($count['n'] ?? 0));

        (new DuelBaselineWeaponSettings(Database::forge()))->down();
        $this->assertNull($this->conn->table('game_settings')->where('setting_key', 'pvp.duel.dodge_percent')->get()->getRowArray());
        $health = $this->row('pvp.duel.baseline_health');
        $this->assertSame(1000, (int) $health['value_int']);
        $this->assertSame('1000', $health['default_value_text']);
    }

    public function testHealthTunedFromAdminIsKept(): void
    {
        $this->conn->table('game_settings')->where('setting_key', 'pvp.duel.baseline_health')
            ->update(['value_int' => 1000, 'updated_by' => 'owner@admin']);
        $this->migrate();

        $health = $this->row('pvp.duel.baseline_health');
        $this->assertSame(1000, (int) $health['value_int'], 'ручная настройка владельца не перезаписывается');
        $this->assertSame('200', $health['default_value_text'], 'умолчание для «Сбросить» — новое');
    }

    // ── площадка, базовое оружие, уворот ────────────────────────────────────

    public function testDistanceBetweenRealCellsDoesNotCutDuelDamage(): void
    {
        $this->migrate();
        $duels = new DuelService();
        $calc  = new PvpDamageCalculator(new PvpFormulaService(), $this->duelRepo([]));

        [$a, $b] = $this->pair($duels, true);
        $this->assertSame($a['cell_number'], $b['cell_number']);
        mt_srand(1);
        $this->assertEqualsWithDelta(10.0, $calc->computeEquipmentDamage($a, $b), 1e-9, 'безоружный бьёт базовым, без деления на дистанцию');

        // Тот же бой на реальных клетках (как до фикса) — урон делится на ≈457 клеток.
        [$fa, $fb] = $this->pair($duels, false);
        mt_srand(1);
        $this->assertLessThan(0.1, $calc->computeEquipmentDamage($fa, $fb));
    }

    public function testWeakerWeaponHitsWithBaseAndStrongerOnlyWithWeightedEdge(): void
    {
        $f = new PvpFormulaService();
        $this->assertSame(10.0, DuelEquipmentRepository::duelWeapon(null, 10.0, 0.5, $f)['damage_value']);
        $this->assertSame(10.0, DuelEquipmentRepository::duelWeapon($this->weapon(6.0, 'Common'), 10.0, 0.5, $f)['damage_value']);
        // Rare 24: 24 × 1.3 = 31.2 → 10 + 0.5 × 21.2 = 20.6; тип и крит своего оружия сохраняются.
        $rare = DuelEquipmentRepository::duelWeapon($this->weapon(24.0, 'Rare', 'Fire', 10.0), 10.0, 0.5, $f);
        $this->assertEqualsWithDelta(20.6, $rare['damage_value'], 1e-9);
        $this->assertSame('Fire', $rare['damage_type']);
        $this->assertSame(10.0, $rare['crit_chance']);
        $this->assertSame('Common', $rare['rarity'], 'редкость уже в уроне');
        $this->assertEqualsWithDelta(31.2, DuelEquipmentRepository::duelWeapon($this->weapon(24.0, 'Rare'), 10.0, 1.0, $f)['damage_value'], 1e-9);
        $this->assertSame(10.0, DuelEquipmentRepository::duelWeapon($this->weapon(24.0, 'Rare'), 10.0, 0.0, $f)['damage_value']);
    }

    public function testDuelDodgeEqualsTheSetting(): void
    {
        $this->migrate();
        $duels = new DuelService();
        $eq    = $duels->equalize(['id' => 1, 'agility' => 0.01], 5);
        $this->assertEqualsWithDelta($duels->dodgePercent(), (new PvpFormulaService())->getDodgeChance($eq), 1e-9);
    }

    public function testDuelRepositoryOverridesEveryPublicMethodOfTheParent(): void
    {
        foreach ((new ReflectionClass(PvpEquipmentRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isConstructor() || $m->isStatic()) {
                continue;
            }
            $this->assertSame(
                DuelEquipmentRepository::class,
                (new ReflectionMethod(DuelEquipmentRepository::class, $m->getName()))->getDeclaringClass()->getName(),
                "{$m->getName()}: родительский конструктор не вызывается — метод обязан делегировать"
            );
        }
    }

    // ── исход на настоящем движке ───────────────────────────────────────────

    public function testTwoUnarmedNewcomersEndInKnockoutNotSeniority(): void
    {
        $this->migrate();
        $stats = $this->series([]);

        $this->assertSame(self::N, $stats['ko'], 'каждый бой — нокаут');
        $this->assertLessThan(150, $stats['median_rounds']);
        $this->assertSame(0, $stats['reasons']['seniority'] ?? 0, 'стаж не решает');
        $this->assertGreaterThanOrEqual(0.40, $stats['challenger_rate'], 'равные: первый удар вызывающего не решает исход');
        $this->assertLessThanOrEqual(0.60, $stats['challenger_rate']);
    }

    public function testWeaponOnlyOnOneSideStillKnocksOutAndWins(): void
    {
        $this->migrate();
        $stats = $this->series(['armed' => $this->weapon(24.0, 'Rare')]);

        $this->assertSame(self::N, $stats['ko']);
        $this->assertGreaterThanOrEqual(0.95, $stats['armed_rate']);
    }

    public function testSmallEdgeGivesAChanceAndDoubleEdgeUsuallyWins(): void
    {
        $this->migrate();
        $small = $this->series(['armed' => $this->weapon(11.0, 'Common')]);
        $this->assertGreaterThanOrEqual(0.50, $small['armed_rate'], '+10 %: не меньше половины');
        $this->assertLessThanOrEqual(0.65, $small['armed_rate'], '+10 %: не гарантия');

        $double = $this->series(['armed' => $this->weapon(20.0, 'Common')]);
        $this->assertGreaterThanOrEqual(0.88, $double['armed_rate']);
        $this->assertLessThanOrEqual(0.98, $double['armed_rate']);
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function migrate(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-18-100000_DuelBaselineWeaponSettings.php';
        (new DuelBaselineWeaponSettings(Database::forge()))->up();
        service('cache')->clean();
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        $row = $this->conn->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        $this->assertIsArray($row, $key);

        return $row;
    }

    /** @return array<string, mixed> */
    private function weapon(float $damage, string $rarity, string $type = 'Physical', float $crit = 0.0): array
    {
        return ['damage_value' => $damage, 'range_value' => 1.0, 'damage_type' => $type, 'rarity' => $rarity, 'crit_chance' => $crit];
    }

    /**
     * @param array<int, array<string, mixed>> $weapons оружие по id бойца
     */
    private function duelRepo(array $weapons): DuelEquipmentRepository
    {
        $duels = new DuelService();

        return new DuelEquipmentRepository($this->stubRepo($weapons), $duels->baselineWeaponDamage(), $duels->weaponAdvantageWeight());
    }

    /**
     * @param array<int, array<string, mixed>> $weapons
     */
    private function stubRepo(array $weapons): PvpEquipmentRepository
    {
        return new class ($weapons) extends PvpEquipmentRepository {
            /** @param array<int, array<string, mixed>> $weapons */
            public function __construct(private readonly array $weapons)
            {
            }

            public function getEquippedWeapon(int $characterId): ?array
            {
                return $this->weapons[$characterId] ?? null;
            }

            public function getEquippedOutfitsWithDetails(int $characterId): array
            {
                return [];
            }

            public function getMapCell(int $cellNumber): ?array
            {
                return match ($cellNumber) {
                    328454 => ['coordinate_x' => 328, 'coordinate_y' => 453],
                    312911 => ['coordinate_x' => 312, 'coordinate_y' => 910],
                    default => null,
                };
            }

            public function getCharacterFaction(int $characterId): ?array
            {
                return null;
            }
        };
    }

    /**
     * Два новичка с реальных клеток: 1 — вызывающий, 2 — защитник. `$onePlace` — как в дуэли (клетка вызывающего).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function pair(DuelService $duels, bool $onePlace): array
    {
        $a = ['id' => 1, 'name' => 'Вызов', 'level' => 1, 'strength' => 0.5, 'agility' => 0.01, 'intellect' => 0.01, 'health' => 90, 'tired' => 10, 'cell_number' => self::CELL_A, 'created_at' => '2026-01-01 00:00:00'];
        $b = ['id' => 2, 'name' => 'Ответ', 'level' => 1, 'strength' => 0.5, 'agility' => 0.01, 'intellect' => 0.01, 'health' => 90, 'tired' => 10, 'cell_number' => self::CELL_B, 'created_at' => '2026-02-01 00:00:00'];

        return $onePlace ? $duels->prepare($a, $b) : [$duels->equalize($a), $duels->equalize($b)];
    }

    /**
     * N дуэлей на настоящем движке. `armed` — оружие одного бойца: в чётных боях оно у вызывающего, в нечётных — у
     * защитника (первый удар при равной инициативе за вызывающим — так он не подмешивается в долю оружия).
     *
     * @param array{armed?: array<string, mixed>} $opts
     *
     * @return array{ko: int, median_rounds: int, reasons: array<string, int>, challenger_rate: float, armed_rate: float}
     */
    private function series(array $opts): array
    {
        $duels    = new DuelService();
        $ko       = 0;
        $rounds   = [];
        $reasons  = [];
        $chWins   = 0;
        $armWins  = 0;

        for ($i = 0; $i < self::N; $i++) {
            $armedId = $i % 2 === 0 ? 1 : 2;
            $weapons = isset($opts['armed']) ? [$armedId => $opts['armed']] : [];
            [$a, $b] = $this->pair($duels, true);

            mt_srand(1000 + $i);
            $result = $duels->simulate($a, $b, ['name' => 'Поля', 'danger_level' => 1 + $i % 3], $this->stubRepo($weapons));
            $res    = $duels->resolveDuel($result, $a, $b);

            $ko += ($result['type'] ?? '') === 'exhausted' ? 0 : 1;
            $rounds[] = is_numeric($result['rounds'] ?? null) ? (int) $result['rounds'] : 0;
            $reasons[$res['reason']] = ($reasons[$res['reason']] ?? 0) + 1;
            $chWins  += $res['winnerId'] === 1 ? 1 : 0;
            $armWins += $res['winnerId'] === $armedId ? 1 : 0;
        }
        sort($rounds);

        return [
            'ko'              => $ko,
            'median_rounds'   => $rounds[intdiv(count($rounds), 2)],
            'reasons'         => $reasons,
            'challenger_rate' => $chWins / self::N,
            'armed_rate'      => $armWins / self::N,
        ];
    }
}
