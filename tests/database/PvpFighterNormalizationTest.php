<?php

namespace Tests\Database;

use App\Entities\CharacterEntity;
use App\Services\PVE\PvpDamageCalculator;
use App\Services\PVE\PvpEquipmentRepository;
use App\Services\PVE\PvpFormulaService;
use App\Services\PVE\PvpRoundOrchestrator;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;

/**
 * Регресс-гейт прод-инцидента 2026-07-30: PvP не работал НИКОГДА.
 *
 * `battle_logs` на проде: 7641 запись с 2026-06-05, ВСЕ `PVE`, ни одного `PVP`.
 * За 30 дней 136 тапов «Атаковать»: 134 отбились о гейты (уровень/дистанция/
 * кулдаун), а оба, что дошли до симуляции, упали с
 * `TypeError: round(): Argument #1 ($num) must be of type int|float, string given`
 * в `PvpRoundOrchestrator:154`.
 *
 * ПРИЧИНА: персонаж приезжает из БД как `CharacterEntity`, а `toRawArray()`
 * ОБХОДИТ касты, объявленные в самой Entity (`health => float`, `level => integer`).
 * На выходе строки: `health = '52.95'`, `level = '17'`, `agility = '0.16'`.
 * Арифметике это безразлично, но боевые файлы под `declare(strict_types=1)`,
 * где `round('52.95', 2)` — TypeError.
 *
 * ПОЧЕМУ ЭТОГО НЕ ЛОВИЛИ ТЕСТЫ: все фикстуры (включая
 * [[AttackPlayerActionFixtureFenceTest]]) строят бойцов НАТИВНЫМИ типами
 * (`int $level`, `float $health`) — той формы, которой в проде не бывает.
 * Поэтому здесь бойцы собираются РОВНО как их отдаёт БД: всё строками.
 *
 * @internal
 */
