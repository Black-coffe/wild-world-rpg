<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\WhatsNewService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W7b (ADR-065 Part 2) — WhatsNewService: killswitch + темы + seen-трекинг (JSON).
 *
 * @internal
 */
final class WhatsNewServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL
            )
        ');

        $db->query('DROP TABLE IF EXISTS whats_new_topics');
        $db->query('
            CREATE TABLE whats_new_topics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic_key VARCHAR(64) NOT NULL,
                title VARCHAR(255) NULL,
                summary VARCHAR(255) NULL,
                image_path VARCHAR(255) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                content TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                whats_new_seen TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['game_settings', 'whats_new_topics', 'characters'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function seedTopic(string $key, int $order, string $title = 'T', string $content = 'C'): void
    {
        Database::connect('tests')->table('whats_new_topics')->insert([
            'topic_key' => $key, 'title' => $title, 'summary' => 's',
            'image_path' => 'x.jpg', 'sort_order' => $order, 'content' => $content,
        ]);
    }

    private function seedCharacter(?string $seenJson = null): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['telegram_user_id' => 1, 'whats_new_seen' => $seenJson]);
        return (int) $db->insertID();
    }

    private function seedEnabled(int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'onboarding.whats_new.enabled', 'category' => 'world',
            'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    public function testEnabledByDefaultWhenNoSetting(): void
    {
        $this->assertTrue((new WhatsNewService())->isEnabled());
    }

    public function testKillswitchOff(): void
    {
        $this->seedEnabled(0);
        $this->assertFalse((new WhatsNewService())->isEnabled());
    }

    public function testAllTopicsOrderedBySortOrder(): void
    {
        $this->seedTopic('b', 20);
        $this->seedTopic('a', 10);
        $this->seedTopic('c', 30);

        $topics = (new WhatsNewService())->allTopics();
        $keys   = array_map(static fn ($t) => $t['topic_key'], $topics);
        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    public function testTopicByKey(): void
    {
        $this->seedTopic('drones', 10, 'Дроны', 'тело');
        $svc = new WhatsNewService();

        $topic = $svc->topicByKey('drones');
        $this->assertIsArray($topic);
        $this->assertSame('Дроны', $topic['title']);
        $this->assertNull($svc->topicByKey('nope'));
    }

    public function testSeenKeysEmptyByDefault(): void
    {
        $charId = $this->seedCharacter();
        $this->assertSame([], (new WhatsNewService())->seenKeys($charId));
    }

    public function testMarkSeenPersistsAndIsIdempotent(): void
    {
        $charId = $this->seedCharacter();
        $svc    = new WhatsNewService();

        $svc->markSeen($charId, 'crafting');
        $svc->markSeen($charId, 'crafting'); // дубль игнорируется
        $svc->markSeen($charId, 'robots');

        $keys = $svc->seenKeys($charId);
        sort($keys);
        $this->assertSame(['crafting', 'robots'], $keys);
        $this->assertTrue($svc->isSeen($charId, 'crafting'));
        $this->assertFalse($svc->isSeen($charId, 'drones'));
    }

    public function testSeenKeysIgnoresMalformedJson(): void
    {
        $charId = $this->seedCharacter('not-a-json');
        $this->assertSame([], (new WhatsNewService())->seenKeys($charId));
    }

    public function testImageRelFallbackForMissingFile(): void
    {
        $fallback = 'uploads/telegram/character/final-step-image.jpg';
        $this->assertSame($fallback, WhatsNewService::imageRelOrFallback('uploads/telegram/character/whatsnew/does-not-exist.jpg'));
        $this->assertSame($fallback, WhatsNewService::imageRelOrFallback(''));
    }

    /**
     * 🔴 Гард Telegram-лимита: каждая тема каталога — photo-caption (≤1024),
     * иначе sendPhoto/editMessageMedia молча падают (memory feedback_telegram_photo_caption_1024_limit).
     * Сканирует реальные строки миграции-сида, ловит будущий drift.
     */
    public function testSeededTopicCaptionsWithinTelegramLimit(): void
    {
        $file = APPPATH . 'Database/Migrations/2026-06-02-130000_W7bSeedWhatsNewTopics.php';
        $this->assertFileExists($file);

        $src    = (string) file_get_contents($file);
        $tokens = token_get_all($src);
        $found  = 0;
        foreach ($tokens as $t) {
            if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $literal = $t[1];
            if ($literal === '' || $literal[0] !== '"') {
                continue; // только двойные кавычки (caption'ы)
            }
            $val = stripcslashes(substr($literal, 1, -1));
            // эвристика: настоящий caption темы (многострочный markdown), не короткие summary/labels
            if (mb_strlen($val) > 200 && mb_strpos($val, "\n\n") !== false && mb_strpos($val, '*') !== false) {
                $found++;
                $this->assertLessThanOrEqual(
                    1024,
                    mb_strlen($val),
                    'Topic caption exceeds Telegram 1024 limit: ' . mb_substr($val, 0, 40)
                );
            }
        }
        $this->assertGreaterThanOrEqual(6, $found, 'Ожидалось 6 caption тем в миграции-сиде');
    }
}
