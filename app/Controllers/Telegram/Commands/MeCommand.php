<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-103 Часть A — `/me`: экран персонажа.
 * Запасной путь к навигации через `/`-меню (на случай потери reply-клавиатуры).
 * Делегирует {@see BotMenuService::openCharacter} — та же логика, что у кнопки «Перс».
 */
class MeCommand extends UserCommand
{
    protected $name        = 'me';
    protected $description = 'Мой персонаж';
    protected $usage       = '/me';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        return BotMenuService::openCharacter(
            $message->getChat()->getId(),
            (int) $message->getFrom()->getId(),
        );
    }
}
