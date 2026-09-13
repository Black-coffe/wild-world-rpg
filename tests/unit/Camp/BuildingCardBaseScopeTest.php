<?php

declare(strict_types=1);

namespace Longman\TelegramBot {
    /**
     * PHP resolves an unqualified `fopen()` call made INSIDE this namespace to this
     * function first (namespace-function fallback), so it shadows the global `fopen()`
     * used by `Request::encodeFile()` — without touching `Request`/`MediaSender`
     * themselves (out of story scope). Handlers build the image path via
     * `base_url('uploads/telegram/camp/...')`, which is an `http://` URL the test
     * stand can't fetch; we rewrite it to the real local asset under `public/` so the
     * card's photo/caption path runs exactly like in production, just off disk.
     *
     * The namespaced `function fopen()` declaration itself can't be scoped to one test
     * file — PHP registers it process-wide the moment this file is loaded, and nothing
     * can undeclare it (angela-second-base-bugs-05). What CAN be scoped is its
     * BEHAVIOUR: gated behind a static flag that `BuildingCardBaseScopeTest` flips on
     * only for its own `setUp()`/`tearDown()` window, this function is a transparent
     * pass-through to the real `fopen()` for every test loaded before it, after it, or
     * running interleaved in the same process — indistinguishable from no shim at all.
     *
     * @param string $filename
     * @param string $mode
     * @return resource|false
     */
    function fopen($filename, $mode)
    {
        if (\Tests\Unit\Camp\BuildingCardBaseScopeTest::$fopenShimActive
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

namespace Tests\Unit\Camp {

    use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\HandPumpHandler;
    use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WarehouseHandler;
    use CodeIgniter\Database\BaseConnection;
    use CodeIgniter\Test\CIUnitTestCase;
    use CodeIgniter\Test\DatabaseTestTrait;
    use Config\Database;
    use Longman\TelegramBot\Entities\CallbackQuery;
    use Longman\TelegramBot\Entities\ServerResponse;
    use Longman\TelegramBot\Telegram;

    /**
     * angela-second-base-bugs-02 — карточка здания на второй базе показывала уровень
     * СОВСЕМ ДРУГОЙ (первой) базы: старый запрос искал строку `character_buildings`
     * только по `(character_id, building_id)`, без `map_cell_id`, и `->first()` без
     * `orderBy` брал первую попавшуюся строку — обычно старейшую (первую базу).
     *
     * Правка читает строку той базы, в клетке которой стоит игрок, через
     * `ClaimedCellModel::resolveTargetBaseCell()` (owned by story 01) — тем же
     * методом, что уже использует `GenericBuildingAction`.
     *
     * Тест бьёт по ПОВЕДЕНИЮ выборки (реальный `handle()`, реальный текст ответа),
     * а не по наличию `map_cell_id` в исходнике (`feedback_source_scan_tests_are_not_coverage`).
     * Схема таблиц — минимальный набор колонок, которые реально трогает `handle()`
     * ({@see \App\Controllers\Telegram\Commands\Actions\BaseAction::getUserAndCharacter()}),
     * тот же DDL-паттерн, что `RepairBuildingShortageRollbackTest`
     * (`feedback_test_schema_must_come_from_migration`, `feedback_local_green_on_empty_test_db_proves_nothing`).
     *
     * @internal
     */
    final class BuildingCardBaseScopeTest extends CIUnitTestCase
    {
        use DatabaseTestTrait;

        protected $migrate = false;

        /**
         * Флаг, читаемый namespaced `fopen()`-шимом выше: включён только на время
         * setUp()..tearDown() ЭТОГО теста, чтобы шим не действовал на тесты,
         * загруженные до/после/вперемешку с этим файлом (angela-second-base-bugs-05).
         */
        public static bool $fopenShimActive = false;

        /**
         * Приватный префикс таблиц (тот же паттерн, что `bubs_*` в
         * `BuildingUpgradeBaseScopeTest`), применяется через `BaseConnection::setPrefix()` —
         * реальные модели/хендлеры продолжают запрашивать `characters`/`buildings`/...
         * без единой правки, а CI4 query builder транслирует их в `bcbs_characters`/
         * `bcbs_buildings`/... на уровне SQL. Полная изоляция от общих таблиц: чужой тест
         * не может оставить здесь несовместимую схему, потому что имя `bcbs_*` — только
         * наше (feedback_test_schema_must_come_from_migration,
         * feedback_local_green_on_empty_test_db_proves_nothing).
         */
        private const PREFIX = 'bcbs_';

        private string $origPrefix = '';

        protected function setUp(): void
        {
            parent::setUp();

            if (! defined('PHPUNIT_TESTSUITE')) {
                define('PHPUNIT_TESTSUITE', true);
            }
            // Нужен, чтобы App\Services\Telegram\Request коротил на фейковый ServerResponse
            // вместо реального HTTP (урок feedback_taskhandler_telegram_init_in_tests).
            new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

            self::$fopenShimActive = true;
            $this->origPrefix      = $this->db()->getPrefix();
            $this->db()->setPrefix(self::PREFIX);

            $this->createOwnTable('telegram_users', '
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    telegram_id BIGINT NULL
                )
            ', ['id', 'telegram_id']);
            $this->createOwnTable('characters', '
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    telegram_user_id INT NULL,
                    name VARCHAR(64) NULL,
                    cell_number INT NULL,
                    locale VARCHAR(8) NULL,
                    level INT NULL DEFAULT 1,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ', ['id', 'telegram_user_id', 'cell_number', 'locale']);
            $this->createOwnTable('buildings', "
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name_ru VARCHAR(255) NULL,
                    name_en VARCHAR(255) NULL,
                    building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL,
                    hp INT NULL,
                    usage_count INT NULL,
                    description VARCHAR(255) NULL
                )
            ", ['id', 'name_ru', 'name_en', 'building_type']);
            $this->createOwnTable('character_buildings', "
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    character_id INT NULL,
                    building_id INT NULL,
                    map_cell_id INT NULL,
                    amount INT NULL DEFAULT 1,
                    hp INT NULL,
                    level INT NULL DEFAULT 1,
                    built_at DATETIME NULL,
                    building_type VARCHAR(32) NULL,
                    tax INT NULL,
                    `usage` VARCHAR(16) NULL,
                    disappearance_date DATETIME NULL,
                    usage_count INT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ", ['id', 'character_id', 'building_id', 'map_cell_id', 'level']);
            $this->createOwnTable('claimed_cells', '
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    character_id INT NULL,
                    map_cell_id INT NULL,
                    claimed_at DATETIME NULL,
                    status VARCHAR(16) NULL DEFAULT "active"
                )
            ', ['id', 'character_id', 'map_cell_id', 'status']);
            $this->createOwnTable('tasks', '
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(64) NULL,
                    name_rus VARCHAR(64) NULL
                )
            ', ['id', 'name']);
            $this->createOwnTable('character_tasks', '
                CREATE TABLE __TABLE__ (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    character_id INT NULL,
                    task_id INT NULL,
                    status VARCHAR(16) NULL,
                    start_time DATETIME NULL,
                    end_time DATETIME NULL,
                    task_settings TEXT NULL
                )
            ', ['id', 'character_id', 'task_id', 'status']);

            $cache = service('cache');
            if (is_object($cache) && method_exists($cache, 'clean')) {
                $cache->clean();
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
                self::$fopenShimActive = false;
            }

            parent::tearDown();
        }

        /** @var list<string> порядок создания == порядок дропа в reverse. */
        private const TABLES = [
            'telegram_users', 'characters', 'buildings', 'character_buildings',
            'claimed_cells', 'tasks', 'character_tasks',
        ];

        private function db(): BaseConnection
        {
            return Database::connect('tests');
        }

        /**
         * Таблица живёт только под приватным префиксом `bcbs_` — никакой другой тест
         * этим именем не пользуется, поэтому здесь никогда не может обнаружиться
         * чужая, несовместимая схема: `DROP ... IF EXISTS` подчищает только
         * возможный мусор от аварийно прерванного прошлого прогона ЭТОГО ЖЕ файла.
         * После создания проверяем ожидаемые колонки — если DDL когда-нибудь
         * разойдётся с требованиями `handle()`, тест упадёт с именем таблицы и
         * недостающей колонки, а не с сырым `Unknown column` из драйвера.
         *
         * @param list<string> $requiredColumns
         */
        private function createOwnTable(string $table, string $ddl, array $requiredColumns): void
        {
            $prefixed = self::PREFIX . $table;
            $this->db()->query("DROP TABLE IF EXISTS {$prefixed}");
            $this->db()->query(str_replace('__TABLE__', $prefixed, $ddl));

            $actual  = $this->db()->getFieldNames($prefixed);
            $missing = array_diff($requiredColumns, $actual);
            if ($missing !== []) {
                throw new \RuntimeException(sprintf(
                    'BuildingCardBaseScopeTest: таблица %s существует, но в ней нет колонки %s — DDL теста разошёлся с тем, что реально трогает handle().',
                    $prefixed,
                    implode(', ', $missing)
                ));
            }
        }

        /** @return array{0:int,1:int} [telegram_id, character_id] */
        private function seedCharacter(int $cellNumber): array
        {
            $tgId = random_int(720_000_000, 729_999_999);
            $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
            $tgUid = (int) $this->db()->insertID();

            $this->db()->table('characters')->insert([
                'telegram_user_id' => $tgUid, 'cell_number' => $cellNumber, 'locale' => 'ru',
            ]);
            $charId = (int) $this->db()->insertID();

            return [$tgId, $charId];
        }

        private function seedBase(int $charId, int $cell): void
        {
            $this->db()->table('claimed_cells')->insert([
                'character_id' => $charId, 'map_cell_id' => $cell,
                'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
            ]);
        }

        private function seedBuilding(string $nameRu, string $nameEn): int
        {
            $this->db()->table('buildings')->insert([
                'name_ru' => $nameRu, 'name_en' => $nameEn, 'building_type' => 'resource', 'hp' => 100,
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

        /** Новая CallbackQuery на каждый вызов handle(). */
        private function cbq(int $tgId, string $data): CallbackQuery
        {
            return new CallbackQuery([
                'id'   => 'cbq_' . random_int(1, 999999999),
                'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
                'message' => [
                    'message_id' => 1, 'date' => time(),
                    'chat' => ['id' => $tgId, 'type' => 'private'],
                    'text' => 'placeholder',
                ],
                'chat_instance' => 'ci_' . $tgId,
                'data' => $data,
            ]);
        }

        /**
         * Карточки шлют caption (фото), text (fallback-сообщение), либо — раз наша
         * фейковая `CallbackQuery` всегда несёт `message_id` — уходят в ветку
         * `MediaSender::editOrSend()`, где caption едет ВНУТРИ `InputMediaPhoto`
         * (`media`), а не top-level полем.
         *
         * `Longman\TelegramBot\Entities\Entity` реализует все геттеры магически через
         * `__call()` — `method_exists()` их не видит (не декларированы явно), поэтому
         * зовём напрямую: `__call()` безопасно вернёт `null`, если поля нет.
         */
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

            $media = $result->getMedia();
            if (is_object($media)) {
                $mediaCaption = $media->getCaption();
                if (is_string($mediaCaption) && $mediaCaption !== '') {
                    return $mediaCaption;
                }
            }

            $text = $result->getText();

            return is_string($text) ? $text : '';
        }

        public function testCardOnEachBaseShowsOwnLevelNotAnotherBases(): void
        {
            $buildingId = $this->seedBuilding('Ручная скважина', 'HandPump');

            [$tgId, $charId] = $this->seedCharacter(100);
            $this->seedBase($charId, 100);
            $this->seedBase($charId, 200);
            $this->seedCharacterBuilding($charId, $buildingId, 100, 10); // база A — L10
            $this->seedCharacterBuilding($charId, $buildingId, 200, 1);  // база B — L1

            // Игрок стоит на базе A (100) — карточка обязана показать L10, не L1 с базы B.
            $responseA = (new HandPumpHandler($this->cbq($tgId, 'building_' . $buildingId . '_HandPump')))->handle();
            $this->assertStringContainsString('10 lvl', $this->textOf($responseA));
            $this->assertStringNotContainsString('1 lvl', $this->textOf($responseA));

            // Переставляем игрока на базу B (200) — карточка обязана показать L1, не L10 с базы A.
            $this->db()->table('characters')->where('id', $charId)->update(['cell_number' => 200]);
            $responseB = (new HandPumpHandler($this->cbq($tgId, 'building_' . $buildingId . '_HandPump')))->handle();
            $this->assertStringContainsString('1 lvl', $this->textOf($responseB));
            $this->assertStringNotContainsString('10 lvl', $this->textOf($responseB));
        }

        public function testCardDoesNotLeakBuildingFromOtherBase(): void
        {
            $buildingId      = $this->seedBuilding('Склад', 'Warehouse');
            [$tgId, $charId] = $this->seedCharacter(100);
            $this->seedBase($charId, 100);
            // Склад есть только на базе, где игрока сейчас нет — на базе 100 у него Склада нет.
            $this->seedCharacterBuilding($charId, $buildingId, 999, 5);

            $response = (new WarehouseHandler($this->cbq($tgId, 'building_' . $buildingId . '_Warehouse')))->handle();
            $this->assertStringContainsString('нет Склада на базе', $this->textOf($response));
        }

        public function testAmbiguousBaseAsksToStandOnIt(): void
        {
            $buildingId      = $this->seedBuilding('Ручная скважина', 'HandPump');
            [$tgId, $charId] = $this->seedCharacter(999); // не стоит ни на одной из баз
            $this->seedBase($charId, 100);
            $this->seedBase($charId, 200);
            $this->seedCharacterBuilding($charId, $buildingId, 100, 10);
            $this->seedCharacterBuilding($charId, $buildingId, 200, 1);

            $response = (new HandPumpHandler($this->cbq($tgId, 'building_' . $buildingId . '_HandPump')))->handle();
            $this->assertStringContainsString(
                'Баз у тебя несколько. Встань на ту базу, с которой работаешь, — и открой экран снова.',
                $this->textOf($response)
            );
        }

        public function testSingleBaseCharacterUnaffected(): void
        {
            $buildingId      = $this->seedBuilding('Ручная скважина', 'HandPump');
            [$tgId, $charId] = $this->seedCharacter(100);
            $this->seedBase($charId, 100);
            $this->seedCharacterBuilding($charId, $buildingId, 100, 4);

            // Игрок с одной базой — даже стоя не на клетке базы (например, в поле) экран
            // резолвит его единственную базу и показывает уровень, как раньше.
            $this->db()->table('characters')->where('id', $charId)->update(['cell_number' => 777]);
            $response = (new HandPumpHandler($this->cbq($tgId, 'building_' . $buildingId . '_HandPump')))->handle();
            $this->assertStringContainsString('4 lvl', $this->textOf($response));
        }

        // story angela-second-base-bugs-07 — `resolveTargetBaseCell()` возвращает `null`
        // и для «баз ≥2» И для «баз нет вообще»; до этой истории оба состояния отвечали
        // одним текстом «Встань на ту базу, с которой работаешь» — прямая ложь игроку
        // без единой базы.
        public function testNoBasesAtAllGetsHonestRefusalNotAmbiguousText(): void
        {
            $buildingId      = $this->seedBuilding('Ручная скважина', 'HandPump');
            [$tgId, $charId] = $this->seedCharacter(100); // ни одной seedBase()

            $response = (new HandPumpHandler($this->cbq($tgId, 'building_' . $buildingId . '_HandPump')))->handle();
            $this->assertStringContainsString(
                'Базы у тебя сейчас нет. Разбей лагерь — и постройки появятся на этом экране.',
                $this->textOf($response)
            );
            $this->assertStringNotContainsString('Встань на ту базу', $this->textOf($response));
        }
    }
}
