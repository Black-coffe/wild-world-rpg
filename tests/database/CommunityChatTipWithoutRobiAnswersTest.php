<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr176SeedCommunityChatTip;
use App\Database\Migrations\CommunityChatTipWithoutRobiAnswers;
use App\Services\Player\TipService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * HONEST-OFF (honest-off-1127) — совет №143 «💬 Общий чат сообщества» больше не обещает, что в чате отвечает
 * сам Роби: `community.enabled` выключен, на проде `community_messages` = 0. Тест сидит совет настоящей
 * seed-миграцией ADR-176, переписывает его миграцией-фиксом и откатывает обратно.
 *
 * @internal
 */
final class CommunityChatTipWithoutRobiAnswersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-08-25-110000_Adr176SeedCommunityChatTip.php';
        require_once APPPATH . 'Database/Migrations/2026-12-31-100000_CommunityChatTipWithoutRobiAnswers.php';
        $db = Database::connect('tests');
        $t  = $db->prefixTable('game_tips');
        $db->query("DROP TABLE IF EXISTS {$t}");
        $db->query("CREATE TABLE {$t} (id INT AUTO_INCREMENT PRIMARY KEY, title_ru VARCHAR(255) NULL, title_en VARCHAR(255) NULL, tip_type VARCHAR(32) NULL, content TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable('game_tips'));
        parent::tearDown();
    }

    public function testTipNoLongerPromisesRobiAnswersAndDownRestoresSeedText(): void
    {
        $forge = Database::forge('tests');
        (new Adr176SeedCommunityChatTip($forge))->up();

        $seed = (string) $this->tip()['content'];
        $this->assertStringContainsString('Роби', $seed, 'исходный случай: сид обещает ответ Роби');

        (new CommunityChatTipWithoutRobiAnswers($forge))->up();
        (new CommunityChatTipWithoutRobiAnswers($forge))->up(); // повтор — тот же текст

        $row     = $this->tip();
        $content = (string) $row['content'];
        $this->assertStringNotContainsString('Роби', $content);
        $this->assertStringNotContainsString('отвечает', $content);
        $this->assertStringContainsString('живые выжившие помогают друг другу', $content);
        $this->assertStringContainsString('*/guide*', $content);
        $this->assertStringContainsString('*«💬 Общий чат»*', $content);
        $this->assertStringContainsString('куда с личным вопросом', $content);
        $this->assertSame(0, substr_count($content, '*') % 2, 'markdown: звёздочки парные');
        $this->assertDoesNotMatchRegularExpression('/\d/u', $content, 'без чисел');
        $this->assertSame('общие', $row['tip_type']);
        $this->assertSame(1, Database::connect('tests')->table('game_tips')->where('title_en', 'Adr176CommunityChatExists')->countAllResults());
        $this->assertLessThan(1024, mb_strlen(TipService::renderTip($row)));

        (new CommunityChatTipWithoutRobiAnswers($forge))->down();
        $this->assertSame($seed, (string) $this->tip()['content'], 'откат возвращает текст сида дословно');
        $this->assertSame($seed, CommunityChatTipWithoutRobiAnswers::OLD_CONTENT);
    }

    /** @return array<string, mixed> */
    private function tip(): array
    {
        $row = Database::connect('tests')->table('game_tips')->where('title_en', 'Adr176CommunityChatExists')->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }
}
