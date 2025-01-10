<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use Longman\TelegramBot\Entities\ServerResponse;

interface RobotActivatorInterface
{
    public function activate($chatId, $character): ServerResponse;
}
