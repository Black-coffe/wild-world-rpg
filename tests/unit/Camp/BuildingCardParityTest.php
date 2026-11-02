<?php

declare(strict_types=1);

namespace Longman\TelegramBot {
    if (! function_exists('Longman\TelegramBot\fopen')) {
        /**
         * Общий шим `fopen()` карточек построек (тот же, что в `BuildingCardBaseScopeTest`; объявляет
         * его тот файл, что загрузился первым). Карточки собирают картинку через
         * `Request::encodeFile(base_url(...))` — `http://` URL, который стенд не скачает; шим
         * подменяет его на локальный файл под `public/`. Вне окна теста, поднявшего флаг
         * `$GLOBALS['__building_card_fopen_shim']`, — прозрачный проход к настоящему `fopen()`.
         *
         * @param string $filename
         * @param string $mode
         * @return resource|false
         */
        function fopen($filename, $mode)
        {
            if (($GLOBALS['__building_card_fopen_shim'] ?? false) === true
                && is_string($filename)
                && preg_match('#^https?://[^/]+/(.+)$#', $filename, $m) === 1
            ) {
                $local = rtrim(FCPATH, '\\/') . '/' . $m[1];
                if (is_file($local)) {
                    return \fopen($local, $mode);
                }
            }

            return \fopen($filename, $mode);
        }
    }
}

namespace Tests\Unit\Camp {

    use App\Controllers\Telegram\Commands\Actions\Camp\BuildingHandlerAction;
    use CodeIgniter\Database\BaseConnection;
    use CodeIgniter\Test\CIUnitTestCase;
    use CodeIgniter\Test\DatabaseTestTrait;
    use Config\Database;
    use Longman\TelegramBot\Entities\CallbackQuery;
    use Longman\TelegramBot\Entities\ServerResponse;
    use Longman\TelegramBot\Telegram;

    /**
     * w2-n4b-cards-01 (ADR-190): 14 карточек построек переезжают в одно ядро `BuildingCardService`,
     * бот рисует их одним отрисовщиком. Обещание владельца — «текст и кнопки байт-в-байт прежние».
     *
     * Снимки `BuildingCardSnapshots.json` сняты со СТАРЫХ классов `Camp/Buildings/*Handler.php` до их
     * удаления (режим записи: `BUILDING_CARD_SNAPSHOT=write`). Матрица: каждое здание × два состояния
     * строки (личная / коллективная, без лимита / с лимитом использований, без срока / со сроком) ×
     * колбэк без и с суффиксом базы `_b<id>`; оборона — целая ×1 и повреждённая ×3. Сравнивается всё,
     * что уходит в Telegram: caption/text, parse_mode, клавиатура, фото или текст.
     *
     * Второе обещание (ask 4): переезд базы блокирует карточку в ядре — для всех 14, включая оборону и
     * Навес, у которых старые классы проверки не имели.
     *
     * @internal
     */
    final class BuildingCardParityTest extends CIUnitTestCase
    {
        use DatabaseTestTrait;

        protected $migrate = false;

        private const PREFIX   = 'bcpt_';
        private const SNAPSHOT = __DIR__ . '/BuildingCardSnapshots.json';
        private const TG_ID    = 731_000_001;
        private const CELL     = 4242;

        /** Ключ здания → [имя, тип]. Все карточки, у которых есть экран в боте. */
        private const KEYS = [
            'HandPump'            => ['Ручная колонка', 'resource'],
            'BlastFurnace'        => ['Доменная печь', 'engineering'],
            'RoboticsWorkshop'    => ['Мастерская роботов', 'engineering'],
            'Workshop'            => ['Мастерская', 'engineering'],
            'Warehouse'           => ['Склад', 'residential'],
            'Laboratory'          => ['Лаборатория', 'engineering'],
            'Greenhouse'          => ['Теплица', 'farming'],
            'SolarStation'        => ['Солнечная станция', 'resource'],
            'Gym'                 => ['Спортзал', 'residential'],
            'TeleportationCenter' => ['Центр телепортации', 'engineering'],
            'Arsenal'             => ['Арсенал', 'military'],
            'CommunicationTower'  => ['Вышка связи', 'engineering'],
            'WoodenWall'          => ['Деревянная стена', 'defensive'],
            'BarbedFence'         => ['Колючий забор', 'defensive'],
            'WatchTower'          => ['Сторожевая вышка', 'defensive'],
            'LeanTo'              => ['Навес', 'residential'],
        ];

