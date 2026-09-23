<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\CharacterProvisioningService;
use App\Services\Player\NameService;
use App\Services\Web\AccountAuthService;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Accounts;
use Config\Database;
use Config\Social;

/**
 * web-accounts-p0-08 (ADR-188) — регистрация по email и создание персонажа без Telegram.
 *
 * Обе страницы за флагом GameSettings `web.open_registration` (default false = закрытая бета):
 * при выключенном флаге GET и POST только объясняют закрытую бету и ведут в бота (`/web`),
 * ничего не создавая. Аккаунт без персонажа (после регистрации или первого OAuth-входа, story 07)
 * попадает на `/account/character`. P0: один персонаж на аккаунт (plan A1).
 *
 * POST-формы защищены глобальным CSRF и `accountThrottle` (Routes).
 */
class AccountRegister extends BaseController
{
    public const FLAG = 'web.open_registration';

    private const REGISTER_ERRORS = [
        AccountAuthService::ERR_INVALID_EMAIL => 'Похоже, это не адрес почты. Проверь и попробуй ещё раз.',
        AccountAuthService::ERR_EMAIL_TAKEN   => 'Эта почта уже привязана к аккаунту. Войди с ней или сбрось пароль.',
    ];

    public function index(): ResponseInterface|string
    {
        if (! self::registrationOpen()) {
            return $this->registerView(['closed' => true]);
        }
        if ((new AccountSession())->current() !== null) {
            return redirect()->to('/account/character')->withCookies();
        }

        return $this->registerView([]);
    }

    public function store(): ResponseInterface|string
    {
        if (! self::registrationOpen()) {
            return $this->registerView(['closed' => true]);
        }
        $session = new AccountSession();
        if ($session->current() !== null) {
            return redirect()->to('/account/character')->withCookies();
        }

        $email    = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $email    = is_string($email) ? $email : '';
        $password = is_string($password) ? $password : '';

        $result = (new AccountAuthService())->registerWithEmail($email, $password);
        if (is_string($result)) {
            $message = $result === AccountAuthService::ERR_WEAK_PASSWORD
                ? 'Пароль слишком короткий: нужно не меньше ' . (new Accounts())->passwordMinLength . ' символов.'
                : (self::REGISTER_ERRORS[$result] ?? 'Не удалось зарегистрироваться. Попробуй ещё раз.');

            return $this->registerView(['error' => $message, 'errorField' => $result, 'email' => $email]);
        }

        $session->login($result);

        return redirect()->to('/account/character')->withCookies();
    }

    public function character(): ResponseInterface|string
    {
        if (! self::registrationOpen()) {
            return $this->characterView(['closed' => true]);
        }
        $current = (new AccountSession())->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }
        if ((new AccountService())->characterForAccount($current['account_id']) !== null) {
            return redirect()->to('/account')->withCookies();
        }

        return $this->characterView([]);
    }

    public function createCharacter(): ResponseInterface|string
    {
        if (! self::registrationOpen()) {
            return $this->characterView(['closed' => true]);
        }
        $session = new AccountSession();
        $current = $session->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }
        $accountId = $current['account_id'];

        $raw  = $this->request->getPost('name');
        $name = is_string($raw) ? trim($raw) : '';
        if (! NameService::isValidName($name)) {
            return $this->characterView(['error' => NameService::ruleMessagePlain(), 'name' => $name]);
        }

        // Двойной сабмит формы не должен дать второго персонажа (A1): проверка и создание под локом аккаунта.
        $db   = Database::connect();
        $lock = 'ww-acct-char-' . $accountId;
        $db->query('SELECT GET_LOCK(?, 5)', [$lock]);
        try {
            $accounts = new AccountService($db);
            if ($accounts->characterForAccount($accountId) === null) {
                (new CharacterProvisioningService($accounts))->create($name, null, null, $accountId);
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        $session->refreshCharacter();

        return redirect()->to('/account')->withCookies();
    }

    public static function registrationOpen(): bool
    {
        $raw = (new GameSettingsService())->get(self::FLAG, false);

        return $raw === true || (is_numeric($raw) && (int) $raw === 1);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function registerView(array $data): string
    {
        return view('site/account_register', $data + [
            'closed'      => false,
            'error'       => null,
            'errorField'  => null,
            'email'       => '',
            'minLength'   => (new Accounts())->passwordMinLength,
            'botLink'     => config(Social::class)->botStart('src_site_register'),
            'meta'        => self::meta('Регистрация — Wild World', 'account/register'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function characterView(array $data): string
    {
        return view('site/account_character', $data + [
            'closed'   => false,
            'error'    => null,
            'name'     => '',
            'ruleText' => NameService::ruleMessagePlain(),
            'botLink'  => config(Social::class)->botStart('src_site_register'),
            'meta'     => self::meta('Новый персонаж — Wild World', 'account/character'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function meta(string $title, string $path): array
    {
        return [
            'title'     => $title,
            'canonical' => rtrim(base_url($path), '/'),
            'robots'    => 'noindex,nofollow',
        ];
    }
}
