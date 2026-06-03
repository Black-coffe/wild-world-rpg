<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Web\TelegramLoginVerifier;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Services;

/**
 * ADR-061 — Telegram Login Widget callback + logout.
 *
 * Виджет на /map шлёт GET-запрос со своими params (id, first_name, ..., hash).
 * Проверяем HMAC через TelegramLoginVerifier, маппим telegram_id → telegram_users
 * → characters, кладём в CI4-сессию `tg_user_id` (= telegram_users.id), редиректим
 * обратно на /map.
 *
 * Other-player visibility НЕ открывается этим контроллером — только own position
 * (см. Map::data() me-block).
 */
class TelegramLogin extends BaseController
{
    public function callback(): ResponseInterface
    {
        $payload = $this->request->getGet();
        if (! is_array($payload)) {
            return redirect()->to('/map?auth=bad_payload');
        }

        // ADR-092 Фаза 3: куда вернуть после входа (по умолчанию /map). `next` НЕ подписан
        // Telegram'ом → убираем ДО verify (иначе попадёт в data_check_string и сломает подпись).
        $next = $this->safeNext(is_scalar($payload['next'] ?? null) ? (string) $payload['next'] : null);
        unset($payload['next']);

        $verifier = new TelegramLoginVerifier();
        $result   = $verifier->verify($payload);
        if ($result['ok'] !== true) {
            log_message('warning', 'TelegramLogin verify failed: ' . $result['error']);
            return redirect()->to($this->withParam($next, 'auth=fail'));
        }

        $tgId      = $result['tg_id'];
        $firstName = $result['first_name'];
        $username  = $result['username'];
        if ($tgId <= 0) {
            return redirect()->to($this->withParam($next, 'auth=bad_id'));
        }

        // Найти или создать telegram_users
        $tgUserModel = new TelegramUserModel();
        $tgUser      = $tgUserModel->where('telegram_id', $tgId)->first();
        if (! is_array($tgUser)) {
            $insertData = [
                'telegram_id' => $tgId,
                'username'    => $username !== '' ? $username : null,
                'first_name'  => $firstName !== '' ? $firstName : null,
                'last_name'   => $result['last_name'] !== '' ? $result['last_name'] : null,
            ];
            $tgUserModel->insert($insertData);
            $tgUser = $tgUserModel->where('telegram_id', $tgId)->first();
        }
        if (! is_array($tgUser) || ! isset($tgUser['id']) || ! is_numeric($tgUser['id'])) {
            return redirect()->to($this->withParam($next, 'auth=db_error'));
        }

        $tgUserPk = (int) $tgUser['id'];

        // CI4-сессия. Регенерим session_id (anti-fixation).
        $session = Services::session();
        $session->regenerate(true);
        $session->set('tg_user_id', $tgUserPk);
        $session->set('tg_first_name', $firstName);
        $session->set('tg_username', $username);

        // Соединяем character (опционально — может ещё не быть)
        $character = (new CharacterModel())->where('telegram_user_id', $tgUserPk)->first();
        if (is_array($character) && isset($character['id']) && is_numeric($character['id'])) {
            $session->set('character_id', (int) $character['id']);
        }

        return redirect()->to($this->withParam($next, 'auth=ok'));
    }

    public function logout(): ResponseInterface
    {
        $next    = $this->safeNext(is_scalar($this->request->getPost('next') ?? null) ? (string) $this->request->getPost('next') : null);
        $session = Services::session();
        $session->remove(['tg_user_id', 'tg_first_name', 'tg_username', 'character_id']);
        $session->regenerate(true);

        return redirect()->to($this->withParam($next, 'auth=logged_out'));
    }

    /**
     * Безопасный локальный путь для возврата (anti open-redirect). Дефолт /map.
     * Допускаем только относительные пути, начинающиеся с одного «/» (не «//», без схемы).
     */
    private function safeNext(?string $next): string
    {
        if ($next === null || $next === '') {
            return '/map';
        }
        if ($next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '://')) {
            return '/map';
        }

        return $next;
    }

    /** Добавить query-параметр к пути, корректно выбрав ?/&. */
    private function withParam(string $path, string $param): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . $param;
    }
}
