<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-103 Часть A — `/menu`: лёгкий escape-hatch, возвращает нижнее меню.
 *
 * В отличие от `/start` (для существующих игроков рендерит тяжёлую карточку
 * персонажа), `/menu` просто пере-аттачивает постоянную reply-клавиатуру.
 * Делегирует {@see BotMenuService::sendMainMenu}.
 */
class MenuCommand extends UserCommand
{
    protected $name        = 'menu';
    protected $description = 'Показать нижнее меню';
    protected $usage       = '/menu';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message  = $this->getMessage();
        $response = BotMenuService::sendMainMenu($message->getChat()->getId());

        // ADR-150: `/menu` уже отдал новый каркас и перечислил кнопки → помечаем игрока, чтобы
        // NavMenuRefreshService не прислал ему потом дубль «Меню обновилось».
        if ($response->isOk()) {
            BotMenuService::markMenuFresh((int) $message->getFrom()->getId());
        }

        return $response;
    }
}
