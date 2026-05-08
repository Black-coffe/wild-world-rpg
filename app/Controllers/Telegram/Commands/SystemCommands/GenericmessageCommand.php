<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\BaseService;
use App\Services\Player\CharacterService;
use App\Services\Player\CraftService;
use App\Services\World\MapService;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// Модели

// Сервисы

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $rawText = trim($message->getText(true) ?? '');
        $text    = mb_strtolower($rawText);
        $chatId  = $message->getChat()->getId();

        // Идея #6 (Arseny, 21.01.2025): ForceReply ответ на trade-промпт
        // вида "[SELL:123]" или "[BUY:123]" → выполнить продажу/покупку с qty.
        $reply = $message->getReplyToMessage();
        if ($reply !== null) {
            $promptText = (string) ($reply->getText() ?? '');
            if (preg_match('/\[(SELL|BUY):(\d+)\]/', $promptText, $m)) {
                return $this->handleTradeReply($chatId, $m[1], (int) $m[2], $rawText);
            }
        }

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
     * Идея #6: обработка ForceReply ответа на trade-промпт.
     * Парсим qty из ответа, дёргаем ResourceTradeService.
     */
    private function handleTradeReply(int $chatId, string $direction, int $resourceId, string $rawReply): ServerResponse
    {
        $qty = (int) preg_replace('/[^\d]/', '', $rawReply);
        if ($qty <= 0) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => '❌ Не понял число. Введите положительное целое число (например, 25000).',
            ]);
        }

        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();
        $userRow    = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        $character = (new CharacterModel())->where('telegram_user_id', $userRow['id'])->first();
        if (!$character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $svc    = new \App\Services\Player\Trade\ResourceTradeService();
        $result = $direction === 'SELL'
            ? $svc->sellResource((array) $character, $resourceId, $qty)
            : $svc->buyResource((array) $character, $resourceId, $qty);

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $result['message'],
            'parse_mode' => 'Markdown',
        ]);
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
