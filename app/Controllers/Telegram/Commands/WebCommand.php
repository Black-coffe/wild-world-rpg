<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Controllers\Telegram\Commands\Actions\WebLinkCodeAction;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * web-accounts-p0-06 (ADR-188) — `/web`: одноразовый код для входа на сайт и привязки персонажа.
 * Тот же код выдаёт кнопка «🌐 Играть на сайте» в «⚙️ Настройках»; рендер — {@see WebLinkCodeAction}.
 * В `/`-меню бота — через {@see \App\Services\Telegram\BotMenuService::commandList()}.
 */
class WebCommand extends UserCommand
{
    protected $name        = 'web';
    protected $description = 'One-time code to log in on wildworld.fun';
    protected $usage       = '/web';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message    = $this->getMessage();
        $chatId     = (int) $message->getChat()->getId();
        $telegramId = (int) $message->getFrom()->getId();

        /** @var array<string,mixed>|null $userRow */
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if ($userRow === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Telegram-пользователь не найден. Используйте /start.',
            ]);
        }

        $character   = (new CharacterModel())->where('telegram_user_id', $userRow['id'] ?? 0)->first();
        $characterId = match (true) {
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

        return WebLinkCodeAction::sendCode($chatId, (int) $characterId);
    }
}
