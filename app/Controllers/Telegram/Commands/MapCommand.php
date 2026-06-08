<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-103 Часть A — `/map`: карта мира с позицией игрока.
 * Запасной путь к навигации через `/`-меню (на случай потери reply-клавиатуры).
 * Делегирует {@see BotMenuService::openMap} — та же логика, что у кнопки «Карта».
 */
class MapCommand extends UserCommand
{
    protected $name        = 'map';
    protected $description = 'Карта мира';
    protected $usage       = '/map';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        return BotMenuService::openMap(
            $message->getChat()->getId(),
            (int) $message->getFrom()->getId(),
        );
    }
}