final class PvpFighterNormalizationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const SEED = 42;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['characters_weapons', 'weapons', 'characters_outfits', 'outfits', 'map'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters_weapons (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, weapon_id INT NOT NULL, equipped TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE weapons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, damage_value DECIMAL(10,2) NULL, range_value DECIMAL(10,2) NULL, damage_type VARCHAR(50) NULL, rarity VARCHAR(50) NULL, special_effect VARCHAR(100) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE characters_outfits (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, outfit_id INT NOT NULL, equipped TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE outfits (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, physical_resistance DECIMAL(10,2) NULL DEFAULT 0, fire_resistance DECIMAL(10,2) NULL DEFAULT 0, poison_resistance DECIMAL(10,2) NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE map (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, biome_id INT NOT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (100, 10, 10, 1)');
        $db->query('INSERT INTO map (cell_number, coordinate_x, coordinate_y, biome_id) VALUES (101, 11, 10, 1)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['characters_weapons', 'weapons', 'characters_outfits', 'outfits', 'map'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function orchestrator(): PvpRoundOrchestrator
    {
        $formulas = new PvpFormulaService();
        $repo     = new PvpEquipmentRepository(
            new \App\Models\CharactersWeaponsModel(),
            new \App\Models\WeaponModel(),
            new \App\Models\CharactersOutfitsModel(),
            new \App\Models\OutfitModel(),
            new \App\Models\MapModel(),
            new \App\Models\CharacterFactionModel(),
            new \App\Models\FactionModel()
        );

        return new PvpRoundOrchestrator(new PvpDamageCalculator($formulas, $repo), $formulas);
    }

    /**
     * Боец с нативными типами — форма, которую использовали все прежние фикстуры.
     *
     * @return array<string, mixed>
     */
    private function nativeFighter(int $id, string $name, int $level, int $cell): array
    {
        return [
            'id'               => $id,
            'name'             => $name,
            'level'            => $level,
            'health'           => 100.0,
            'max_health'       => 100.0,
            'tired'            => 100.0,
            'max_tired'        => 100.0,
            'strength'         => 50.0,
            'agility'          => 50.0,
            'intellect'        => 50.0,
            'experience'       => 1000.0,
            'gold'             => 100,
            'cell_number'      => $cell,
            'telegram_user_id' => $id + 1000,
        ];
    }

    /**
     * Тот же боец РОВНО в той форме, в какой его отдаёт прод:
     * `CharacterEntity::toRawArray()` поверх MySQLi → все числа строками
     * (`decimal(5,2)` MySQLi возвращает строкой всегда).
     *
     * @return array<string, mixed>
     */
    private function dbShapedFighter(int $id, string $name, int $level, int $cell): array
    {
        $out = [];
        foreach ($this->nativeFighter($id, $name, $level, $cell) as $k => $v) {
            $out[$k] = $k === 'name' ? $v : (is_float($v) ? number_format($v, 2, '.', '') : (string) $v);
        }

        return $out;
    }

    /**
     * 🔴 Сам инцидент: бойцы из БД (строки) — бой обязан пройти, а не упасть.
     */
    public function testSimulateFightSurvivesDbShapedStringValues(): void
    {
        $p1    = $this->dbShapedFighter(1, 'Alice', 50, 100);
        $p2    = $this->dbShapedFighter(2, 'Bob', 50, 101);
        $biome = ['id' => 1, 'name' => 'Forest', 'danger_level' => 1];

        $this->assertIsString($p1['health'], 'Фикстура обязана быть строковой — иначе тест бесполезен');

        mt_srand(self::SEED);
        $result = $this->orchestrator()->simulateFight($p1, $p2, $biome);

        $this->assertContains($result['type'], ['normal', 'exhausted']);
        $this->assertGreaterThan(0, $result['rounds']);
        $this->assertNotEmpty($result['roundLogs']);
    }

    /**
     * Нормализация не должна двигать RNG-fence: тот же seed → тот же бой,
     * независимо от того, пришли числа строками или нативными.
     */
    public function testStringAndNativeFightersProduceIdenticalFight(): void
    {
        $biome = ['id' => 1, 'name' => 'Forest', 'danger_level' => 1];

        mt_srand(self::SEED);
        $native = $this->orchestrator()->simulateFight(
            $this->nativeFighter(1, 'Alice', 50, 100),
            $this->nativeFighter(2, 'Bob', 50, 101),
            $biome
        );

        mt_srand(self::SEED);
        $strings = $this->orchestrator()->simulateFight(
            $this->dbShapedFighter(1, 'Alice', 50, 100),
            $this->dbShapedFighter(2, 'Bob', 50, 101),
            $biome
        );

        $this->assertSame($native['type'], $strings['type']);
        $this->assertSame($native['rounds'], $strings['rounds']);
        $this->assertSame($native['firstAttacker'], $strings['firstAttacker']);
        $this->assertEquals($native['roundLogs'], $strings['roundLogs'], 'RNG-fence сдвинулся');
    }

    /** Конвертируем только числовые строки — и только в объявленных полях. */
    public function testNormalizeFighterConvertsOnlyDeclaredNumericStrings(): void
    {
        $out = PvpRoundOrchestrator::normalizeFighter([
            'id'      => '491',
            'health'  => '52.95',
            'level'   => '17',
            'agility' => '0.16',
            'tired'   => null,
            'name'    => '12345',
            'faction' => 'roamers',
        ]);

        $this->assertSame(491, $out['id']);
        $this->assertSame(52.95, $out['health']);
        $this->assertSame(17, $out['level']);
        $this->assertSame(0.16, $out['agility']);
        $this->assertNull($out['tired'], 'nullable-колонка обязана остаться null');
        $this->assertSame('12345', $out['name'], 'Имя из одних цифр — не число');
        $this->assertSame('roamers', $out['faction']);
    }

    /** Отсутствующие ключи не должны появляться из ниоткуда. */
    public function testNormalizeFighterDoesNotInventKeys(): void
    {
        $out = PvpRoundOrchestrator::normalizeFighter(['id' => '7']);

        $this->assertSame(['id' => 7], $out);
    }

    /**
     * Анти-дрейф: если в `CharacterEntity` появится новый числовой каст, он
     * обязан попасть и в список нормализации — иначе новое поле поедет в бой
     * строкой и воспроизведёт этот же инцидент.
     */
    public function testCoversEveryNumericCastOfCharacterEntity(): void
    {
        $rc = new ReflectionClass(CharacterEntity::class);
        $rp = $rc->getProperty('casts');
        $rp->setAccessible(true);
        /** @var array<string, string> $casts */
        $casts = $rp->getValue(new CharacterEntity());

        $rcOrch = new ReflectionClass(PvpRoundOrchestrator::class);
        /** @var array<string, string> $covered */
        $covered = $rcOrch->getConstant('NUMERIC_FIELDS');

        $missing = [];
        foreach ($casts as $field => $cast) {
            $bare = ltrim($cast, '?');
            if (!in_array($bare, ['integer', 'int', 'float', 'double'], true)) {
                continue;
            }
            if (!array_key_exists($field, $covered)) {
                $missing[] = "{$field} ({$cast})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Новые числовые касты CharacterEntity не покрыты PvpRoundOrchestrator::NUMERIC_FIELDS: '
            . implode(', ', $missing)
        );
    }
}
