<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands;

use App\Services\Onboarding\GuideService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-127 — `/guide`: команда-реплей онбординга «📖 Путь новичка».
 *
 * Зачем: многие новички пропускают обучение на /start (жмут «Прервать») и через
 * пару часов теряются. /guide даёт пройти то же самое (и больше) в любой момент —
 * это навигируемый текстовый справочник по всем механикам игры.
 *
 * 🔴 АНТИ-АБЬЮЗ: команда ТОЛЬКО показывает текст. В отличие от обучения Роби на /start
 * (стартовый набор + «счастливая находка» + награды квест-цепочки), здесь нет никакой
 * выдачи, телепортов, раскрытия карты или изменения персонажа. Звать можно сколько угодно.
 *
 * Регистрируется в `/`-меню бота через {@see \App\Services\Telegram\BotMenuService::commandList()}
 * (деплой-шаг `php spark bot:setcommands`). Навигация по разделам — {@see GuideService}
 * + {@see \App\Controllers\Telegram\Commands\Actions\Guide\GuideAction} (callback `guide`).
 */
class GuideCommand extends UserCommand
{
    protected $name        = 'guide';
    protected $description = 'Путь новичка — обучение и справочник по механикам';
    protected $usage       = '/guide';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $payload = (new GuideService())->indexPayload();

        return Request::sendMessage([
            'chat_id'      => $message->getChat()->getId(),
            'text'         => $payload['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $payload['reply_markup'],
        ]);
    }
}
