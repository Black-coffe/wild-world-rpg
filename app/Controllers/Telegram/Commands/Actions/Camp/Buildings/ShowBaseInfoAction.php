<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\BaseService;
use App\Services\Bases\BaseCallbackSuffix;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class ShowBaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Сразу отвечаем на CallbackQuery
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // multibase-picker-02: `Base_b<id>` из пикера — база выбрана явно, идёт в
        // BaseService::showBaseInfo() для повторной проверки {@see \App\Services\Bases\BaseScopeResolver::resolveForBase()}.
        // Бес суффикса (старые сообщения / reply-меню) — $baseId=null, прежнее правило.
        [, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден, не могу показать базу.',
            ]);
        }
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден, не могу показать базу.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // Вызываем сервис базы. #12 edit-in-place (ADR-018): передаём message_id текущего
        // сообщения (на котором нажали «🏕») — showBaseInfo отредактирует его в «База»-вид
        // (с graceful fallback на новое, если source — text-сообщение).
        $baseService = new BaseService();
        return $baseService->showBaseInfo($chatId, $character, $this->callbackQuery->getMessage()->getMessageId(), $baseId);
    }
}