<?php

declare(strict_types=1);

namespace Tests\Unit\Camp {

    // Переиспользуем процесс-широкий namespaced `fopen()`-шим из `BuildingCardBaseScopeTest`
    // (`Request::encodeFile(base_url(...))` иначе полез бы в сеть). Паттерн `BuildingCardBaseChoiceTest`.
    if (! class_exists(BuildingCardBaseScopeTest::class, false)) {
        require_once __DIR__ . '/BuildingCardBaseScopeTest.php';
    }

    use App\Controllers\Telegram\Commands\Actions\Camp\DetailedBaseInfoAction;
    use App\Services\Bases\BaseScopeResolver;
    use CodeIgniter\Database\BaseConnection;
    use CodeIgniter\Test\CIUnitTestCase;
    use CodeIgniter\Test\DatabaseTestTrait;
    use Config\Database;
    use Longman\TelegramBot\Entities\CallbackQuery;
    use Longman\TelegramBot\Entities\ServerResponse;
    use Longman\TelegramBot\Telegram;

    /**
     * story multibase-picker-11 (совет, раунд 2, ask 3 RED) — голый `construction` вне базы
     * показывает и штампует ту базу, что выбирает `BaseScopeResolver::resolve()` (первая по id,
     * которую покрывает ЕЁ Вышка), с числами Вышки этой же базы. Отказ без покрытия — прежний,
     * байт в байт. Реальные сервисы, своя схема с префиксом `dbbc_`.
     *
     * Геометрия: клетки на линии y=0, x = номер клетки; игрок на клетке 300; база-1 на 0
     * (дистанция 300), база-2 на 1000 (дистанция 700); радиус = уровень Вышки × 100.
     *
     * @internal
     */
    final class DetailedBaseInfoBareConstructionTest extends CIUnitTestCase
    {
        use DatabaseTestTrait;

        protected $migrate = false;

        private const PREFIX = 'dbbc_';

        /** @var list<string> */
        private const TABLES = [
            'telegram_users', 'characters', 'buildings', 'character_buildings',
            'claimed_cells', 'map', 'biomes', 'game_settings',
        ];

        private string $origPrefix = '';

        protected function setUp(): void
        {
            parent::setUp();

            if (! defined('PHPUNIT_TESTSUITE')) {
                define('PHPUNIT_TESTSUITE', true);
            }
            new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

            BuildingCardBaseScopeTest::$fopenShimActive = true;
            $this->origPrefix = $this->db()->getPrefix();
            $this->db()->setPrefix(self::PREFIX);

            $prop = new \ReflectionProperty(\App\Models\BuildingModel::class, 'byNameEnCache');
            $prop->setAccessible(true);
            $prop->setValue(null, []);

            $ddl = [
                'telegram_users' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
                'characters' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, '
                    . 'name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, '
                    . 'level INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
                'buildings' => "id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, "
                    . "name_en VARCHAR(255) NULL, "
                    . "building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL, "
                    . 'hp INT NULL, usage_count INT NULL, description VARCHAR(255) NULL',
                'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, '
                    . 'building_id INT NULL, map_cell_id INT NULL, amount INT NULL DEFAULT 1, hp INT NULL, '
                    . 'level INT NULL DEFAULT 1, built_at DATETIME NULL, building_type VARCHAR(32) NULL, '
                    . 'tax INT NULL, `usage` VARCHAR(16) NULL, disappearance_date DATETIME NULL, '
                    . 'usage_count INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
                'claimed_cells' => "id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, "
                    . "map_cell_id INT NULL, camp_name VARCHAR(64) NULL, claimed_at DATETIME NULL, "
                    . "status VARCHAR(16) NULL DEFAULT 'active'",
                'map' => 'id INT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL, '
                    . 'coordinate_y INT NOT NULL, biome_id INT NULL',
                'biomes' => 'id INT PRIMARY KEY, name VARCHAR(64) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
                'game_settings' => 'id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(128) NOT NULL, '
                    . 'value_type VARCHAR(16) NOT NULL, value_int INT NULL, value_float DOUBLE NULL, '
                    . 'value_bool TINYINT NULL, value_string VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
            ];
            foreach ($ddl as $table => $cols) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
                $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) ENGINE=InnoDB");
            }

