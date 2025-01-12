<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// Модели
use App\Models\TelegramUserModel;
use App\Models\CharacterModel;

// Сервисы
use App\Services\CharacterService;
use App\Services\BaseService;
use App\Services\CraftService;
use App\Services\MapService;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $text    = mb_strtolower(trim($message->getText(true)));  // приводим к нижнему регистру
        $chatId  = $message->getChat()->getId();

        switch ($text) {
            case 'перс':
                return $this->handleCharacter($chatId);

            case 'база':
                return $this->handleBase($chatId);

            case 'крафт':
                return $this->handleCraft($chatId);

            case 'карта':
                return $this->handleMap($chatId);

            // Новые команды для смены типа карты
            case 'beautiful_map':
                return $this->handleMapPreference($chatId, 'beautiful');

            case 'accurate_map':
                return $this->handleMapPreference($chatId, 'accurate');

            default:
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Не понял, попробуйте «перс», «база», «крафт» или «карта».',
                ]);
        }
    }

    /**
     * Установка предпочтения карты (beautiful/accurate)
     */
    private function handleMapPreference(int $chatId, string $mapType): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel = new TelegramUserModel();
        $userRow   = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден, не могу установить тип карты.',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден, не могу установить тип карты.',
            ]);
        }

        // Обновляем поле в БД
        $charModel->update($characterRow['id'], [
            'preferred_map_type' => $mapType,
        ]);

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => "Теперь ваша карта будет отображаться в режиме: {$mapType}",
        ]);
    }

    /**
     *  Логика для команды «персонаж»
     */
    private function handleCharacter(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (character).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (character).',
            ]);
        }

        // Вызываем сервис персонажа
        $charService = new CharacterService();
        return $charService->showCharacterInfo($chatId, $characterRow);
    }

    /**
     *  Логика для команды «база»
     */
    private function handleBase(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (base).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (base).',
            ]);
        }

        // Вызываем сервис базы
        $baseService = new BaseService();
        return $baseService->showBaseInfo($chatId, $characterRow);
    }

    /**
     *  Логика для команды «крафт»
     */
    private function handleCraft(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (craft).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (craft).',
            ]);
        }

        // Вызываем сервис крафта
        $craftService = new CraftService();
        return $craftService->showCraftMenu($chatId);
    }

    private function handleMap(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        // 1) Ищем пользователя
        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (map).',
            ]);
        }

        // 2) Ищем персонажа
        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (map).',
            ]);
        }

        // 3) Вызываем сервис карты
        $mapService = new MapService();
        return $mapService->showMapWithPlayer($chatId, $characterRow);
    }

}
