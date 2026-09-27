<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\World\LiveMapService;
use App\Services\World\MoveSurfaceService;
use App\Services\World\TextMapService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;

/**
 * W2.N2-01 (ADR-190) — модель экрана «Мир»: окно 12×12 со смещением 6, маркеры по приоритетной
 * лестнице прежнего `TextMapService`, расстояние до ближайшей базы, статы, действия, легенда.
 *
 * Паритет: {@see self::GRID_BEFORE} — вывод `TextMapService::buildMapOnly()` на этой фикстуре,
 * снятый с кода ДО переноса лестницы в модель. Модель обязана дать те же маркеры, строка бота —
 * тот же текст.
 *
 * Схема — из миграций; `npc_spawns` создающей миграции не имеет (legacy-дамп), поэтому её DDL —
 * копия прод-таблицы без FK на `npcs`. Флаги поселений, узлов и приманки — выключены (пустой
 * `game_settings`), их слои здесь пусты.
 *
 * Фикстура: игрок на (3, 500) у западного края мира → окно x −3..8, y 494..505; столбцы x < 0 —
 * ⬜. Две свои базы в окне (одна на неоткрытой клетке), брошенная база не рисуется, чужая база на
 * открытой клетке 🚫 и на закрытой ⬛️, NPC рядом 🥷 и вдали (не рисуется), дыра в `map`, биом без
 * значка ❓.
 *
 * @internal
 */
final class LiveMapServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-24-212921_CreateExploredCellsTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'explored_cells', 'claimed_cells', 'game_settings', 'npc_spawns',
    ];

    private const CHAR     = 1;
    private const STRANGER = 2;

    /** Вывод `TextMapService::buildMapOnly()` на фикстуре до правки (снят с прежнего кода). */
    private const GRID_BEFORE = "⬜⬜⬜🏜️🕳️⬛️❄️🌲🌋⬛️🌊⬛️
"
        . "⬜⬜⬜🌲⬛️🌾🌊⛰️⬛️🕳️🌴❄️
"
        . "⬜⬜⬜⬛️🏜️🕳️🌴⬛️🌲🌋🌾⬛️
"
        . "⬜⬜⬜❄️🌲🌋⬛️🌊⛰️🏜️⬛️🌴
"
        . "⬜⬜⬜🌊⛰️⬛️🕳️🌴🏕⬛️🌋🌾
"
        . "⬜⬜⬜🌴⬛️🌲🌋🌾⬛️⛰️🚫🕳️
"
        . "⬜⬜⬜⬛️🌊❓🙎‍♂️🥷🌴❄️🌲⬛️
"
        . "⬜⬜⬜🕳️🌴❄️⬛️🌋🌾🌊⬛️🏜️
"
        . "⬜⬜⬜🌋🌾⬛️⛰️🏜️🕳️⬛️❄️🌲
"
        . "⬜⬜⬜🏜️🏕🌴❄️🌲⬛️🌾🌊⛰️
"
        . "⬜⬜⬜⬛️🌋🌾🌊⬛️🏜️🕳️🌴⬛️
"
        . "⬜⬜⬜⛰️🏜️🕳️⬛️❄️🌲🌋⬛️🌊
