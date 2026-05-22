<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\TipService;

class TipsCommand extends UserCommand
{
    protected $name = 'tips';
    protected $description = 'Provides a random game tip';
    protected $usage = '/tips';
    protected $version = '1.2.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();
        $from = $message->getFrom();
        $telegramId = $from->getId();

        $telegramUserModel = new TelegramUserModel();
        $characterModel = new CharacterModel();

        $existingUser = $telegramUserModel->where('telegram_id', $telegramId)->first();
        $character = $existingUser
            ? $characterModel->where('telegram_user_id', $existingUser['id'])->first()
            : null;

        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Ошибка: персонаж не найден.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        // ADR-038 Фаза C — общая логика выдачи совета (дедуп 15 дней + лог + микро-награда).
        $tipService = new TipService();
        $tip = $tipService->serveTip((int) $character['id']);

        if (!$tip) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Вы уже просмотрели все доступные советы в последние 15 дней. Пожалуйста, приходите позже.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => TipService::renderTip($tip),
            'parse_mode' => 'Markdown',
        ]);
    }
}
