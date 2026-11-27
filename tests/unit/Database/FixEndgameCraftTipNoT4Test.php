<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\Migrations\FixEndgameCraftTipNoT4;
use App\Services\Player\TipService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * H6.2 (h6-canon-1127, ask 8) — совет №47 `EndgameCraftT4` больше не говорит «T4»: по ADR-130 два верстака
 * и три уровня крафта. Новый заголовок и текст называют Профессиональный верстак, фракцию и квест захвата,
 * без чисел; откат возвращает текст, который лежит на проде (SELECT 2026-11-27), дословно.
 */
final class FixEndgameCraftTipNoT4Test extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2027-01-02-100000_FixEndgameCraftTipNoT4.php';
    }

    public function testNewTitleAndTextFollowTwoWorkbenchThreeLevelCanon(): void
    {
        $title   = FixEndgameCraftTipNoT4::NEW_TITLE_RU;
        $content = FixEndgameCraftTipNoT4::NEW_CONTENT;

        $this->assertStringContainsString('Профессиональный верстак', $content);
        $this->assertStringContainsString('фракци', $content);
        $this->assertStringContainsString('захват', $content);
        foreach (['title' => $title, 'content' => $content] as $what => $text) {
            $this->assertNotSame('', $text, $what);
            $this->assertStringNotContainsString('T4', $text, $what);
            $this->assertStringNotContainsString('Т4', $text, $what . ' (кириллица)');
            $this->assertDoesNotMatchRegularExpression('/\d/u', $text, $what . ': без чисел');
        }
        $this->assertSame(0, substr_count($content, '*') % 2, 'markdown: звёздочки парные');
        $this->assertSame(0, substr_count($content, '_') % 2, 'markdown: подчёркивания парные');
        $this->assertSame('EndgameCraftT4', FixEndgameCraftTipNoT4::TITLE_EN, 'ключ совета не меняется');

        $rendered = TipService::renderTip(['id' => 47, 'title_ru' => $title, 'tip_type' => 'эндгейм', 'content' => $content]);
        $this->assertLessThan(1024, mb_strlen($rendered));
    }

    public function testDownRestoresProdTextVerbatim(): void
    {
        $this->assertSame('🏆 Эндгейм-крафт (T4)', FixEndgameCraftTipNoT4::OLD_TITLE_RU);
        $this->assertSame(
            '🏆 *Топовый крафт (T4):* лучшие предметы требуют Профессиональный верстак + фракцию + пройденный квест. '
            . 'Это вершина развития — готовь ресурсы и репутацию заранее!',
            FixEndgameCraftTipNoT4::OLD_CONTENT,
        );
    }
}
