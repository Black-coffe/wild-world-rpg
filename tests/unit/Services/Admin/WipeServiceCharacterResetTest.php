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
 * Story community-chat-bot-60 — `WipeService::resetCharacter()` должен чистить
 * `community_messages` персонажа так же, как чистит его `player_action_log`, потому что
 * `Config\WipeManifest` классифицирует таблицу `PLAYER_DATA` (а не `TRANSIENT`).
 *
 * Схема `community_messages` берётся из реальной миграции `Adr176CreateCommunityMessagesTable`
 * (Forge, паттерн `CommunityIngestServiceTest`), а не сочиняется руками — ровно та таблица,
 * которую переклассифицировали. `characters`/`map` — узкая ручная схема под нужды
 * `resetCharacter()` (паттерн `AchievementServiceTest`/`DefenseStructureServiceTest`), эти
 * таблицы не были предметом story.
 *
 * Тест использует боевой `Config\WipeManifest` без подмен: если стратегию `community_messages`
 * вернуть в `TRANSIENT`, цикл `resetCharacter()` пропустит таблицу (`$d['strategy'] !==
 * PLAYER_DATA` → continue) и строки персонажа A не удалятся — тест покраснеет.
 *
 * @internal
 */
final class WipeServiceCharacterResetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'map', 'community_messages'];

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
                telegram_user_id BIGINT NULL,
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

    private function seedCharacter(int $telegramUserId): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert([
            'telegram_user_id' => $telegramUserId,
            'name'              => 'char' . $telegramUserId,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @param list<int> $messageIds */
    private function seedCommunityMessages(int $telegramUserId, array $messageIds): void
    {
        $db = Database::connect('tests');
        foreach ($messageIds as $messageId) {
            $db->table('community_messages')->insert([
                'chat_id'          => -1009999,
                'message_id'       => $messageId,
                'telegram_user_id' => $telegramUserId,
                'username'         => 'user' . $telegramUserId,
                'text'             => 'сообщение ' . $messageId,
                'sent_at'          => date('Y-m-d H:i:s'),
                'is_question'      => 0,
                'addressed_to_bot' => 0,
                'status'           => 'new',
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function testResetCharacterDeletesOnlyItsOwnCommunityMessages(): void
    {
        $db = Database::connect('tests');

        $charA = $this->seedCharacter(1001);
        $charB = $this->seedCharacter(1002);

        $this->seedCommunityMessages(1001, [1, 2, 3]);
        $this->seedCommunityMessages(1002, [4, 5]);

        $service = new WipeService(null, $db);
        $result  = $service->resetCharacter($charA);

        $remainingA = $db->table('community_messages')->where('telegram_user_id', 1001)->countAllResults();
        $remainingB = $db->table('community_messages')->where('telegram_user_id', 1002)->countAllResults();

        $this->assertSame(0, $remainingA, 'Сообщения персонажа A должны быть удалены сбросом.');
        $this->assertSame(2, $remainingB, 'Сообщения персонажа B — чужие, сброс их не должен трогать.');
        $this->assertArrayHasKey('community_messages', $result['deleted']);
        $this->assertSame(3, $result['deleted']['community_messages']);

        // Персонаж B не тронут в принципе.
        $this->assertNotNull($charB);
    }
}
