<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Controllers\AccountCabinet;
use App\Controllers\Telegram\Commands\Actions\WebLinkCodeAction;
use App\Database\Migrations\UpdateWebLinkTipForWebPlay;
use App\Database\Migrations\WebPlayTipTrueInBothFlagStates;
use App\Services\Onboarding\GuideCatalog;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * web-bridge-p1-03 — тексты `/web`, совета и `/guide web` говорят правду (код не привязывает
 * чужой вход, «выйди из другого входа и введи код»), игра на сайте описана верно при любом web.play_enabled (p1-10); «Играть»
 * виден в шапке сайта и в кабинете.
 *
 * @internal
 */
final class WebPlayTextsTest extends CIUnitTestCase
{
    private const INSTRUCTION = 'выйди из другого входа и введи код';

    /** @var list<string> формулировки старого обещания привязки */
    private const OLD_PROMISE = ['привяжет', 'привязать', 'привязывает', 'прикрепит'];

    private function assertTruthful(string $text, string $where): void
    {
        foreach (self::OLD_PROMISE as $promise) {
            $this->assertStringNotContainsStringIgnoringCase($promise, $text, "{$where}: old link promise «{$promise}»");
        }
        $this->assertStringContainsString(self::INSTRUCTION, $text, "{$where}: log-out-first instruction");
        $this->assertStringContainsString('«Играть» в шапке', $text, "{$where}: «Играть» in the site header is the way in");
        $this->assertTrueInBothFlagStates($text, $where);
    }

    /**
     * web-bridge-p1-10 (Ask 8) — текст не утверждает ни «закрыто / скоро / заглушка», ни «уже открыто»:
     * `web.play_enabled` переключается в админке без деплоя.
     */
    private function assertTrueInBothFlagStates(string $text, string $where): void
    {
        foreach (self::FLAG_STATE_CLAIMS as $claim) {
            $this->assertDoesNotMatchRegularExpression($claim, $text, "{$where}: flag-state claim {$claim}");
        }
        $this->assertStringContainsString('сейчас нельзя, страница сама', $text, "{$where}: /play itself explains a closed state");
    }

    /** @var list<string> утверждения о состоянии флага (закрыто / скоро / уже открыто) */
    private const FLAG_STATE_CLAIMS = [
        '/откро(?:ют|ется|ем)/iu', '/открыт[аоы]?\b/iu', '/заглушк/iu', '/скоро/iu', '/\bпока\b/iu', '/ещё не/iu', '/еще не/iu',
        '/появится/iu', '/запустят/iu', '/уже (?:можно|доступн|работает)/iu', '/теперь можно/iu',
    ];

    private function assertMarkdownBalanced(string $text, string $where): void
    {
        foreach (['*', '_', '`'] as $entity) {
            $this->assertSame(0, substr_count($text, $entity) % 2, "{$where}: unbalanced «{$entity}»");
        }
    }

    public function testWebMessageIsTruthfulAndKeepsCodePathAndLifetime(): void
    {
        $text = WebLinkCodeAction::codeMessage('ABCD2345', 15);

        $this->assertTruthful($text, '/web');
        $this->assertStringContainsString('ABCD2345', $text);
        $this->assertStringContainsString('wildworld.fun/account/link', $text);
        $this->assertStringContainsString('15 мин.', $text);
        $this->assertMarkdownBalanced($text, '/web');
    }

    public function testGuideWebSectionIsTruthfulAndNumberFree(): void
    {
        $section = GuideCatalog::find('web');
        $this->assertIsArray($section);
        $body = $section['body'];
        $this->assertIsString($body);

        $this->assertTruthful($body, 'guide web');
        $this->assertDoesNotMatchRegularExpression('/\d/', $body, 'guide web: no numbers');
        $this->assertMarkdownBalanced($body, 'guide web');
    }

