<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-103 Часть A — `/craft`: меню крафта.
 * Запасной путь к навигации через `/`-меню (на случай потери reply-клавиатуры).
 * Делегирует {@see BotMenuService::openCraft} — та же логика, что у кнопки «Крафт».
 */
class CraftCommand extends UserCommand
{
    protected $name        = 'craft';
    protected $description = 'Крафт';
    protected $usage       = '/craft';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        return BotMenuService::openCraft(
            $message->getChat()->getId(),
            (int) $message->getFrom()->getId(),
        );
    }
}
