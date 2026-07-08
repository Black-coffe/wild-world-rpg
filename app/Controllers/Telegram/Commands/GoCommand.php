<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * ADR-150 Слайс 1 — `/go`: прямой прыжок к поверхности «🌍 Мир» (компас ходьбы).
 * Прямой ответ на жалобу «после обучения непонятно как просто ИДТИ»: одна команда —
 * и сразу компас-розетка направлений. Делегирует {@see BotMenuService::openWorld}
 * (тот же экран, что нижняя кнопка «🌍 Мир» и callback `move`). Работает независимо
 * от killswitch — но `/go` появится в `/`-меню только после `php spark bot:setcommands`.
 */
class GoCommand extends UserCommand
{
    protected $name        = 'go';
    protected $description = 'Идти (компас ходьбы)';
    protected $usage       = '/go';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        return BotMenuService::openWorld(
            $message->getChat()->getId(),
            (int) $message->getFrom()->getId(),
        );
    }
}