    public function testTipFlagStateMigrationUpdatesByTitleEnAndRestoresStory03Text(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-11-100010_UpdateWebLinkTipForWebPlay.php';
        require_once APPPATH . 'Database/Migrations/2026-12-11-100011_WebPlayTipTrueInBothFlagStates.php';
        $new = WebPlayTipTrueInBothFlagStates::NEW_CONTENT;

        $this->assertSame('WebLinkCode', WebPlayTipTrueInBothFlagStates::TITLE_EN);
        $this->assertTruthful($new, 'tip');
        $this->assertDoesNotMatchRegularExpression('/\d/', $new, 'tip: no numbers');
        $this->assertMarkdownBalanced($new, 'tip');
        $this->assertStringContainsString('я пришлю', $new, 'tip: Robi speaks in first person');
        $this->assertSame(UpdateWebLinkTipForWebPlay::NEW_CONTENT, WebPlayTipTrueInBothFlagStates::OLD_CONTENT, 'down() restores the story-03 text');

        $source = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-12-11-100011_WebPlayTipTrueInBothFlagStates.php');
        $this->assertStringContainsString("->where('title_en', self::TITLE_EN)", $source);
        $this->assertStringContainsString('->update(', $source, 'UPDATE, not a second tip');
        $this->assertStringNotContainsString('->insert(', $source);
        $this->assertStringNotContainsString('tip_type', $source, 'category stays as seeded');
    }

    public function testTipMigrationSwapsTextByTitleEnAndIsNumberFree(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-11-100010_UpdateWebLinkTipForWebPlay.php';
        $new = UpdateWebLinkTipForWebPlay::NEW_CONTENT;
        $old = UpdateWebLinkTipForWebPlay::OLD_CONTENT;

        // Текст story 03 superseded миграцией 100011 (Ask 8); здесь — только правда о привязке.
        $this->assertSame('WebLinkCode', UpdateWebLinkTipForWebPlay::TITLE_EN);
        $this->assertStringContainsString(self::INSTRUCTION, $new, 'tip 03: log-out-first instruction');
        $this->assertDoesNotMatchRegularExpression('/\d/', $new, 'tip: no numbers');
        $this->assertMarkdownBalanced($new, 'tip');
        $this->assertStringContainsString('привяжет этот вход', $old, 'down() restores the seeded text');

        $source = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-12-11-100010_UpdateWebLinkTipForWebPlay.php');
        $this->assertStringContainsString('->update(', $source, 'UPDATE, not a second tip');
        $this->assertStringNotContainsString('->insert(', $source);
        $this->assertStringNotContainsString('tip_type', $source, 'category stays as seeded');

        $seed = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-12-10-100020_SeedWebLinkTip.php');
        foreach (['wildworld.fun/account/link: ', 'привяжет этот вход к персонажу. '] as $fragment) {
            $this->assertStringContainsString($fragment, $seed, 'OLD_CONTENT mirrors the seed');
        }
    }

    public function testHeaderShowsPlayLinkLoggedInAndOut(): void
    {
        helper('url');
        foreach ([null, 'Робинзон'] as $authLabel) {
            $html = view('site/_layout/header', [
                'navCats'   => [],
                'uri'       => 'wiki',
                'tgLink'    => 'https://t.me/example_bot',
                'authLabel' => $authLabel,
            ]);

            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
            $this->assertSame(2, preg_match_all('~<a href="[^"]*/play" class="" data-nav="play">Играть</a>~u', $decoded), 'desktop nav + drawer');
        }
    }

    public function testCabinetPlayBlockIsFlagDrivenAndNeverEmpty(): void
    {
        $this->assertSame('web.play_enabled', AccountCabinet::PLAY_FLAG);

        $controller = (string) file_get_contents(APPPATH . 'Controllers/AccountCabinet.php');
        $this->assertStringContainsString("'playEnabled'   => \$this->gsBool(self::PLAY_FLAG, false)", $controller);

        $view = (string) file_get_contents(APPPATH . 'Views/site/account_cabinet.php');
        $this->assertStringContainsString('Играть на сайте</div>', $view, 'block label');
        $this->assertStringContainsString("base_url('play')", $view, 'flag on: link to /play');
        $this->assertStringContainsString('🔒 Игра на сайте (скоро) — ', $view, 'flag off: lock line with reason');
    }
}
