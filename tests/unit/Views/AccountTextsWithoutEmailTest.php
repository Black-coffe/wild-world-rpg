<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use App\Controllers\AccountOAuth;
use App\Controllers\Telegram\Commands\Actions\WebLinkCodeAction;
use App\Database\Migrations\WebLinkTipWithoutEmail;
use App\Services\Onboarding\GuideCatalog;
use App\Services\Web\OAuthProviderFactory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * web-accounts-oauth-only-02 (ADR-188, поправка 2026-09-30) — ни один текст игроку не обещает вход или
 * восстановление по почте с паролем: у сайта есть только Google, Яндекс и Telegram.
 *
 * Проверка — регулярка «почт…» рядом со «вход/войд/пароль/сброс»; её сила доказана фикстурами:
 * исходная форма («войди почтой с паролем») и соседняя («вошёл почтой, Google…») обе ловятся.
 *
 * @internal
 */
final class AccountTextsWithoutEmailTest extends CIUnitTestCase
{
    private const EMAIL_LOGIN = '~(почт\w*[^.\n]{0,40}(парол|вход|войд|вош[её]л|сброс))|((парол|вход|войд|вош[её]л|сброс)\w*[^.\n]{0,40}почт)~iu';

    /** Скан ловит исходный случай и соседнюю форму — иначе зелёный результат ничего не значит. */
    public function testScanCatchesOriginalAndNeighbourForms(): void
    {
        $this->assertMatchesRegularExpression(self::EMAIL_LOGIN, 'Попробуй ещё раз или войди почтой с паролем.');
        $this->assertMatchesRegularExpression(self::EMAIL_LOGIN, 'Если ты уже вошёл на сайте почтой, Google или Яндексом в другой аккаунт');
        $this->assertDoesNotMatchRegularExpression(self::EMAIL_LOGIN, 'Добыть почти всегда дешевле, чем купить.');
    }

    public function testPlayerTextsDoNotPromiseEmailLogin(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-15-100000_WebLinkTipWithoutEmail.php';

        $texts = [
            'WebLinkCodeAction::codeMessage' => WebLinkCodeAction::codeMessage('ABCD2345', 15),
            'AccountOAuth::MSG_FAILED'       => AccountOAuth::MSG_FAILED,
            'AccountOAuth::MSG_UNAVAILABLE'  => AccountOAuth::MSG_UNAVAILABLE,
            'OAuth unavailable (google)'     => (new OAuthProviderFactory())->unavailableReason('google'),
            'tip WebLinkCode'                => WebLinkTipWithoutEmail::NEW_CONTENT,
        ];
        $guide = GuideCatalog::find('web');
        $this->assertIsArray($guide);
        $texts['/guide web'] = (string) ($guide['body'] ?? '');

        foreach (['account_login', 'account_register', 'account_link', 'account_cabinet', 'account_character', 'play_stub'] as $view) {
            // Комментарии разработчика (они объясняют, что почты нет) — не текст игроку.
            $texts["view {$view}"] = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(APPPATH . "Views/site/{$view}.php"));
        }

        foreach ($texts as $label => $text) {
            $this->assertDoesNotMatchRegularExpression(self::EMAIL_LOGIN, $text, $label);
        }
    }

    /** Откат совета возвращает прежний текст дословно — копией, без ссылки на соседнюю миграцию. */
    public function testTipMigrationDownRestoresPreviousTextVerbatim(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-12-11-100011_WebPlayTipTrueInBothFlagStates.php';
        require_once APPPATH . 'Database/Migrations/2026-12-15-100000_WebLinkTipWithoutEmail.php';

        $this->assertSame(\App\Database\Migrations\WebPlayTipTrueInBothFlagStates::NEW_CONTENT, WebLinkTipWithoutEmail::OLD_CONTENT);
        $source = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-12-15-100000_WebLinkTipWithoutEmail.php');
        $this->assertDoesNotMatchRegularExpression('~setContent\(\s*\\?(App\\\\Database\\\\Migrations\\\\)?WebPlayTipTrueInBothFlagStates::~', $source, 'down() must not reference another migration class');
    }
}
