<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\World\MoveSurfaceService;

/**
 * Класс, вызываемый при нажатии кнопки "🧭 Двигаться" (бывш. "Переехать") из меню "ДЕЙСТВИЯ".
 *
 * ADR-150 Слайс 1: сам рендер поверхности ходьбы (карта 12×12 + компас-розетка)
 * вынесен в {@see \App\Services\World\MoveSurfaceService}, чтобы тот же экран
 * открывался и из нижней кнопки «🌍 Мир», и из slash `/go` без дрейфа. Здесь остаётся
 * резолв персонажа + гейт переезда. Сбор и исследование открытию экрана не мешают —
 * шаг во время них отклоняет {@see \App\Services\World\MoveService::step()}.
 *
 * Все последующие перемещения по кнопкам (move_dir_...) редактируют это сообщение.
 */
class MoveCharacterAction
{
    protected $callbackQuery;

    protected $characterModel;
    protected $telegramUserModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery     = $callbackQuery;
        $this->characterModel    = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Отвечаем на колбэк, чтобы убрать "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Ищем telegram-пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе.'
            ]);
        }

        // Ищем персонажа
        $character = $this->characterModel
            ->where('telegram_user_id', $user['id'])
            ->first();

        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден в базе.'
            ]);
        }

        // Переезд базы — блокирует (клетка игрока меняется)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            // Если идёт переезд — выходим. Сервис уже отправил сообщение.
            return Request::emptyResponse();
        }

        // Сбор/исследование экран НЕ закрывают (oct-world-robot-fix, чат 2026-10-02): карту смотреть
        // можно, как из нижней «🌍 Мир» (GenericmessageCommand::handleWorld). Шаг во время такой задачи
        // отклоняет MoveService::step() → BUSY (задачи с parallel_execution_allowed = 0).
        // Рендер поверхности ходьбы — единый для move / «🌍 Мир» / /go.
        return (new MoveSurfaceService())->show($chatId, $character);
    }
}
