<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-103 Часть A — `/base`: экран базы (Роби) с кнопкой «🏗 Строить».
 * Запасной путь к навигации через `/`-меню (на случай потери reply-клавиатуры).
 * Делегирует {@see BotMenuService::openBase} — та же логика, что у кнопки «База».
 */
class BaseCommand extends UserCommand
{
    protected $name        = 'base';
    protected $description = 'Моя база и стройка';
    protected $usage       = '/base';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        return BotMenuService::openBase(
            $message->getChat()->getId(),
            (int) $message->getFrom()->getId(),
        );
    }
}
