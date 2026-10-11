<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\FixArmorResistancesTipMarkdown;
use App\Database\Migrations\V30SeedContentPassTipsV6ToV19;
use App\Services\Player\TipService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Совет «🛡 Сопротивления брони (PvP)» ломал рассылку: `armor_value` открывал в legacy-Markdown курсив без пары,
 * Telegram отвечал «can't parse entities … byte offset 542». Тест сидит советы настоящей seed-миграцией V30, чинит
 * их миграцией-фиксом и проверяет, что каждый отрендеренный совет из этого сида проходит разбор legacy-Markdown.
 *
 * @internal
 */
final class FixArmorResistancesTipMarkdownTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-05-29-100000_V30SeedContentPassTipsV6ToV19.php';
        require_once APPPATH . 'Database/Migrations/2026-12-19-100000_FixArmorResistancesTipMarkdown.php';
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_tips');
        $db->query('CREATE TABLE game_tips (id INT AUTO_INCREMENT PRIMARY KEY, title_ru VARCHAR(255) NULL, title_en VARCHAR(255) NULL, tip_type VARCHAR(32) NULL, content TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4');
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_tips');
        parent::tearDown();
    }

    public function testSeedTextBrokeMarkdownAtByte542AndFixRepairsIt(): void
    {
        $forge = Database::forge('tests');
        (new V30SeedContentPassTipsV6ToV19($forge))->up();

        $tip = $this->tip();
        $this->assertSame(542, self::firstUnclosedEntityByte(TipService::renderTip(['id' => 68] + $tip)), 'исходный случай прода');

        (new FixArmorResistancesTipMarkdown($forge))->up();
        (new FixArmorResistancesTipMarkdown($forge))->up(); // повтор — no-op

        $content = (string) $this->tip()['content'];
        $this->assertStringNotContainsString('armor_value', $content);
        $this->assertStringContainsString('учитывается только базовая защита брони', $content);

        $rows = Database::connect('tests')->table('game_tips')->get()->getResultArray();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNull(
                self::firstUnclosedEntityByte(TipService::renderTip($row)),
                "совет {$row['title_en']}: незакрытая Markdown-сущность"
            );
        }

        (new FixArmorResistancesTipMarkdown($forge))->down();
        $this->assertStringContainsString('armor_value', (string) $this->tip()['content']);
    }

    /** @return array<string, mixed> */
    private function tip(): array
    {
        $row = Database::connect('tests')->table('game_tips')->where('title_en', 'ArmorResistancesPvP')->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * Байтовое смещение первой незакрытой сущности legacy-Markdown Telegram (`*`, `_`, `` ` ``, ```` ``` ````, `[…](…)`),
     * как его называет ошибка «can't find end of the entity starting at byte offset N»; null — текст разбирается.
     */
    private static function firstUnclosedEntityByte(string $t): ?int
    {
        $n = strlen($t);
        $i = 0;
        while ($i < $n) {
            $c = $t[$i];
            if ($c === '*' || $c === '_' || $c === '`') {
                $tok = substr($t, $i, 3) === '```' ? '```' : $c;
                $j   = strpos($t, $tok, $i + strlen($tok));
                if ($j === false) {
                    return $i;
                }
                $i = $j + strlen($tok);

                continue;
            }
            if ($c === '[') {
                $j = strpos($t, ']', $i + 1);
                if ($j === false) {
                    return $i;
                }
                $i = $j + 1;
                if ($i < $n && $t[$i] === '(') {
                    $k = strpos($t, ')', $i);
                    if ($k === false) {
                        return $i;
                    }
                    $i = $k + 1;
                }

                continue;
            }
            $i++;
        }

        return null;
    }
}
