<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use App\Services\Web\LinkCodeService;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * web-accounts-p0-06 (ADR-188) — кнопка «🌐 Играть на сайте» на экране «⚙️ Настройки»
 * (callback `webLinkCode`) и общий рендер сообщения с кодом для `/web` ({@see \App\Controllers\Telegram\Commands\WebCommand}).
 *
 * Код выдаёт {@see LinkCodeService::issue()}. Сообщение — только текст (без фото, MEDIA-OFF):
 * код, где его ввести, сколько минут он живёт (из `Config\Accounts`) и что он одноразовый.
 * Код не привязывает чужой вход (ADR-188 инв. 4): вошедшему в другой аккаунт — «выйди и введи код».
 * Экран настроек не перерисовывается — код приходит отдельным сообщением.
 */
class WebLinkCodeAction extends BaseAction
{
    public const CALLBACK = 'webLinkCode';

    public const SITE_PATH = 'wildworld.fun/account/link';

    /**
     * web-bridge-p1-03 (plan A11) — игра в браузере описана условно: тексты уезжают, пока флаг
     * `web.play_enabled` выключен и `/play` показывает заглушку.
     */
    public const PLAY_HINT = 'Когда игру на сайте откроют, кнопка «Играть» в шапке сайта запустит её '
        . 'прямо в браузере. Пока там заглушка — играй здесь, в боте.';

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [, $character] = $this->getUserAndCharacter();
        $characterId   = match (true) {
            is_object($character) => $character->id ?? null,
            is_array($character)  => $character['id'] ?? null,
            default               => null,
        };
        if (! is_numeric($characterId)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден. Используйте /start.',
            ]);
        }

        return self::sendCode($chatId, (int) $characterId);
    }

    /** Выдать код персонажу и отправить сообщение с ним в чат. */
    public static function sendCode(int $chatId, int $characterId): ServerResponse
    {
        $issued = (new LinkCodeService())->issue($characterId);

        return Request::sendMessage([
            'chat_id'                  => $chatId,
            'text'                     => self::codeMessage($issued['code'], $issued['ttl_minutes']),
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
    }

    /** Текст сообщения с кодом (legacy Markdown; в коде нет символов разметки — алфавит A-Z2-9). */
    public static function codeMessage(string $code, int $ttlMinutes): string
    {
        return "🌐 *Игра на сайте*\n\n"
            . "Твой код: `{$code}`\n\n"
            . 'Открой ' . self::SITE_PATH . " и введи код там.\n"
            . "⏳ Код действует {$ttlMinutes} мин. и срабатывает один раз. "
            . "Новый код отменяет прежний.\n\n"
            . "Код впускает тебя на сайте в аккаунт этого персонажа. "
            . 'Уже вошёл на сайте почтой, Google или Яндексом в другой аккаунт — '
            . "сначала выйди из другого входа и введи код.\n\n"
            . self::PLAY_HINT;
    }
}
