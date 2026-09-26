<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\TipService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-038 Фаза C — TipService: дедуп 15 дней + лог показа + микро-награда.
 *
 * Общая логика /tips и авто-рассылки → тестируется один раз здесь.
 *
 * @internal
 */
final class TipServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_tips', 'character_game_tips', 'characters', 'game_settings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_tips (id INT AUTO_INCREMENT PRIMARY KEY, title_ru VARCHAR(255) NULL, title_en VARCHAR(255) NULL, tip_type VARCHAR(32) NULL, content TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_game_tips (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, game_tip_id INT NOT NULL, viewed_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, experience DECIMAL(12,2) NOT NULL DEFAULT 0, agility DECIMAL(12,2) NOT NULL DEFAULT 0, intellect DECIMAL(12,2) NOT NULL DEFAULT 0, daily_tips_enabled TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    /** GameSettingsService кэширует значения 60с — чистим, чтобы награда не утекала между тестами. */
    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function seedTips(int $n): void
    {
        $db = Database::connect('tests');
        for ($i = 1; $i <= $n; $i++) {
            $db->table('game_tips')->insert([
                'title_ru' => "Совет {$i}", 'title_en' => "Tip{$i}", 'tip_type' => 'общие', 'content' => "Тело {$i}",
            ]);
        }
    }

    private function seedCharacter(): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['experience' => 1.00, 'agility' => 2.00, 'intellect' => 3.00, 'daily_tips_enabled' => 1]);
        return (int) $db->insertID();
    }

    public function testPickReturnsTipWhenPoolNotExhausted(): void
    {
        $this->seedTips(3);
        $charId = $this->seedCharacter();

        $tip = (new TipService())->pickForCharacter($charId);
        $this->assertIsArray($tip);
        $this->assertArrayHasKey('id', $tip);
    }

    public function testServeRecordsViewAndRewards(): void
    {
        $this->seedTips(3);
        $charId = $this->seedCharacter();

        $tip = (new TipService())->serveTip($charId);
        $this->assertIsArray($tip);

        // Лог показа создан.
        $views = Database::connect('tests')->table('character_game_tips')
            ->where('character_id', $charId)->countAllResults();
        $this->assertSame(1, $views);

        // Микро-награда применена (1.00→1.01 / 2.00→2.02 / 3.00→3.04).
        $row = Database::connect('tests')->table('characters')->where('id', $charId)->get()->getRowArray();
        $this->assertEqualsWithDelta(1.01, (float) $row['experience'], 0.0001);
        $this->assertEqualsWithDelta(2.02, (float) $row['agility'], 0.0001);
        $this->assertEqualsWithDelta(3.04, (float) $row['intellect'], 0.0001);
    }

    public function testServeWithoutRewardRecordsViewButKeepsStats(): void
    {
        $this->seedTips(3);
        $charId = $this->seedCharacter();

        $tip = (new TipService())->serveTip($charId, false);
        $this->assertIsArray($tip);

        // Показ записан (общий 15-дневный дедуп) ...
        $views = Database::connect('tests')->table('character_game_tips')
            ->where('character_id', $charId)->countAllResults();
        $this->assertSame(1, $views);

        // ... а статы не тронуты (авто-рассылка не качает, d1-relevel-l1-l2).
        $row = Database::connect('tests')->table('characters')->where('id', $charId)->get()->getRowArray();
        $this->assertEqualsWithDelta(1.00, (float) $row['experience'], 0.0001);
        $this->assertEqualsWithDelta(2.00, (float) $row['agility'], 0.0001);
        $this->assertEqualsWithDelta(3.00, (float) $row['intellect'], 0.0001);
    }

    public function testRewardComesFromGameSettings(): void
    {
        $this->seedTips(1);
        $charId = $this->seedCharacter();
        $db     = Database::connect('tests');
        foreach (['tips.reward.experience' => 0.5, 'tips.reward.agility' => 0.25, 'tips.reward.intellect' => 0.75] as $key => $v) {
            $db->table('game_settings')->insert([
                'setting_key' => $key, 'category' => 'world', 'value_type' => 'float', 'value_float' => $v,
                'hard_min' => '0', 'hard_max' => '1',
            ]);
        }

        (new TipService())->serveTip($charId);

        $row = $db->table('characters')->where('id', $charId)->get()->getRowArray();
        $this->assertEqualsWithDelta(1.50, (float) $row['experience'], 0.0001);
        $this->assertEqualsWithDelta(2.25, (float) $row['agility'], 0.0001);
        $this->assertEqualsWithDelta(3.75, (float) $row['intellect'], 0.0001);
    }

    public function testRecentlyViewedTipExcludedFromPick(): void
    {
        $this->seedTips(2);
        $charId = $this->seedCharacter();
        $db = Database::connect('tests');

        // Совет id=1 просмотрен вчера (внутри 15-дневного окна) → исключён.
        $db->table('character_game_tips')->insert([
            'character_id' => $charId, 'game_tip_id' => 1, 'viewed_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $tip = (new TipService())->pickForCharacter($charId);
            $this->assertIsArray($tip);
            $this->assertSame(2, (int) $tip['id'], 'Виденный совет #1 не должен выпадать');
        }
    }

    public function testOldViewDoesNotExclude(): void
    {
        $this->seedTips(1);
        $charId = $this->seedCharacter();
        $db = Database::connect('tests');

        // Просмотр 20 дней назад (вне окна 15) → совет снова доступен.
        $db->table('character_game_tips')->insert([
            'character_id' => $charId, 'game_tip_id' => 1, 'viewed_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
        ]);

        $tip = (new TipService())->pickForCharacter($charId);
        $this->assertIsArray($tip);
        $this->assertSame(1, (int) $tip['id']);
    }

    public function testPoolExhaustedReturnsNull(): void
    {
        $this->seedTips(1);
        $charId = $this->seedCharacter();

        // Единственный совет просмотрен сейчас → пул исчерпан.
        Database::connect('tests')->table('character_game_tips')->insert([
            'character_id' => $charId, 'game_tip_id' => 1, 'viewed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertNull((new TipService())->pickForCharacter($charId));
    }

    public function testRenderContainsKeyFields(): void
    {
        $html = TipService::renderTip(['id' => 7, 'title_ru' => 'Заголовок', 'tip_type' => 'крафт', 'content' => 'Содержимое']);
        $this->assertStringContainsString('Роби', $html);
        $this->assertStringContainsString('Заголовок', $html);
        $this->assertStringContainsString('крафт', $html);
        $this->assertStringContainsString('Содержимое', $html);
    }
}