        private const DEFENSIVE = ['WoodenWall', 'BarbedFence', 'WatchTower'];

        private const TABLES = [
            'telegram_users', 'characters', 'buildings', 'character_buildings',
            'claimed_cells', 'tasks', 'character_tasks',
        ];

        private string $origPrefix = '';
        private int $charId        = 0;
        private int $baseId        = 0;
        /** @var array<string,int> */
        private array $buildingIds = [];

        protected function setUp(): void
        {
            parent::setUp();

            if (! defined('PHPUNIT_TESTSUITE')) {
                define('PHPUNIT_TESTSUITE', true);
            }
            new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

            $GLOBALS['__building_card_fopen_shim'] = true;
            $this->origPrefix = $this->db()->getPrefix();
            $this->db()->setPrefix(self::PREFIX);

            $this->createOwnTable('telegram_users', 'CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL)');
            $this->createOwnTable('characters', 'CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL,
                cell_number INT NULL, locale VARCHAR(8) NULL, level INT NULL DEFAULT 1,
                disable_media TINYINT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
            $this->createOwnTable('buildings', "CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL,
                building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL,
                hp INT NULL, usage_count INT NULL, description VARCHAR(255) NULL)");
            $this->createOwnTable('character_buildings', 'CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL,
                map_cell_id INT NULL, amount INT NULL DEFAULT 1, hp INT NULL, level INT NULL DEFAULT 1,
                built_at DATETIME NULL, building_type VARCHAR(32) NULL, tax INT NULL, `usage` VARCHAR(16) NULL,
                disappearance_date DATETIME NULL, usage_count INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
            $this->createOwnTable('claimed_cells', 'CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, map_cell_id INT NULL,
                claimed_at DATETIME NULL, status VARCHAR(16) NULL DEFAULT "active")');
            $this->createOwnTable('tasks', 'CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL)');
            $this->createOwnTable('character_tasks', 'CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, task_id INT NULL,
                status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL)');

            $cache = service('cache');
            if (is_object($cache) && method_exists($cache, 'clean')) {
                $cache->clean();
            }

            $this->db()->table('telegram_users')->insert(['telegram_id' => self::TG_ID]);
            $tgUid = (int) $this->db()->insertID();
            $this->db()->table('characters')->insert([
                'telegram_user_id' => $tgUid, 'name' => 'Тест', 'cell_number' => self::CELL, 'locale' => 'ru', 'level' => 30,
            ]);
            $this->charId = (int) $this->db()->insertID();
            $this->db()->table('claimed_cells')->insert([
                'character_id' => $this->charId, 'map_cell_id' => self::CELL,
                'claimed_at' => '2026-01-01 00:00:00', 'status' => 'active',
            ]);
            $this->baseId = (int) $this->db()->insertID();

            foreach (self::KEYS as $key => [$nameRu, $type]) {
                $this->db()->table('buildings')->insert([
                    'name_ru' => $nameRu, 'name_en' => $key, 'building_type' => $type, 'hp' => 100,
                    'usage_count' => 10, 'description' => 'Описание: ' . $nameRu . '.',
                ]);
                $this->buildingIds[$key] = (int) $this->db()->insertID();
            }
        }

        protected function tearDown(): void
        {
            try {
                foreach (array_reverse(self::TABLES) as $table) {
                    $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
                }
            } finally {
                $this->db()->setPrefix($this->origPrefix);
                $GLOBALS['__building_card_fopen_shim'] = false;
            }

            parent::tearDown();
        }

        /**
         * Каждая карточка совпадает со снимком старого класса байт-в-байт.
         */
        public function testEveryCardMatchesSnapshotOfOldHandler(): void
        {
            $actual = [];
            foreach (array_keys(self::KEYS) as $key) {
                foreach ($this->variantsOf($key) as $variant => $row) {
                    foreach (['plain', 'suffix'] as $cb) {
                        $this->db()->table('character_buildings')->where('character_id', $this->charId)->delete();
                        $this->db()->table('character_buildings')->insert($row + [
                            'character_id' => $this->charId, 'building_id' => $this->buildingIds[$key],
                            'map_cell_id' => self::CELL, 'building_type' => self::KEYS[$key][1],
                        ]);
                        $data = 'building_' . $this->buildingIds[$key] . '_' . $key . ($cb === 'suffix' ? '_b' . $this->baseId : '');
                        $actual["{$key}/{$variant}/{$cb}"] = $this->render($data);
                    }
                }
            }
            ksort($actual);

            if (getenv('BUILDING_CARD_SNAPSHOT') === 'write') {
                file_put_contents(self::SNAPSHOT, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }
            $this->assertFileExists(self::SNAPSHOT, 'Снимков нет: их снимают со старых классов (BUILDING_CARD_SNAPSHOT=write).');
            $expected = json_decode((string) file_get_contents(self::SNAPSHOT), true);
            $this->assertIsArray($expected);

            foreach ($expected as $case => $want) {
                $this->assertArrayHasKey($case, $actual, "Карточка {$case} пропала.");
                $this->assertSame($want, $actual[$case], "Карточка {$case} разошлась со снимком старого handler'а.");
            }
            $this->assertSame(array_keys($expected), array_keys($actual), 'Набор карточек изменился.');
        }

        /**
         * Ask 4: во время переезда базы ни одна карточка не открывается — и оборона с Навесом тоже
         * (старые DefensiveBuildingHandler / LeanToHandler переезд не проверяли).
         */
        public function testRelocationBlocksEveryCardIncludingDefenseAndLeanTo(): void
        {
            $this->db()->table('tasks')->insert(['name' => 'BaseRelocation', 'name_rus' => 'Переезд базы']);
            $taskId = (int) $this->db()->insertID();
            $this->db()->table('character_tasks')->insert([
                'character_id' => $this->charId, 'task_id' => $taskId, 'status' => 'in_work',
                'start_time' => '2026-01-01 00:00:00', 'end_time' => '2099-01-01 00:00:00',
            ]);

            foreach (['HandPump', 'WoodenWall', 'WatchTower', 'LeanTo'] as $key) {
                $this->db()->table('character_buildings')->where('character_id', $this->charId)->delete();
                $this->db()->table('character_buildings')->insert([
                    'character_id' => $this->charId, 'building_id' => $this->buildingIds[$key], 'map_cell_id' => self::CELL,
                    'level' => 2, 'amount' => 1, 'hp' => 100, 'tax' => 5, 'usage' => 'personal',
                    'built_at' => '2026-01-15 10:00:00', 'building_type' => self::KEYS[$key][1],
                ]);
                $out = $this->render('building_' . $this->buildingIds[$key] . '_' . $key);
                $this->assertSame('', $out['text'], "{$key}: карточка открылась во время переезда базы.");
                $this->assertSame([], $out['keyboard'], "{$key}: во время переезда ушла клавиатура карточки.");
            }
        }

        /**
         * @return array<string, array<string,mixed>>
         */
        private function variantsOf(string $key): array
        {
            if (in_array($key, self::DEFENSIVE, true)) {
                return [
                    'full1' => ['level' => 2, 'amount' => 1, 'hp' => 99999, 'tax' => 5, 'usage' => 'personal', 'built_at' => '2026-01-15 10:00:00'],
                    'dmg3'  => ['level' => 3, 'amount' => 3, 'hp' => 7, 'tax' => 12, 'usage' => 'personal', 'built_at' => '2026-01-15 10:00:00'],
                ];
            }
            if ($key === 'LeanTo') {
                return [
                    'hp50' => ['level' => 1, 'amount' => 1, 'hp' => 50, 'tax' => 0, 'usage' => 'personal', 'built_at' => '2026-01-15 10:00:00'],
                ];
            }

            return [
                'personal' => [
                    'level' => 2, 'amount' => 1, 'hp' => 100, 'tax' => 10, 'usage' => 'personal',
                    'built_at' => '2026-01-15 10:00:00', 'disappearance_date' => null, 'usage_count' => null,
                ],
                'collective' => [
                    'level' => 5, 'amount' => 2, 'hp' => 100, 'tax' => 0, 'usage' => 'collective',
                    'built_at' => '2026-02-20 08:30:00', 'disappearance_date' => '2026-12-31 00:00:00', 'usage_count' => 3,
                ],
            ];
        }

        /**
         * Что ушло бы в Telegram: текст (caption/text), parse_mode, клавиатура, фото или текст.
         *
         * @return array{kind:string, text:string, parse_mode:string, keyboard:list<list<array{text:string,data:string}>>}
         */
        private function render(string $data): array
        {
            $response = (new BuildingHandlerAction(new CallbackQuery([
                'id'   => 'cbq_' . random_int(1, 999999999),
                'from' => ['id' => self::TG_ID, 'is_bot' => false, 'first_name' => 'Тест'],
                'message' => [
                    'message_id' => 1, 'date' => time(),
                    'chat' => ['id' => self::TG_ID, 'type' => 'private'], 'text' => 'placeholder',
                ],
                'chat_instance' => 'ci', 'data' => $data,
            ])))->handle();

            return self::shape($response);
        }

        /**
         * @return array{kind:string, text:string, parse_mode:string, keyboard:list<list<array{text:string,data:string}>>}
         */
        private static function shape(ServerResponse $response): array
        {
            // Фейковый ответ Longman: `result` — Message (или true у пустого ответа); его raw_data —
            // ровно то, что ушло бы в Telegram, `media` — InputMediaPhoto со своим raw_data.
            $resultObj = $response->getResult();
            // Геттеры Entity магические (`__call`), `method_exists` их не видит — зовём напрямую.
            $result    = is_object($resultObj) ? (array) $resultObj->getRawData() : [];
            if (isset($result['media']) && is_object($result['media'])) {
                $result['media'] = (array) $result['media']->getRawData();
            }

            $kind = 'none';
            $text = '';
            $mode = '';
            if (isset($result['media'])) {
                $media = is_string($result['media']) ? json_decode($result['media'], true) : (array) $result['media'];
                $kind  = 'photo';
                $text  = (string) ($media['caption'] ?? '');
                $mode  = (string) ($media['parse_mode'] ?? '');
            } elseif (isset($result['caption'])) {
                $kind = 'photo';
                $text = (string) $result['caption'];
                $mode = (string) ($result['parse_mode'] ?? '');
            } elseif (isset($result['text'])) {
                $kind = 'text';
                $text = (string) $result['text'];
                $mode = (string) ($result['parse_mode'] ?? '');
            }

            $markup = $result['reply_markup'] ?? null;
            if (is_string($markup)) {
                $markup = json_decode($markup, true);
            }
            $keyboard = [];
            foreach ((is_array($markup) ? ($markup['inline_keyboard'] ?? []) : []) as $row) {
                $keyboard[] = array_map(
                    static fn (array $b): array => ['text' => (string) ($b['text'] ?? ''), 'data' => (string) ($b['callback_data'] ?? '')],
                    (array) $row
                );
            }

            return ['kind' => $kind, 'text' => $text, 'parse_mode' => $mode, 'keyboard' => $keyboard];
        }

        private function db(): BaseConnection
        {
            return Database::connect('tests');
        }

        private function createOwnTable(string $table, string $ddl): void
        {
            $prefixed = self::PREFIX . $table;
            $this->db()->query("DROP TABLE IF EXISTS {$prefixed}");
            $this->db()->query(str_replace('__TABLE__', $prefixed, $ddl));
        }
    }
}
