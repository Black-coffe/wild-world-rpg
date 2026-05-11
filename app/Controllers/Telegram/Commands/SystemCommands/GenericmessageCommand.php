<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Controllers\Telegram\Commands\Actions\SettingsAction;
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
        // с маркером "SELL:123" / "BUY:123" в тексте → выполнить продажу/покупку с qty.
        // Скобки вокруг маркера опциональны: при parse_mode=Markdown Telegram «съедал»
        // квадратные скобки (вид [SELL:123] → SELL:123), regex терпим к обоим вариантам —
        // и продажа «своим числом» снова работает (баг 2026-05-11: «Не понял…» на ответ).
        $reply = $message->getReplyToMessage();
        if ($reply !== null) {
            $promptText = (string) ($reply->getText() ?? '');
            if (preg_match('/(SELL|BUY):(\d+)/', $promptText, $m)) {
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

            case 'настройки':
            case 'settings':
                return $this->handleSettings($chatId);

            // Новые команды для смены типа карты
            case 'beautiful_map':
                return $this->handleMapPreference($chatId, 'beautiful');

            case 'accurate_map':
                return $this->handleMapPreference($chatId, 'accurate');

            // Идея #14 (Yupirex, 13.02.2025): toggle для отключения изображений
            // (старые текстовые команды; новый UI — экран «Настройки», см. handleSettings).
            case 'media_off':
                return $this->handleMediaPreference($chatId, 1);

            case 'media_on':
                return $this->handleMediaPreference($chatId, 0);

            default:
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Не понял, попробуйте «перс», «база», «крафт», «карта» или «настройки».',
                ]);
        }
    }

    /**
     * Экран «⚙️ Настройки» (тумблер картинок, идея #14). Рендер — {@see SettingsAction::buildScreen()}.
     * Вызывается по тексту «настройки»/«settings» и кнопкой «Настройки» постоянной клавиатуры.
     */
    private function handleSettings(int $chatId): ServerResponse
    {
        $telegramId = (int) $this->getMessage()->getFrom()->getId();
        /** @var array<string,mixed>|null $userRow */
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if ($userRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден. Используйте /start.']);
        }
        /** @var \App\Entities\CharacterEntity|null $character */
        $character = (new CharacterModel())->where('telegram_user_id', $userRow['id'] ?? 0)->first();
        if ($character === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден. Используйте /start.']);
        }

        return Request::sendMessage(['chat_id' => $chatId] + SettingsAction::buildScreen($character));
    }

    /**
     * Идея #14: toggle disable_media через типизированную команду.
     */
    private function handleMediaPreference(int $chatId, int $disable): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();
        $userRow    = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        // TelegramUserModel + CharacterModel возвращают Entity (F1.4) или array
        // в зависимости от ToColumns. Поддерживаем оба.
        $userId = isset($userRow['id']) ? (int) $userRow['id'] : 0;

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userId)->first();
        if (!$characterRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        $charId = isset($characterRow['id']) ? (int) $characterRow['id'] : 0;

        $charModel->update($charId, ['disable_media' => $disable]);
        \App\Services\Notifications\MediaSender::reset();

        $msg = $disable === 1
            ? "🚫 *Режим без медиа* включён. Бот будет слать только текст.\n\n_Чтобы вернуть картинки — напиши_ `media_on`."
            : "🖼️ *Режим с медиа* включён. Бот будет присылать изображения.\n\n_Чтобы отключить — напиши_ `media_off`.";

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Идея #6: обработка ForceReply ответа на trade-промпт.
     * Парсим qty из ответа (любой мусор вокруг цифр срезаем), дёргаем ResourceTradeService.
     *
     * ⚠️ Персонажа берём через `asArray()` — `ResourceTradeService::sellResource()` ждёт
     * именно массив (`$character['id']`/`$character['gold']`); `(array)` каст по
     * `CharacterEntity` давал «битый» массив с mangled-ключами → продажа молча падала
     * в «У вас нет такого ресурса» (часть бага 2026-05-11).
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
        $character = (new CharacterModel())->asArray()->where('telegram_user_id', $userRow['id'])->first();
        if (!is_array($character)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        /** @var array<string,mixed> $character — строка БД (asArray): ключи всегда строковые */

        $svc    = new \App\Services\Player\Trade\ResourceTradeService();
        $result = $direction === 'SELL'
            ? $svc->sellResource($character, $resourceId, $qty)
            : $svc->buyResource($character, $resourceId, $qty);

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
