<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Web\AccountAuthService;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * web-accounts-p0-07 (ADR-188) — кабинет аккаунта: персонаж, способы входа (почта+пароль,
 * Google, Яндекс, Telegram), добавление и отвязка (никогда не последнего), ссылка на привязку
 * кодом из бота, выход. web-bridge-p1-03: блок «Играть на сайте» — ссылка на /play при включённом
 * `web.play_enabled`, иначе lock-строка с причиной (флаг читается здесь, на сервере).
 *
 * Сообщения — через `?auth=<код>` (PRG): их ставят этот контроллер, AccountOAuth и TelegramLogin.
 */
class AccountCabinet extends BaseController
{
    use GameSettingsReaderTrait;

    public const PLAY_FLAG = 'web.play_enabled';

    public const MSG_LAST_IDENTITY = 'Это последний способ входа — его нельзя отвязать, иначе ты потеряешь доступ к аккаунту. Сначала добавь другой способ.';

    private const AUTH_NOTICES = [
        'ok'                => ['ok', 'Ты вошёл в аккаунт.'],
        'linked'            => ['ok', 'Telegram привязан к аккаунту.'],
        'link_refused'      => ['error', 'Этот Telegram уже привязан к другому аккаунту игры. Способ входа не переносится между аккаунтами: сначала отвяжи его там.'],
        'link_already'      => ['ok', 'Этот Telegram уже привязан к твоему аккаунту.'],
        'link_unconfirmed'  => ['error', 'Привязка Telegram не подтверждена: начни её кнопкой Telegram на этой странице. Если хочешь войти другим аккаунтом, сначала выйди.'],
        'email_added'       => ['ok', 'Почта и пароль сохранены — теперь можно входить ими.'],
        'unlinked'          => ['ok', 'Способ входа отвязан.'],
        'unlink_last'       => ['error', self::MSG_LAST_IDENTITY],
        'unlink_failed'     => ['error', 'Такого способа входа у аккаунта нет.'],
        'oauth_linked'      => ['ok', 'Способ входа привязан к аккаунту.'],
        'oauth_already'     => ['ok', 'Этот способ входа уже привязан к твоему аккаунту.'],
        'oauth_taken'       => ['error', 'Этот аккаунт Google/Яндекс уже привязан к другому аккаунту игры. Сначала отвяжи его там.'],
        'oauth_state'       => ['error', 'Привязка не подтверждена: ссылка устарела или открыта не из этого браузера. Попробуй ещё раз.'],
        'oauth_denied'      => ['error', 'Привязка отменена.'],
        'oauth_failed'      => ['error', 'Не удалось получить ответ от провайдера. Попробуй ещё раз позже.'],
        'oauth_unavailable' => ['error', 'Этот способ входа сейчас недоступен.'],
    ];

    /** Сообщения ошибок `AccountAuthService::setEmailPassword`. */
    private const EMAIL_ERRORS = [
        AccountAuthService::ERR_INVALID_EMAIL  => 'Это не похоже на адрес почты.',
        AccountAuthService::ERR_WEAK_PASSWORD  => 'Пароль слишком короткий.',
        AccountAuthService::ERR_EMAIL_TAKEN    => 'Эта почта уже привязана к другому аккаунту.',
        AccountAuthService::ERR_HAS_OTHER_MAIL => 'К аккаунту уже привязана другая почта. Чтобы сменить её, сначала отвяжи старую.',
    ];

    public function index(): ResponseInterface|string
    {
        $session = new AccountSession();
        $current = $session->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }
        // web-bridge-p1-08: гость пришёл с «Играть» — после входа один раз обратно на /play.
        if ($session->consumeReturnTarget() === AccountSession::RETURN_PLAY) {
            return redirect()->to(AccountSession::RETURN_PLAY, 303)->withCookies();
        }

        $auth   = $this->request->getGet('auth');
        $notice = is_string($auth) ? (self::AUTH_NOTICES[$auth] ?? null) : null;

        return $this->render($current['account_id'], ['notice' => $notice]);
    }

    public function addEmail(): ResponseInterface|string
    {
        $current = (new AccountSession())->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }

        $email    = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $email    = is_string($email) ? $email : '';
        $password = is_string($password) ? $password : '';

        $result = (new AccountAuthService())->setEmailPassword($current['account_id'], $email, $password);
        if ($result === true) {
            return redirect()->to('/account?auth=email_added')->withCookies();
        }

        $this->response->setStatusCode(422);

        return $this->render($current['account_id'], [
            'emailError' => self::EMAIL_ERRORS[$result] ?? 'Не удалось сохранить почту.',
            'emailValue' => $email,
        ]);
    }

    public function unlink(string $identityId): ResponseInterface
    {
        $current = (new AccountSession())->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }

        $accounts   = new AccountService();
        $accountId  = $current['account_id'];
        $id         = ctype_digit($identityId) ? (int) $identityId : 0;
        $identities = $accounts->identities($accountId);
        $owned      = array_filter($identities, static fn (array $i): bool => is_numeric($i['id'] ?? null) && (int) $i['id'] === $id);

        if ($owned === []) {
            return redirect()->to('/account?auth=unlink_failed')->withCookies();
        }
        if (count($identities) <= 1) {
            return redirect()->to('/account?auth=unlink_last')->withCookies();
        }

        $code = $accounts->unlinkIdentity($accountId, $id) ? 'unlinked' : 'unlink_last';

        return redirect()->to('/account?auth=' . $code)->withCookies();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function render(int $accountId, array $extra): string
    {
        $accounts   = new AccountService();
        $character  = $accounts->characterForAccount($accountId);
        $identities = $accounts->identities($accountId);
        $rawBot     = env('telegram.BOT_USERNAME');
        $bot        = is_string($rawBot) ? ltrim($rawBot, '@') : '';

        $providers = [];
        foreach ($identities as $identity) {
            if (is_string($identity['provider'] ?? null)) {
                $providers[] = $identity['provider'];
            }
        }

        return view('site/account_cabinet', $extra + [
            'characterName' => is_string($character['name'] ?? null) && $character['name'] !== '' ? $character['name'] : null,
            'hasCharacter'  => $character !== null,
            'identities'    => $identities,
            'linked'        => array_values(array_unique($providers)),
            'playEnabled'   => $this->gsBool(self::PLAY_FLAG, false),
            // web-bridge-p1-15: то же условие, что `can_register` у заглушки /play.
            'canRegister'   => AccountRegister::registrationOpen(),
            'botUsername'   => $bot,
            // Story 09 (F1): привязка виджетом засчитывается только с этим одноразовым nonce.
            'linkNonce'     => ! in_array('telegram', $providers, true) && $bot !== ''
                ? (new AccountSession())->mintTelegramLinkNonce()
                : '',
            'notice'        => null,
            'emailError'    => null,
            'emailValue'    => '',
            'meta'          => [
                'title'     => 'Аккаунт — Wild World',
                'canonical' => rtrim(base_url('account'), '/'),
                'robots'    => 'noindex,nofollow',
            ],
        ]);
    }
}