            $this->cleanCache();
        }

        protected function tearDown(): void
        {
            try {
                foreach (array_reverse(self::TABLES) as $table) {
                    $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
                }
            } finally {
                $this->db()->setPrefix($this->origPrefix);
                BuildingCardBaseScopeTest::$fopenShimActive = false;
                $this->cleanCache();
            }

            parent::tearDown();
        }

        private function db(): BaseConnection
        {
            return Database::connect('tests');
        }

        private function cleanCache(): void
        {
            $cache = service('cache');
            if (is_object($cache) && method_exists($cache, 'clean')) {
                $cache->clean();
            }
        }

        // ---- seeding ----

        /**
         * Две активные базы (0 и 1000), игрок на 300, HandPump L10 на базе-1 и L3 на базе-2.
         *
         * @return array{tgId:int, charId:int, base1:int, base2:int, pumpId:int, towerId:int}
         */
        private function seedWorld(): array
        {
            $this->db()->table('biomes')->insert(['id' => 1, 'name' => 'Лес']);
            foreach ([0, 300, 1000] as $cell) {
                $this->db()->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $cell, 'coordinate_y' => 0, 'biome_id' => 1]);
            }
            $this->db()->table('buildings')->insert(['name_ru' => 'Вышка связи', 'name_en' => 'CommunicationTower', 'building_type' => 'engineering', 'hp' => 100]);
            $towerId = (int) $this->db()->insertID();
            $this->db()->table('buildings')->insert(['name_ru' => 'Ручная скважина', 'name_en' => 'HandPump', 'building_type' => 'resource', 'hp' => 100]);
            $pumpId = (int) $this->db()->insertID();

            $tgId = random_int(730_000_000, 739_999_999);
            $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
            $tgUid = (int) $this->db()->insertID();
            $this->db()->table('characters')->insert(['telegram_user_id' => $tgUid, 'cell_number' => 300, 'locale' => 'ru']);
            $charId = (int) $this->db()->insertID();

            $base1 = $this->seedBase($charId, 0);
            $base2 = $this->seedBase($charId, 1000);
            $this->seedCharacterBuilding($charId, $pumpId, 0, 10);
            $this->seedCharacterBuilding($charId, $pumpId, 1000, 3);

            return ['tgId' => $tgId, 'charId' => $charId, 'base1' => $base1, 'base2' => $base2, 'pumpId' => $pumpId, 'towerId' => $towerId];
        }

        private function seedBase(int $charId, int $cell): int
        {
            $this->db()->table('claimed_cells')->insert([
                'character_id' => $charId, 'map_cell_id' => $cell,
                'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
            ]);

            return (int) $this->db()->insertID();
        }

        private function seedCharacterBuilding(int $charId, int $buildingId, int $cell, int $level): void
        {
            $this->db()->table('character_buildings')->insert([
                'character_id' => $charId, 'building_id' => $buildingId, 'map_cell_id' => $cell,
                'hp' => 100, 'level' => $level, 'built_at' => date('Y-m-d H:i:s'),
                'building_type' => 'resource', 'tax' => 10, 'usage' => 'personal',
            ]);
        }

        private function construction(int $tgId): ServerResponse
        {
            return (new DetailedBaseInfoAction(new CallbackQuery([
                'id'   => 'cbq_' . random_int(1, 999999999),
                'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
                'message' => [
                    'message_id' => 1, 'date' => time(),
                    'chat' => ['id' => $tgId, 'type' => 'private'],
                    'text' => 'placeholder',
                ],
                'chat_instance' => 'ci_' . $tgId,
                'data' => 'construction',
            ])))->handle();
        }

        private function textOf(ServerResponse $response): string
        {
            $result = $response->getResult();
            if (! is_object($result)) {
                return '';
            }
            $caption = $result->getCaption();
            if (is_string($caption) && $caption !== '') {
                return $caption;
            }
            $text = $result->getText();

            return is_string($text) ? $text : '';
        }

        /** @return list<array{text:string,callback_data:string}> */
        private function buttonsOf(ServerResponse $response): array
        {
            $result  = $response->getResult();
            $raw     = is_object($result) ? $result->reply_markup : null;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $flat    = [];
            foreach ((is_array($decoded) ? ($decoded['inline_keyboard'] ?? []) : []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button)) {
                        $flat[] = $button;
                    }
                }
            }

            return $flat;
        }

        /**
         * Каждая кнопка постройки и «Развитие базы» кончаются на `_b<baseId>`; этот id — база
         * `resolve()`, и `resolveForBase()` для того же игрока в той же клетке его не отвергает.
         *
         * @param array{charId:int} $w
         */
        private function assertStampedWith(ServerResponse $response, array $w, int $baseId, int $baseCell): void
        {
            $scoped = array_values(array_filter(
                $this->buttonsOf($response),
                static fn (array $b): bool => str_starts_with($b['callback_data'], 'building_') || str_starts_with($b['callback_data'], 'baseDevelopment')
            ));
            $this->assertCount(3, $scoped, 'две постройки базы (HandPump + Вышка) + «Развитие базы»');
            foreach ($scoped as $button) {
                $this->assertStringEndsWith('_b' . $baseId, $button['callback_data']);
            }

            $resolver = new BaseScopeResolver();
            $this->assertSame($baseCell, $resolver->resolve($w['charId'], 300)['cell'], 'id на кнопках — база resolve()');
            $this->assertNotSame(BaseScopeResolver::REASON_UNAVAILABLE, $resolver->resolveForBase($w['charId'], 300, $baseId)['reason']);
        }

        // ---- AC 1: покрывает только Вышка базы-2 → экран базы-2 ----

        public function testOnlySecondBaseTowerCoversShowsSecondBase(): void
        {
            $w = $this->seedWorld();
            $this->seedCharacterBuilding($w['charId'], $w['towerId'], 1000, 7); // 700 ≥ 700

            $response = $this->construction($w['tgId']);
            $text     = $this->textOf($response);

            $this->assertStringContainsString('(ур. 7)', $text);
            $this->assertStringContainsString('700/700', $text);
            $this->assertStringContainsString('x=1000, y=0', $text, 'шапка — координаты базы-2');
            $labels = implode("\n", array_column($this->buttonsOf($response), 'text'));
            $this->assertStringContainsString('Ручная скважина L3', $labels);
            $this->assertStringNotContainsString('Ручная скважина L10', $labels);
            $this->assertStampedWith($response, $w, $w['base2'], 1000);
        }

        // ---- AC 2a: покрывают обе → база-1, как resolve() ----

        public function testBothTowersCoverShowsFirstBaseById(): void
        {
            $w = $this->seedWorld();
            $this->seedCharacterBuilding($w['charId'], $w['towerId'], 0, 3);    // 300 ≥ 300
            $this->seedCharacterBuilding($w['charId'], $w['towerId'], 1000, 7); // 700 ≥ 700

            $response = $this->construction($w['tgId']);
            $text     = $this->textOf($response);

            $this->assertStringContainsString('(ур. 3)', $text);
            $this->assertStringContainsString('300/300', $text);
            $this->assertStringContainsString('x=0, y=0', $text);
            $labels = implode("\n", array_column($this->buttonsOf($response), 'text'));
            $this->assertStringContainsString('Ручная скважина L10', $labels);
            $this->assertStringNotContainsString('Ручная скважина L3', $labels);
            $this->assertStampedWith($response, $w, $w['base1'], 0);
        }

        // ---- AC 2b: не покрывает ни одна → прежний отказ байт в байт ----

        public function testNoTowerCoversKeepsLegacyRefusalByteForByte(): void
        {
            $w = $this->seedWorld();
            $this->seedCharacterBuilding($w['charId'], $w['towerId'], 1000, 1); // 100 < 700

            $response = $this->construction($w['tgId']);

            // Текст `handleNotOnBasePhysically()` до правки — первая активная база (x=0 y=0).
            $expected = "🤖 Это снова я – *Роби*!\n\n"
                . "Твоя база находится в другой игровой ячейке, ты не дома! "
                . "Чтобы начать строительство или изучить сооружения, вернись на базу:\n"
                . "1️⃣ пешком\n"
                . "2️⃣ телепорт.\n\n"
                . "📍 *Координаты базы*: x=0 y=0\n"
                . "🌍 *Биом*: Лес";
            $this->assertSame($expected, $this->textOf($response));

            $callbacks = array_column($this->buttonsOf($response), 'callback_data');
            $this->assertSame(['TeleportToCamp', 'move'], $callbacks, 'кнопок построек нет');
        }
    }
}
