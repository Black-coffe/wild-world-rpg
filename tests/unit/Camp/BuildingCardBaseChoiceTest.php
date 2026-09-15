<?php

declare(strict_types=1);

namespace Tests\Unit\Camp {

    // `BuildingCardBaseScopeTest` объявляет процесс-широкий namespaced `fopen()`-шим,
    // который переиспользует этот файл (см. ниже) — вместо второго `function fopen()`
    // (redeclare-фатал). Явный require нужен, когда этот файл запускают ОДНИМ (не всем
    // сьютом): autoloader тестов не подхватывает `tests/` по PSR-4.
    if (! class_exists(BuildingCardBaseScopeTest::class, false)) {
        require_once __DIR__ . '/BuildingCardBaseScopeTest.php';
    }

    use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\HandPumpHandler;
    use App\Services\Bases\BaseCallbackSuffix;
    use App\Services\Bases\BaseScopeResolver;
    use App\Services\Player\BuildingUpgrade\BuildingUpgradeApplier;
    use App\Services\Player\BuildingUpgrade\BuildingUpgradeValidator;
    use App\Services\Player\CharacterStatsService;
    use CodeIgniter\Database\BaseConnection;
    use CodeIgniter\Test\CIUnitTestCase;
    use CodeIgniter\Test\DatabaseTestTrait;
    use Config\Database;
    use Longman\TelegramBot\Entities\CallbackQuery;
    use Longman\TelegramBot\Entities\ServerResponse;
    use Longman\TelegramBot\Telegram;

    /**
     * story multibase-picker-03 — 14 карточек построек и апгрейд (`UpgradeBuildingAction`
     * → `BuildingUpgradeValidator`) разбирают необязательный суффикс `_b<baseId>` через
     * `BaseCallbackSuffix::split()` (история 01). С суффиксом база берётся через
     * `BaseScopeResolver::resolveForBase()` (своя, активная, игрок на ней либо под
     * сигналом ЕЁ Вышки связи) — заново проверенная, а не подсказка из кнопки.
     *
     * Схема — свой приватный префикс `bcbc_` (паттерн `BuildingCardBaseScopeTest` +
     * `CommunicationTowerCoverageByBaseTest`), тест строит её сам
     * (`feedback_test_schema_must_come_from_migration`).
     *
     * Апгрейд «по кнопке до конца» (askForUpgrade → confirmUpgrade через реальный
     * `UpgradeBuildingAction`) здесь НЕ гоняется: подтверждающая кнопка
     * `confirm_upgrade_building_{id}` строится в `BuildingUpgradeMessageFormatter::askPrompt()`,
     * который не входит в `## Files` этой истории (см. `## Findings` / `INTERFACES` в
     * story-файле) и суффикс НЕ несёт. Механизм «суффикс → правильная база»
     * проверяется на уровне `BuildingUpgradeValidator::validate($baseId)` +
     * `BuildingUpgradeApplier::apply()` напрямую — том же слое, что и эталонный
     * `BuildingUpgradeBaseScopeTest` (angela-second-base-bugs-03), и через реальный
     * `UpgradeBuildingAction::askForUpgrade()` для самого парсинга суффикса.
     *
     * @internal
     */
    final class BuildingCardBaseChoiceTest extends CIUnitTestCase
    {
        use DatabaseTestTrait;

        protected $migrate = false;

        private const PREFIX = 'bcbc_';

        /** @var list<string> */
        private const TABLES = [
            'telegram_users', 'characters', 'buildings', 'character_buildings',
            'claimed_cells', 'map', 'game_settings', 'tasks', 'character_tasks',
        ];

        private string $origPrefix = '';

