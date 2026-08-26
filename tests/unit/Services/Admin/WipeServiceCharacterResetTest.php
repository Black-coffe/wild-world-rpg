<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Services\Admin\WipeService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story community-chat-bot-60/74/77 — `WipeService::resetCharacter()` должен чистить
 * `community_messages` персонажа так же, как чистит его `player_action_log`, потому что
 * `Config\WipeManifest` классифицирует таблицу `PLAYER_DATA` (а не `TRANSIENT`).
 *
 * Схема `community_messages` берётся из реальной миграции `Adr176CreateCommunityMessagesTable`
 * (Forge, паттерн `CommunityIngestServiceTest`), а не сочиняется руками — ровно та таблица,
 * которую переклассифицировали. `characters`/`telegram_users`/`map` — узкая ручная схема под
 * нужды `resetCharacter()` (паттерн `AchievementServiceTest`/`DefenseStructureServiceTest`),
 * эти таблицы не были предметом дефекта.
 *
 * 🔴 story community-chat-bot-74: первая версия этого теста сеяла `characters.telegram_user_id`
 * и `community_messages.telegram_user_id` ОДНИМ И ТЕМ ЖЕ значением (1001) — то есть кодировала
 * ошибочное предположение, что это одно пространство id. На деле `characters.telegram_user_id` —
 * ВНУТРЕННИЙ `telegram_users.id` (join в `CharacterModel`), а `community_messages.telegram_user_id`
 * — СЫРОЙ Telegram `from.id` (`CommunityIngestService`). Тест был зелёным на вымысле: мост
 * между системами id отсутствовал, `resetCharacter()` удалял 0 строк, а тест этого не ловил.
 * Теперь внутренний id (7 / 8) и сырой Telegram-id (584213905 / 112233445) — разные числа,
 * связанные только через `telegram_users` — и тест проверяет именно мост
 * (`WipeService::rawTelegramIdOf()`), а не совпадение цифр.
 *
 * Тест использует боевой `Config\WipeManifest` без подмен — красен на любой из двух поломок:
 * стратегию `community_messages` вернуть в `TRANSIENT` (цикл `resetCharacter()` пропустит
 * таблицу) или `by` вернуть в `'telegram'` (сравнение внутреннего id с сырым не даст совпадений).
 *
 * 🔴 story community-chat-bot-77: `previewCharacter()` (сосед `resetCharacter()` в том же файле)
 * остался на прежней бинарной развилке `$by === 'telegram' ? $tgId : $characterId` уже ПОСЛЕ
 * того, как `resetCharacter()` починили на `resolveMatchId()` — превью для `community_messages`
 * подставляло id персонажа в колонку с сырым Telegram-id и показывало владельцу 0 строк там,
 * где сброс удалял всё. Разрешение id вынесено в один метод `WipeService::resolveMatchId()`,
 * которым обязаны пользоваться ОБА метода — `testPreviewCharacterCountsSameRowsAsReset...`
 * проверяет именно это: превью и сброс видят одно и то же число на разных id-пространствах.
 *
 * @internal
 */