";

    /** Текст «🌍 Мир» бота до правки, world_hub=off (снят с прежнего кода). */
    private const SURFACE_PLAIN = 'Куда пойдём? Выберите направление с клавиатуры ниже:' . "\n"
        . "\n"
        . 'Легенда:' . "\n"
        . '🙎‍♂️ — игрок' . "\n"
        . '🏕 — ваша база' . "\n"
        . '🚫 — чужая база' . "\n"
        . '🏚 — поселение' . "\n"
        . '☠ — узел (босс)' . "\n"
        . '⏳ — узел в кулдауне' . "\n"
        . '🥷 — NPC' . "\n"
        . '⬛️ — не изучено' . "\n"
        . '⬜ — за пределами мира' . "\n"
        . "\n"
        . '1) 🌲 — Лес' . "\n"
        . '2) ⛰️ — Горы' . "\n"
        . '3) ❄️ — Тундра' . "\n"
        . '4) 🌊 — Реки' . "\n"
        . '5) 🌴 — Джунгли' . "\n"
        . '6) 🌾 — Поля' . "\n"
        . '7) 🕳️ — Пещеры' . "\n"
        . '8) 🌋 — Вулкан' . "\n"
        . '9) 🏜️ — Пустыни' . "\n"
        . "\n"
        . 'От 🙎‍♂️ до 🏕 = 2 ходов ↗️' . "\n"
        . "\n"
        . '❤️ Здоровье: 88' . "\n"
        . '💤 Усталость: 12' . "\n"
        . "\n"
        . '⬜⬜⬜🏜️🕳️⬛️❄️🌲🌋⬛️🌊⬛️' . "\n"
        . '⬜⬜⬜🌲⬛️🌾🌊⛰️⬛️🕳️🌴❄️' . "\n"
        . '⬜⬜⬜⬛️🏜️🕳️🌴⬛️🌲🌋🌾⬛️' . "\n"
        . '⬜⬜⬜❄️🌲🌋⬛️🌊⛰️🏜️⬛️🌴' . "\n"
        . '⬜⬜⬜🌊⛰️⬛️🕳️🌴🏕⬛️🌋🌾' . "\n"
        . '⬜⬜⬜🌴⬛️🌲🌋🌾⬛️⛰️🚫🕳️' . "\n"
        . '⬜⬜⬜⬛️🌊❓🙎‍♂️🥷🌴❄️🌲⬛️' . "\n"
        . '⬜⬜⬜🕳️🌴❄️⬛️🌋🌾🌊⬛️🏜️' . "\n"
        . '⬜⬜⬜🌋🌾⬛️⛰️🏜️🕳️⬛️❄️🌲' . "\n"
        . '⬜⬜⬜🏜️🏕🌴❄️🌲⬛️🌾🌊⛰️' . "\n"
        . '⬜⬜⬜⬛️🌋🌾🌊⬛️🏜️🕳️🌴⬛️' . "\n"
        . '⬜⬜⬜⛰️🏜️🕳️⬛️❄️🌲🌋⬛️🌊' . "\n"
        . "\n"
        . 'Игрок по центру (X=3, Y=500)' . "\n"
        . '';

    private const KEYBOARD_PLAIN = '[[{"text":"↖️ Сев-Запад","callback_data":"move_dir_northwest"},{"text":"⬆️ Север","callback_data":"move_dir_north"},{"text":"↗️ Сев-Восток","callback_data":"move_dir_northeast"}],[{"text":"⬅️ Запад","callback_data":"move_dir_west"},{"text":"🏠 База","callback_data":"Base"},{"text":"🧑‍🌾 🛠️","callback_data":"characterActions"},{"text":"➡️ Восток","callback_data":"move_dir_east"}],[{"text":"↙️ Юго-Запад","callback_data":"move_dir_southwest"},{"text":"⬇️ Юг","callback_data":"move_dir_south"},{"text":"↘️ Юго-Восток","callback_data":"move_dir_southeast"}],[{"text":"🗺️ Поход","callback_data":"march"}],[{"text":"🌍 Остров живёт","callback_data":"island"}]]';

    /** Текст «🌍 Мир» бота до правки, world_hub=on (снят с прежнего кода). */
    private const SURFACE_HUB = 'Куда пойдём? Выберите направление с клавиатуры ниже:' . "\n"
        . "\n"
        . 'От 🙎‍♂️ до 🏕 = 2 ходов ↗️' . "\n"
        . "\n"
        . '❤️ Здоровье: 88' . "\n"
        . '💤 Усталость: 12' . "\n"
        . "\n"
        . '⬜⬜⬜🏜️🕳️⬛️❄️🌲🌋⬛️🌊⬛️' . "\n"
        . '⬜⬜⬜🌲⬛️🌾🌊⛰️⬛️🕳️🌴❄️' . "\n"
        . '⬜⬜⬜⬛️🏜️🕳️🌴⬛️🌲🌋🌾⬛️' . "\n"
        . '⬜⬜⬜❄️🌲🌋⬛️🌊⛰️🏜️⬛️🌴' . "\n"
        . '⬜⬜⬜🌊⛰️⬛️🕳️🌴🏕⬛️🌋🌾' . "\n"
        . '⬜⬜⬜🌴⬛️🌲🌋🌾⬛️⛰️🚫🕳️' . "\n"
        . '⬜⬜⬜⬛️🌊❓🙎‍♂️🥷🌴❄️🌲⬛️' . "\n"
        . '⬜⬜⬜🕳️🌴❄️⬛️🌋🌾🌊⬛️🏜️' . "\n"
        . '⬜⬜⬜🌋🌾⬛️⛰️🏜️🕳️⬛️❄️🌲' . "\n"
        . '⬜⬜⬜🏜️🏕🌴❄️🌲⬛️🌾🌊⛰️' . "\n"
        . '⬜⬜⬜⬛️🌋🌾🌊⬛️🏜️🕳️🌴⬛️' . "\n"
        . '⬜⬜⬜⛰️🏜️🕳️⬛️❄️🌲🌋⬛️🌊' . "\n"
        . "\n"
        . 'Игрок по центру (X=3, Y=500)' . "\n"
        . '';

    private const KEYBOARD_HUB = '[[{"text":"↖️ Сев-Запад","callback_data":"move_dir_northwest"},{"text":"⬆️ Север","callback_data":"move_dir_north"},{"text":"↗️ Сев-Восток","callback_data":"move_dir_northeast"}],[{"text":"⬅️ Запад","callback_data":"move_dir_west"},{"text":"🏠 База","callback_data":"Base"},{"text":"🧑‍🌾 🛠️","callback_data":"characterActions"},{"text":"➡️ Восток","callback_data":"move_dir_east"}],[{"text":"↙️ Юго-Запад","callback_data":"move_dir_southwest"},{"text":"⬇️ Юг","callback_data":"move_dir_south"},{"text":"↘️ Юго-Восток","callback_data":"move_dir_southeast"}],[{"text":"🗺️ Поход","callback_data":"march"},{"text":"❓ Легенда","callback_data":"mapLegend"},{"text":"🗺 Обзор","callback_data":"mapOverview"}],[{"text":"🌍 Остров живёт","callback_data":"island"},{"text":"🎉 События","callback_data":"events"}]]';

    private BaseConnection $conn;

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
            $this->conn->query(
                'CREATE TABLE `npc_spawns` ('
                . ' `id` int unsigned NOT NULL AUTO_INCREMENT, `npc_id` int unsigned NOT NULL, `cell_number` int NOT NULL,'
                . ' `coordinate_x` int NOT NULL, `coordinate_y` int NOT NULL,'
                . " `current_health` decimal(7,2) NOT NULL DEFAULT '100.00', `spawned_at` datetime DEFAULT CURRENT_TIMESTAMP,"
                . " `status` varchar(20) NOT NULL DEFAULT 'alive',"
                . ' `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . ' PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->mockCache();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('cache');
        $this->dropTables();
        parent::tearDown();
    }

    public function testBotGridIsUnchangedByTheModel(): void
    {
        $this->assertSame(self::GRID_BEFORE, (new TextMapService())->buildMapOnly($this->characterRow()));
    }

    public function testModelWindowIs12x12AroundThePlayer(): void
    {
        $model = (new LiveMapService())->forCharacter(self::CHAR);

        $this->assertNull($model['error']);
        $this->assertSame(['x' => 3, 'y' => 500, 'biome' => $this->biome(3, 500)], $model['center']);
        $this->assertSame(['x0' => -3, 'y0' => 494, 'size' => 12], $model['window']);
        $this->assertCount(12, $model['cells']);
        foreach ($model['cells'] as $r => $row) {
            $this->assertCount(12, $row);
            foreach ($row as $c => $cell) {
                $this->assertSame(-3 + $c, $cell['x']);
                $this->assertSame(494 + $r, $cell['y']);
            }
        }
    }

    public function testEveryCellCarriesTheSameMarkerAsTheBotLine(): void
    {
        $model = (new LiveMapService())->forCharacter(self::CHAR);

        $lines = [];
        foreach ($model['cells'] as $row) {
            $lines[] = implode('', array_map(static fn (array $cell): string => $cell['marker'], $row));
        }
        $this->assertSame(self::GRID_BEFORE, implode("\n", $lines) . "\n");
    }

    public function testCodesFollowThePriorityLadder(): void
    {
        $cells = (new LiveMapService())->forCharacter(self::CHAR)['cells'];
        $at    = static fn (int $x, int $y): array => $cells[$y - 494][$x + 3];

        $this->assertSame(LiveMapService::CODE_OUT, $at(-1, 500)['code'], 'за краем 0..999');
        $this->assertNull($at(-1, 500)['biome']);
        $this->assertSame(LiveMapService::CODE_PLAYER, $at(3, 500)['code']);
        $this->assertSame(LiveMapService::CODE_OWN_BASE, $at(5, 498)['code']);
        $this->assertSame(LiveMapService::CODE_OWN_BASE, $at(1, 503)['code'], 'своя база видна и на неоткрытой клетке');
        $this->assertFalse($at(1, 503)['explored']);
        $this->assertSame(LiveMapService::CODE_FOREIGN_BASE, $at(7, 499)['code'], 'чужая база на открытой клетке');
        $this->assertSame(LiveMapService::CODE_FOG, $at(4, 496)['code'], 'закрытая клетка — туман');
        $this->assertNull($at(4, 496)['biome'], 'биом закрытой клетки не выдаётся');
        $this->assertSame(LiveMapService::CODE_NPC, $at(4, 500)['code']);
        $this->assertSame(LiveMapService::CODE_BIOME, $at(8, 503)['code'], 'NPC вдали не рисуется');
        $this->assertSame(LiveMapService::CODE_FOG, $at(8, 494)['code'], 'дыра в map — туман');
        $this->assertSame(LiveMapService::CODE_BIOME, $at(2, 500)['code']);
        $this->assertSame(42, $at(2, 500)['biome']);
        $this->assertSame('❓', $at(2, 500)['marker']);
        $this->assertSame(LiveMapService::CODE_BIOME, $at(6, 496)['code'], 'брошенная база не рисуется');
        $this->assertTrue($at(6, 496)['explored']);
    }

    public function testForeignBaseOnClosedCellStaysFog(): void
    {
        $cells = (new LiveMapService())->forCharacter(self::CHAR)['cells'];

        $this->assertSame(LiveMapService::CODE_FOG, $cells[502 - 494][6 + 3]['code']);
    }

    public function testDistanceStatsActionsAndLegend(): void
    {
        $model = (new LiveMapService())->forCharacter(self::CHAR);

        $this->assertSame(['distance' => 2, 'x' => 5, 'y' => 498, 'arrow' => '↗️'], $model['distance_to_base']);
        $this->assertSame(['health' => 88.0, 'tired' => 12.0], $model['stats']);

        $dirs = array_values(array_filter($model['actions'], static fn (array $a): bool => $a['group'] === LiveMapService::GROUP_DIR));
        $this->assertCount(8, $dirs);
        $this->assertSame('move_dir_north', $dirs[1]['callback']);
        $this->assertContains('march', array_column($model['actions'], 'callback'));
        $this->assertContains('Base', array_column($model['actions'], 'callback'));

        $this->assertContains(['marker' => '🙎‍♂️', 'label' => 'игрок', 'biome' => null], $model['legend']);
        $this->assertContains(['marker' => '🌋', 'label' => 'Вулкан', 'biome' => 8], $model['legend']);
    }

    public function testDistanceLineOfTheBotIsTheModelDistance(): void
    {
        $this->assertSame("От 🙎‍♂️ до 🏕 = 2 ходов ↗️\n", (new TextMapService())->getDistanceLine($this->characterRow()));
    }

    public function testCharacterWithoutCellGivesErrorModel(): void
    {
        $this->conn->query('UPDATE characters SET cell_number = 0 WHERE id = ?', [self::CHAR]);

        $model = (new LiveMapService())->forCharacter(self::CHAR);

        $this->assertSame('Нет cell_number у персонажа', $model['error']);
        $this->assertSame([], $model['cells']);
        $this->assertNull($model['center']);
    }

    public function testBotSurfaceTextAndKeyboardAreUnchanged(): void
    {
        foreach ([false => [self::SURFACE_PLAIN, self::KEYBOARD_PLAIN], true => [self::SURFACE_HUB, self::KEYBOARD_HUB]] as $hub => [$text, $keyboard]) {
            $surface = $this->surface((bool) $hub);
            $this->assertSame($text, $surface->text($this->characterRow()), 'текст карты бота');
            $this->assertSame($keyboard, json_encode($surface->keyboard($this->characterRow()), JSON_UNESCAPED_UNICODE), 'роза и кнопки бота');
        }
    }

    // ── Фикстура ─────────────────────────────────────────────────────────

    /** Поверхность бота с зафиксированными флагами (seam вместо GameSettings). */
    private function surface(bool $worldHub): object
    {
        return new class ($worldHub) extends MoveSurfaceService {
            public function __construct(private readonly bool $hub)
            {
                parent::__construct();
            }

            protected function worldHubEnabled(): bool
            {
                return $this->hub;
            }

            protected function finalGridEnabled(): bool
            {
                return $this->hub;
            }

            /** @param array<string, mixed> $character */
            public function text(array $character): string
            {
                return $this->buildMapText($character, new \App\Services\World\TextMapService());
            }

            /**
             * @param array<string, mixed> $character
             *
             * @return array<int, array<int, array<string, string>>>
             */
            public function keyboard(array $character): array
            {
                return $this->buildDirectionsKeyboard(true, $character);
            }
        };
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        // Дрон выключен: иначе экран бота спросит владение дроном (таблиц крафта здесь нет).
        $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_bool) VALUES ('drone.scout.enabled', 'bool', 0)");
        for ($id = 1; $id <= 9; $id++) {
            $this->conn->query('INSERT INTO biomes (id, name) VALUES (?, ?)', [$id, 'b' . $id]);
        }
        $rows = [];
        for ($y = 494; $y <= 505; $y++) {
            for ($x = 0; $x <= 8; $x++) {
                if ($x === 8 && $y === 494) {
                    continue; // дыра в map
                }
                $rows[] = sprintf('(%d, %d, %d, %d, %d)', self::cell($x, $y), self::cell($x, $y), $x, $y, $this->biome($x, $y));
            }
        }
        $rows[] = sprintf('(%d, %d, 100, 100, 1)', self::cell(100, 100), self::cell(100, 100));
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES ' . implode(', ', $rows));

        $this->conn->query(
            "INSERT INTO characters (id, name, level, health, tired, cell_number) VALUES (?, 'Тест', 5, 87.5, 12, ?), (?, 'Чужак', 5, 100, 0, ?)",
            [self::CHAR, self::cell(3, 500), self::STRANGER, self::cell(100, 100)]
        );

        $explored = [];
        for ($y = 494; $y <= 505; $y++) {
            for ($x = 0; $x <= 8; $x++) {
                if (($x + $y) % 4 !== 0 || ($x === 8 && $y === 494)) {
                    $explored[] = sprintf('(%d, %d)', self::CHAR, self::cell($x, $y));
                }
            }
        }
        $this->conn->query('INSERT INTO explored_cells (character_id, map_cell_id) VALUES ' . implode(', ', $explored));

        foreach ([
            [self::CHAR, 5, 498, 'active'],      // открытая клетка
            [self::CHAR, 1, 503, 'active'],      // неоткрытая клетка
            [self::CHAR, 6, 496, 'abandoned'],   // брошенная — не рисуется
            [self::CHAR, 100, 100, 'active'],    // вне окна — дальше ближайшей
            [self::STRANGER, 7, 499, 'active'],  // открытая → 🚫
            [self::STRANGER, 6, 502, 'active'],  // неоткрытая → ⬛️
        ] as [$char, $x, $y, $status]) {
            $this->conn->query(
                'INSERT INTO claimed_cells (character_id, map_cell_id, claimed_at, status) VALUES (?, ?, ?, ?)',
                [$char, self::cell($x, $y), $now, $status]
            );
        }

        foreach ([[4, 500, 'alive'], [8, 503, 'alive'], [2, 499, 'dead'], [3, 500, 'alive']] as [$x, $y, $status]) {
            $this->conn->query(
                'INSERT INTO npc_spawns (npc_id, cell_number, coordinate_x, coordinate_y, status) VALUES (1, ?, ?, ?, ?)',
                [self::cell($x, $y), $x, $y, $status]
            );
        }
    }

    private static function cell(int $x, int $y): int
    {
        return $y * 1000 + $x + 1;
    }

    private function biome(int $x, int $y): int
    {
        return $x === 2 && $y === 500 ? 42 : (($x * 7 + $y) % 9) + 1;
    }

    /** @return array<string, mixed> */
    private function characterRow(): array
    {
        $row = $this->conn->query('SELECT * FROM characters WHERE id = ?', [self::CHAR])->getRowArray();
        $this->assertIsArray($row);

        return $row;
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