        protected function setUp(): void
        {
            parent::setUp();

            if (! defined('PHPUNIT_TESTSUITE')) {
                define('PHPUNIT_TESTSUITE', true);
            }
            new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

            // Переиспользуем namespaced-`fopen()`-шим, объявленный `BuildingCardBaseScopeTest`
            // (process-wide function — объявить второй раз в этом же файле нельзя,
            // PHP не разрешает redeclare). Флаг у неё public static.
            BuildingCardBaseScopeTest::$fopenShimActive = true;
            $this->origPrefix      = $this->db()->getPrefix();
            $this->db()->setPrefix(self::PREFIX);
            $this->resetBuildingModelStaticCache();

            $ddl = [
                'telegram_users' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
                'characters' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, '
                    . 'name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, '
                    . 'level INT NULL DEFAULT 1, gold DOUBLE NULL DEFAULT 1000, '
                    . 'created_at DATETIME NULL, updated_at DATETIME NULL',
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
                    . "map_cell_id INT NULL, claimed_at DATETIME NULL, status VARCHAR(16) NULL DEFAULT 'active'",
                'map' => 'id INT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL',
                'game_settings' => 'id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(128) NOT NULL, '
                    . 'value_type VARCHAR(16) NOT NULL, value_int INT NULL, value_float DOUBLE NULL, '
                    . 'value_bool TINYINT NULL, value_string VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
                'tasks' => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL',
                'character_tasks' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, task_id INT NULL, '
                    . 'status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL',
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

        /**
         * `BuildingModel::$byNameEnCache` — процесс-широкий static-кэш по `name_en`
         * (KEEP-семантика прод-справочника построек). Наши тесты этого класса
         * переиспользуют одни и те же имена ('HandPump', 'CommunicationTower') в
         * разных методах с РАЗНЫМИ id (таблица каждый раз пересоздаётся), поэтому
         * static-кэш от предыдущего теста иначе возвращает id из уже удалённой
         * таблицы (feedback_ci4_model_builder_state_quirk — тот же класс проблемы,
         * не сам builder, а ручной static-кэш поверх него).
         */
        private function resetBuildingModelStaticCache(): void
        {
            $prop = new \ReflectionProperty(\App\Models\BuildingModel::class, 'byNameEnCache');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }

        // ---- seeding ----

        private function seedMapLine(int ...$cells): void
        {
            foreach ($cells as $cell) {
                $this->db()->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $cell, 'coordinate_y' => 0]);
            }
        }

        /** @return array{0:int,1:int} [telegram_id, character_id] */
        private function seedCharacter(int $cellNumber): array
        {
            $tgId = random_int(720_000_000, 729_999_999);
            $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
            $tgUid = (int) $this->db()->insertID();

            $this->db()->table('characters')->insert([
                'telegram_user_id' => $tgUid, 'cell_number' => $cellNumber, 'locale' => 'ru', 'level' => 10, 'gold' => 1000,
            ]);
            $charId = (int) $this->db()->insertID();

            return [$tgId, $charId];
        }

        private function seedBase(int $charId, int $cell, string $status = 'active'): int
        {
            $this->db()->table('claimed_cells')->insert([
                'character_id' => $charId, 'map_cell_id' => $cell,
                'claimed_at' => date('Y-m-d H:i:s'), 'status' => $status,
            ]);

            return (int) $this->db()->insertID();
        }

        private function seedBuilding(string $nameRu, string $nameEn): int
        {
            $this->db()->table('buildings')->insert([
                'name_ru' => $nameRu, 'name_en' => $nameEn, 'building_type' => 'resource', 'hp' => 100,
            ]);

            return (int) $this->db()->insertID();
        }

        private function seedCharacterBuilding(int $charId, int $buildingId, int $cell, int $level): int
        {
            $this->db()->table('character_buildings')->insert([
                'character_id' => $charId, 'building_id' => $buildingId, 'map_cell_id' => $cell,
                'hp' => 100, 'level' => $level, 'built_at' => date('Y-m-d H:i:s'),
                'building_type' => 'resource', 'tax' => 10, 'usage' => 'personal',
            ]);

            return (int) $this->db()->insertID();
        }

        private function seedTower(int $charId, int $towerBuildingId, int $cell, int $level): void
        {
            $this->db()->table('character_buildings')->insert([
                'character_id' => $charId, 'building_id' => $towerBuildingId, 'map_cell_id' => $cell, 'level' => $level,
                'hp' => 100, 'built_at' => date('Y-m-d H:i:s'), 'building_type' => 'engineering', 'tax' => 10, 'usage' => 'personal',
            ]);
        }

