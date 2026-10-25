<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Onboarding\ColdOpenWelcomeService;
use App\Services\Player\CharacterProvisioningService;
use App\Services\Player\NameService;
use App\Services\Web\AccountService;
use App\Services\Telegram\BotMenuService;
use App\Services\Web\AccountSession;
use App\Services\Web\WebScreenStore;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Longman\TelegramBot\Entities\Entity;
use Config\Social;

/**
 * web-accounts-p0-08 (ADR-188) — регистрация и создание персонажа без Telegram.
 * web-accounts-oauth-only (2026-09-30): регистрации по почте нет — аккаунт создаёт первый вход через
 * Google или Яндекс (AccountOAuth, за тем же флагом); `/account/register` — страница «как попасть».
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
                $provisioning = new CharacterProvisioningService($accounts);
                $characterId  = $provisioning->create($name, null, null, $accountId);
                $this->seedFirstScreen($characterId, $provisioning->lastTexts());
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        $session->refreshCharacter();

        // web-onboarding-04: сразу в игру, если она включена (иначе /play — заглушка).
        $play = (new GameSettingsService())->get('web.play_enabled', false);
        $to   = ($play === true || (is_numeric($play) && (int) $play === 1)) ? '/play' : '/account';

        return redirect()->to($to)->withCookies();
    }

    /**
     * web-onboarding-04: веб-модель приветствия из ядра — первым экраном `/play` с тем же доком,
     * что даёт бутстрап. Сбой не роняет создание: экран остаётся пустым, `/play` идёт прежним бутстрапом.
     *
     * @param array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} $texts
     */
    private function seedFirstScreen(int $characterId, array $texts): void
    {
        try {
            $model = (new ColdOpenWelcomeService())->build(ColdOpenWelcomeService::CLIENT_WEB, $texts);
            if (! $texts['spawned']) {
                return; // спавна нет: «ошибку» в экран не кладём, бутстрап сам разберётся
            }
            $messages = [];
            foreach ($model as $m) {
                $messages[] = ['text' => $m['text'], 'parse_mode' => $m['parse_mode'], 'inline_keyboard' => $m['inline_keyboard']];
            }
            $dock = [];
            foreach (BotMenuService::mainReplyKeyboard()->raw_data['keyboard'] ?? [] as $row) {
                $labels = [];
                foreach ((array) $row as $btn) {
                    $raw  = $btn instanceof Entity ? $btn->raw_data : $btn;
                    $text = is_string($raw) ? $raw : (is_array($raw) ? ($raw['text'] ?? null) : null);
                    if (is_string($text) && $text !== '') {
                        $labels[] = $text;
                    }
                }
                if ($labels !== []) {
                    $dock[] = $labels;
                }
            }
            (new WebScreenStore())->seedFirstScreen($characterId, $messages, $dock);
        } catch (\Throwable $e) {
            log_message('error', '[AccountRegister] first screen seed failed for character ' . $characterId . ': ' . $e->getMessage());
        }
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
            'ruleText' => NameService::ruleHintPlain(),
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
