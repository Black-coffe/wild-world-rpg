<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GameSettings;

use App\Database\Migrations\Adr176CommunityGameSettings;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Story community-chat-bot-03 — идемпотентность и полнота seed-миграции
 * `Adr176CommunityGameSettings` (15 ключей килсвитчей/порогов/лимитов чат-бота).
 *
 * Как и другие тесты вокруг `game_settings` в этом наборе (см. AchievementServiceTest,
 * RobotReachSingleSourceTest) — таблица создаётся вручную по продовой схеме
 * (CreateGameSettingsTable), а не через `$migrate = true`, чтобы тест был независим
 * от порядка/состояния миграций тестовой БД. Миграция инстанцируется напрямую с
 * Forge на группу `tests`, что даёт реальный прогон продового кода `up()`/`down()`.
 *
 * Класс миграции не грузится composer'овским PSR-4 автозагрузчиком: файл миграции
 * назван с датой-префиксом (`2026-08-25-100200_Adr176...php`), это конвенция CI4
 * MigrationLocator, а не PSR-4. Файл требуется вручную.
 *
 * @internal
 */
final class CommunitySettingsSeedTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const EXPECTED_KEYS = [
        'community.enabled',
        'community.chat_id',
        'community.autoreply.enabled',
        'community.autoreply.delay_seconds',
        'community.autoreply.max_per_hour_per_topic',
        'community.autoreply.author_cooldown_seconds',
        'community.autoreply.max_answer_chars',
        'community.autoreply.silent_topics',
        'community.match.threshold_addressed',
        'community.match.threshold_overheard',
        'community.answer.max_age_days',
        'community.question.max_age_hours',
        'community.ingest.max_questions_per_author_per_hour',
        'community.retention_days',
        'community.moderation.mode',
    ];

    private bool $createdGameSettings = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        if (! $db->tableExists('game_settings')) {
            $db->query('
                CREATE TABLE game_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(64) NOT NULL,
                    category VARCHAR(32) NOT NULL,
                    value_type VARCHAR(16) NOT NULL,
                    value_int INT NULL,
                    value_float DECIMAL(12,4) NULL,
                    value_bool TINYINT NULL,
                    value_string VARCHAR(255) NULL,
                    default_value_text TEXT NOT NULL,
                    rationale_text TEXT NOT NULL,
                    effect_text TEXT NOT NULL,
                    above_effect_text TEXT NOT NULL,
                    below_effect_text TEXT NOT NULL,
                    recommended_min VARCHAR(64) NULL,
                    recommended_max VARCHAR(64) NULL,
                    hard_min VARCHAR(64) NULL,
                    hard_max VARCHAR(64) NULL,
                    updated_by VARCHAR(128) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY setting_key (setting_key)
                )
            ');
            $this->createdGameSettings = true;
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->whereIn('setting_key', self::EXPECTED_KEYS)->delete();

        if ($this->createdGameSettings) {
            $db->query('DROP TABLE IF EXISTS game_settings');
        }

        parent::tearDown();
    }

    private function migration(): Adr176CommunityGameSettings
    {
        if (! class_exists(Adr176CommunityGameSettings::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100200_Adr176CommunityGameSettings.php';
        }

        $forge = Database::forge('tests');

        return new Adr176CommunityGameSettings($forge instanceof Forge ? $forge : null);
    }

    public function testUpSeedsExactlyTheDocumentedKeys(): void
    {
        $this->migration()->up();

        $db   = Database::connect('tests');
        $rows = $db->table('game_settings')
            ->whereIn('setting_key', self::EXPECTED_KEYS)
            ->get()->getResultArray();

        $keys = array_column($rows, 'setting_key');
        sort($keys);
        $expected = self::EXPECTED_KEYS;
        sort($expected);

        $this->assertSame($expected, $keys, 'миграция обязана посеять ровно эти 15 ключей, не больше и не меньше');
    }

    public function testUpIsIdempotent(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $db    = Database::connect('tests');
        $count = $db->table('game_settings')->whereIn('setting_key', self::EXPECTED_KEYS)->countAllResults();

        $this->assertSame(count(self::EXPECTED_KEYS), $count, 'повторный up() не должен создавать дубли');
    }

    public function testNoRowHasEmptyRationaleAboveOrBelowText(): void
    {
        $this->migration()->up();

        $db   = Database::connect('tests');
        $rows = $db->table('game_settings')->whereIn('setting_key', self::EXPECTED_KEYS)->get()->getResultArray();

        $this->assertCount(count(self::EXPECTED_KEYS), $rows);

        foreach ($rows as $row) {
            $this->assertNotSame('', trim((string) $row['rationale_text']), $row['setting_key'] . ': rationale_text пуст');
            $this->assertNotSame('', trim((string) $row['above_effect_text']), $row['setting_key'] . ': above_effect_text пуст');
            $this->assertNotSame('', trim((string) $row['below_effect_text']), $row['setting_key'] . ': below_effect_text пуст');
            $this->assertNotSame('', trim((string) $row['effect_text']), $row['setting_key'] . ': effect_text пуст');
        }
    }

    public function testKillswitchesStartDisabled(): void
    {
        $this->migration()->up();

        $db = Database::connect('tests');

        $enabled = $db->table('game_settings')->where('setting_key', 'community.enabled')->get()->getRowArray();
        $auto    = $db->table('game_settings')->where('setting_key', 'community.autoreply.enabled')->get()->getRowArray();

        $this->assertSame(0, (int) $enabled['value_bool'], 'community.enabled обязан стартовать выключенным');
        $this->assertSame(0, (int) $auto['value_bool'], 'community.autoreply.enabled обязан стартовать выключенным');
    }

    public function testNumericKeysHaveHardBounds(): void
    {
        $this->migration()->up();

        $numericKeys = [
            'community.autoreply.delay_seconds',
            'community.autoreply.max_per_hour_per_topic',
            'community.autoreply.author_cooldown_seconds',
            'community.autoreply.max_answer_chars',
            'community.match.threshold_addressed',
            'community.match.threshold_overheard',
            'community.answer.max_age_days',
            'community.question.max_age_hours',
            'community.ingest.max_questions_per_author_per_hour',
            'community.retention_days',
        ];

        $db = Database::connect('tests');

        foreach ($numericKeys as $key) {
            $row = $db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();

            $this->assertNotNull($row['hard_min'], $key . ': hard_min не задан');
            $this->assertNotNull($row['hard_max'], $key . ': hard_max не задан');
        }

        $delay = $db->table('game_settings')->where('setting_key', 'community.autoreply.delay_seconds')->get()->getRowArray();
        $this->assertSame(0.0, (float) $delay['hard_min'], 'delay_seconds не может стать отрицательным');

        $perHour = $db->table('game_settings')->where('setting_key', 'community.autoreply.max_per_hour_per_topic')->get()->getRowArray();
        $this->assertLessThanOrEqual(50, (float) $perHour['hard_max'], 'max_per_hour_per_topic обязан иметь разумный потолок');
    }

    public function testDownRemovesExactlyTheseKeysAndNothingElse(): void
    {
        $this->migration()->up();

        $db = Database::connect('tests');
        $db->table('game_settings')->insert([
            'setting_key'        => 'unrelated.probe',
            'category'           => 'experimental',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => 'probe',
            'effect_text'        => 'probe',
            'above_effect_text'  => 'probe',
            'below_effect_text'  => 'probe',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->migration()->down();

        $remainingCommunity = $db->table('game_settings')->whereIn('setting_key', self::EXPECTED_KEYS)->countAllResults();
        $this->assertSame(0, $remainingCommunity, 'down() обязан удалить все community.* ключи');

        $probeStillThere = $db->table('game_settings')->where('setting_key', 'unrelated.probe')->countAllResults();
        $this->assertSame(1, $probeStillThere, 'down() не должен трогать ключи вне списка community.*');

        $db->table('game_settings')->where('setting_key', 'unrelated.probe')->delete();
    }
}