        private function rowLevel(int $rowId): int
        {
            $row = $this->db()->table('character_buildings')->where('id', $rowId)->get()->getRowArray();

            return (int) $row['level'];
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

        /**
         * Читает `reply_markup` НАПРЯМУЮ из `raw_data` (`__get`), не через
         * `getReplyMarkup()` (паттерн `CampfireCustomQuantityTest::responseReplyMarkup()`
         * — у Entity нет `__isset`, геттер по имени поля резолвил бы sub-Entity,
         * а `Request::send()` кладёт JSON-строку как есть).
         *
         * @return array<string,mixed>|null Кнопка с данным `callback_data`-префиксом.
         */
        private function findButton(ServerResponse $response, string $prefix): ?array
        {
            $result  = $response->getResult();
            $raw     = is_object($result) ? $result->reply_markup : null;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($decoded)) {
                return null;
            }
            foreach ($decoded['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    if (isset($button['callback_data']) && str_starts_with((string) $button['callback_data'], $prefix)) {
                        return $button;
                    }
                }
            }

            return null;
        }

        // ---- AC 1: суффикс открывает карточку выбранной базы под сигналом её Вышки ----

        public function testSuffixedCallbackShowsChosenBaseLevelUnderTowerSignal(): void
        {
            $this->seedMapLine(0, 300, 1000);
            $towerId    = $this->seedBuilding('Вышка связи', 'CommunicationTower');
            $handPumpId = $this->seedBuilding('Ручная скважина', 'HandPump');

            [$tgId, $charId] = $this->seedCharacter(300); // игрок не стоит ни на одной базе
            $this->seedBase($charId, 0);                   // база 1
            $base2 = $this->seedBase($charId, 1000);        // база 2
            $this->seedCharacterBuilding($charId, $handPumpId, 0, 10);   // база 1 — L10
            $this->seedCharacterBuilding($charId, $handPumpId, 1000, 3); // база 2 — L3
            $this->seedTower($charId, $towerId, 1000, 7); // 7 × 100 = 700 = дистанция до игрока

            $response = (new HandPumpHandler($this->cbq(
                $tgId,
                'building_' . $handPumpId . '_HandPump' . BaseCallbackSuffix::append('', $base2)
            )))->handle();

            $this->assertStringContainsString('3 lvl', $this->textOf($response));
            $this->assertStringNotContainsString('10 lvl', $this->textOf($response));
        }

        // ---- AC 2: чужая/неактивная/недоступная база из суффикса — честный отказ, без утечки ----

        public function testForeignOrInactiveBaseSuffixGetsUnavailableAndDoesNotLeak(): void
        {
            $handPumpId = $this->seedBuilding('Ручная скважина', 'HandPump');

            [$tgId, $charId] = $this->seedCharacter(50);
            $own = $this->seedBase($charId, 50);
            $this->seedCharacterBuilding($charId, $handPumpId, 50, 4);

            // Чужая база — id другого персонажа.
            $foreignOwnerBase = $this->seedBase($charId + 999, 999);

            $response = (new HandPumpHandler($this->cbq(
                $tgId,
                'building_' . $handPumpId . '_HandPump' . BaseCallbackSuffix::append('', $foreignOwnerBase)
            )))->handle();

            $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
            $this->assertStringNotContainsString('4 lvl', $this->textOf($response));

            // Неактивная (abandoned) собственная база.
            $abandoned = $this->seedBase($charId, 60, 'abandoned');
            $responseAbandoned = (new HandPumpHandler($this->cbq(
                $tgId,
                'building_' . $handPumpId . '_HandPump' . BaseCallbackSuffix::append('', $abandoned)
            )))->handle();
            $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($responseAbandoned));

