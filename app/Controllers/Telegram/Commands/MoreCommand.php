<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Commands\UserCommand;

/**
 * ADR-150 Слайс 4 — `/more`: прямой прыжок к поверхности «⚙️ Ещё».
 *
 * 🔴 Зачем команда, а не только нижняя кнопка. Замер 2026-07-09: после активации Слайса 1
 * лишь **6 из 79** активных игроков нажимали `/start` — у остальных reply-клавиатура осталась
 * СТАРОЙ (Telegram не обновляет её сам). Для них кнопки «⚙️ Ещё» не существует, а других входов
 * у хаба нет → он был бы недостижим (нарушение UX-DISCOVERABILITY). `/`-меню (`setMyCommands`)
 * потерять нельзя — оно и есть запасной путь (ADR-103 Часть A).
 *
 * Делегирует {@see BotMenuService::openMore} — тот же экран, что callback `moreHub`.
 * При killswitch OFF честно объясняет, где искать содержимое, вместо немого отказа.
 */
class MoreCommand extends UserCommand
{
    protected $name        = 'more';
    protected $description = 'Ещё: магазин, арена, развлечения, помощь';
    protected $usage       = '/more';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId  = (int) $message->getChat()->getId();

        if (! BotMenuService::moreHubEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'parse_mode' => 'Markdown',
                'text'       => "Экран «Ещё» пока не включён.\n\n"
                    . 'Магазин и развлечения — на карточке *Перс*, настройки — команда /settings.',
            ]);
        }

        return BotMenuService::openMore($chatId, (int) $message->getFrom()->getId());
    }
}