final class WipeServiceCharacterResetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'telegram_users', 'map', 'community_messages'];

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        // Узкая ручная схема — покрывает все поля, которые трогает resetCharacter()
        // (WipeManifest::$characterResetValues + cell_number/biome_id/updated_at).
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(64) NULL,
                level INT DEFAULT 1,
                experience DECIMAL(10,2) DEFAULT 0,
                health INT DEFAULT 100,
                tired INT DEFAULT 100,
                strength DECIMAL(10,2) DEFAULT 0,
                agility DECIMAL(10,2) DEFAULT 0,
                intellect DECIMAL(10,2) DEFAULT 0,
                specialization VARCHAR(32) NULL,
                gold INT DEFAULT 0,
                combat_drone_active_until DATETIME NULL,
                weight_capacity INT DEFAULT 0,
                trading_karma INT DEFAULT 100,
                insurance INT DEFAULT 0,
                has_renamed TINYINT DEFAULT 0,
                last_name_change DATETIME NULL,
                low_health_notified_at DATETIME NULL,
                last_message_id INT NULL,
                endgame_state VARCHAR(16) DEFAULT \'active\',
                endgame_lock_at DATETIME NULL,
                last_respawn_at DATETIME NULL,
                last_planted_crop DATETIME NULL,
                well_fed_until DATETIME NULL,
                specialization_changed_at DATETIME NULL,
                whats_new_seen TEXT NULL,
                npc_kills INT DEFAULT 0,
                login_streak INT DEFAULT 0,
                login_streak_last_day DATE NULL,
                active_title_id INT NULL,
                active_vehicle_log_id INT NULL,
                cell_number INT NULL,
                biome_id INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        // ВНУТРЕННИЙ id (characters.telegram_user_id ссылается сюда) ↔ telegram_id — СЫРОЙ
        // Telegram from.id. Ровно два поля нужны rawTelegramIdOf(), остальная схема telegram_users
        // тут не нужна.
        $db->query('
            CREATE TABLE telegram_users (
                id INT NOT NULL PRIMARY KEY,
                telegram_id BIGINT NOT NULL
            )
        ');

        // Пусто — spawnCells() вернёт [], respawned=false, cell_number/biome_id трогать не нужно.
        $db->query('CREATE TABLE map (cell_number INT NULL, biome_id INT NULL, coordinate_y INT NULL)');

        $this->requireMigrationClass();
        $forge = Database::forge('tests');
        (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->up();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    /**
     * Файл миграции назван с датой-префиксом (конвенция CI4 MigrationLocator, а не PSR-4),
     * composer-автозагрузчик его не видит — требуется вручную, как в `CommunityIngestServiceTest`.
     */
    private function requireMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityMessagesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php';
        }
    }

    /**
     * @param int $internalTelegramUserId `telegram_users.id` — то, на что ссылается
     *            `characters.telegram_user_id` (конвенция CharacterModel).
     * @param int $rawTelegramId          `telegram_users.telegram_id` — сырой Telegram
     *            `from.id`, тем же значением пишет `CommunityIngestService` в
     *            `community_messages.telegram_user_id`.
     */
    private function seedCharacter(int $internalTelegramUserId, int $rawTelegramId): int
    {
        $db = Database::connect('tests');
        $db->table('telegram_users')->insert([
            'id'          => $internalTelegramUserId,
            'telegram_id' => $rawTelegramId,
        ]);
        $db->table('characters')->insert([
            'telegram_user_id' => $internalTelegramUserId,
            'name'              => 'char' . $internalTelegramUserId,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @param list<int> $messageIds */
    private function seedCommunityMessages(int $rawTelegramId, array $messageIds): void
    {
        $db = Database::connect('tests');
        foreach ($messageIds as $messageId) {
            $db->table('community_messages')->insert([
                'chat_id'          => -1009999,
                'message_id'       => $messageId,
                'telegram_user_id' => $rawTelegramId,
                'username'         => 'user' . $rawTelegramId,
                'text'             => 'сообщение ' . $messageId,
                'sent_at'          => date('Y-m-d H:i:s'),
                'is_question'      => 0,
                'addressed_to_bot' => 0,
                'status'           => 'new',
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function testResetCharacterDeletesOnlyItsOwnCommunityMessagesAcrossKeySpaces(): void
    {
        $db = Database::connect('tests');

        // Внутренний id и сырой Telegram-id намеренно НЕ совпадают числом — тест обязан
        // проверять мост через telegram_users, а не подстановку одинаковых цифр.
        $charA = $this->seedCharacter(7, 584213905);
        $charB = $this->seedCharacter(8, 112233445);

        $this->seedCommunityMessages(584213905, [1, 2, 3]);
        $this->seedCommunityMessages(112233445, [4, 5]);

        $service = new WipeService(null, $db);
        $result  = $service->resetCharacter($charA);

        $remainingA = $db->table('community_messages')->where('telegram_user_id', 584213905)->countAllResults();
        $remainingB = $db->table('community_messages')->where('telegram_user_id', 112233445)->countAllResults();

        $this->assertSame(0, $remainingA, 'Сообщения персонажа A (по его сырому Telegram-id) должны быть удалены сбросом.');
        $this->assertSame(2, $remainingB, 'Сообщения персонажа B — чужие, сброс их не должен трогать.');
        $this->assertArrayHasKey('community_messages', $result['deleted']);
        $this->assertSame(3, $result['deleted']['community_messages']);

        // Персонаж B не тронут в принципе.
        $this->assertNotNull($charB);
    }

    public function testPreviewCharacterCountsSameRowsAsResetAcrossKeySpaces(): void
    {
        $db = Database::connect('tests');

        $charA = $this->seedCharacter(7, 584213905);
        $charB = $this->seedCharacter(8, 112233445);

        $this->seedCommunityMessages(584213905, [1, 2, 3]);
        $this->seedCommunityMessages(112233445, [4, 5]);

        $service = new WipeService(null, $db);
        $preview = $service->previewCharacter($charA);

        $this->assertArrayHasKey(
            'community_messages',
            $preview,
            'Превью обязано видеть сообщения персонажа A по мосту через telegram_users, а не по совпадению с internal id.'
        );
        $this->assertSame(
            3,
            $preview['community_messages'],
            'Превью обязано показать ровно то число, которое удалит resetCharacter() — не 0.'
        );

        // Превью и реальный сброс считают ОДНО И ТО ЖЕ число строк на одних и тех же данных —
        // не совпадение реализаций, а гарантия общего resolveMatchId().
        $result = $service->resetCharacter($charA);
        $this->assertSame($preview['community_messages'], $result['deleted']['community_messages']);

        $this->assertNotNull($charB);
    }
}