            // Сама своя активная база суффиксом продолжает работать штатно.
            $responseOwn = (new HandPumpHandler($this->cbq(
                $tgId,
                'building_' . $handPumpId . '_HandPump' . BaseCallbackSuffix::append('', $own)
            )))->handle();
            $this->assertStringContainsString('4 lvl', $this->textOf($responseOwn));
        }

        // ---- AC 4: кнопки без суффикса ведут себя как раньше ----

        public function testCallbackWithoutSuffixUsesLegacyResolve(): void
        {
            $handPumpId = $this->seedBuilding('Ручная скважина', 'HandPump');
            [$tgId, $charId] = $this->seedCharacter(100);
            $this->seedBase($charId, 100);
            $this->seedCharacterBuilding($charId, $handPumpId, 100, 4);

            $response = (new HandPumpHandler($this->cbq($tgId, 'building_' . $handPumpId . '_HandPump')))->handle();
            $this->assertStringContainsString('4 lvl', $this->textOf($response));
        }

        // ---- AC 3 (первая половина): кнопка «Поднять уровень» кончается на `_b<id2>`, strlen <= 64 ----

        public function testUpgradeButtonCarriesSuffixWithinByteLimit(): void
        {
            $this->seedMapLine(0, 1000);
            $handPumpId = $this->seedBuilding('Ручная скважина', 'HandPump');

            [$tgId, $charId] = $this->seedCharacter(1000); // игрок стоит на базе 2
            $this->seedBase($charId, 0);
            $base2 = $this->seedBase($charId, 1000);
            $this->seedCharacterBuilding($charId, $handPumpId, 0, 10);
            $this->seedCharacterBuilding($charId, $handPumpId, 1000, 3);

            $response = (new HandPumpHandler($this->cbq(
                $tgId,
                'building_' . $handPumpId . '_HandPump' . BaseCallbackSuffix::append('', $base2)
            )))->handle();

            $button = $this->findButton($response, 'upgrade_building_');
            $this->assertNotNull($button, 'кнопка "Поднять уровень" должна быть в клавиатуре');
            $expectedSuffix = '_b' . $base2;
            $this->assertStringEndsWith($expectedSuffix, (string) $button['callback_data']);
            $this->assertLessThanOrEqual(64, strlen((string) $button['callback_data']));
        }

        // ---- AC 3 (вторая половина): апгрейд по суффиксу правит СТРОКУ базы-2, база-1 не трогается ----
        //
        // Идёт через реальный `BuildingUpgradeValidator::validate($baseId)` +
        // `BuildingUpgradeApplier::apply()` — тот же слой, что эталонный
        // `BuildingUpgradeBaseScopeTest`; `UpgradeBuildingAction::confirmUpgrade()`
        // здесь не вызывается (см. докблок класса — формируется вне `## Files`).

        public function testUpgradeWithBaseIdChangesOnlyThatBasesRow(): void
        {
            $handPumpId = $this->seedBuilding('Ручная скважина', 'HandPump');
            [, $charId] = $this->seedCharacter(1000);
            $base1 = $this->seedBase($charId, 0);
            $base2 = $this->seedBase($charId, 1000);
            $row1  = $this->seedCharacterBuilding($charId, $handPumpId, 0, 5);
            $row2  = $this->seedCharacterBuilding($charId, $handPumpId, 1000, 2);

            // Игрок физически стоит на базе 2 (нужно и для шага 1 валидатора —
            // «на базе», и для `resolveForBase`'s on_base) и апгрейдит именно её
            // через явный $baseId из суффикса — база 1 при этом не тронута.
            $character = ['id' => $charId, 'level' => 10, 'gold' => 1000, 'cell_number' => 1000];
            $requirements = [3 => ['level' => 1, 'gold' => 0, 'resources' => []]];

            $result = (new BuildingUpgradeValidator())->validate($character, $handPumpId, $requirements, $base2);
            $this->assertTrue($result['ok'], 'валидация должна пройти по явной базе-2');
            $this->assertSame($row2, (int) $result['context']['charBuilding']['id']);

            $applier = new BuildingUpgradeApplier(null, null, null, null, null, $this->statsServiceDouble());
            $applier->apply($character, $result['context']['charBuilding'], $result['context']['nextLevel'], $result['context']['requirements']);

            $this->assertSame(3, $this->rowLevel($row2), 'уровень поднялся у строки базы-2');
            $this->assertSame(5, $this->rowLevel($row1), 'строка базы-1 не тронута');

            // Явный foreign/недоступный baseId — валидатор честно отказывает, апгрейд не применяется.
            $foreign = $this->seedBase($charId + 999, 2000);
            $resultForeign = (new BuildingUpgradeValidator())->validate($character, $handPumpId, $requirements, $foreign);
            $this->assertFalse($resultForeign['ok']);
            $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $resultForeign['error']);
            $this->assertSame(5, $this->rowLevel($row1));
            $this->assertSame(3, $this->rowLevel($row2));
        }

        /** Двойник золота — эта история не трогает списание золота (Non-goals у соседней истории). */
        private function statsServiceDouble(): CharacterStatsService
        {
            return new class () extends CharacterStatsService {
                public function adjust(int $characterId, array $deltas, array $boundsOverride = []): ?array
                {
                    return null;
                }
            };
        }
    }
}
