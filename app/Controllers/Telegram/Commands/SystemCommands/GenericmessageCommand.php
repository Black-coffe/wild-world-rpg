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
            // N4 (ADR-039): forceReply-ввод имени персонажа в онбординге.
            // SetNameAction шлёт промпт с маркером «✍ NAME» → имя из ответа в NameService.
            if (mb_strpos($promptText, '✍ NAME') !== false) {
                return $this->handleNameReply($chatId, $rawText);
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
                // ADR-150 Слайс 1: при world_hub ON «Карта» ведёт к компасу ходьбы
                // (MoveSurfaceService), при OFF — к фото (byte-identical). Старая
                // reply-клавиатура ещё шлёт «Карта» после флипа — тоже роутим на компас.
                return \App\Services\Telegram\BotMenuService::worldHubEnabled()
                    ? $this->handleWorld($chatId)
                    : $this->handleMap($chatId);

            // ADR-150 Слайс 1: новая нижняя кнопка «🌍 Мир» (при world_hub ON) → компас.
            case '🌍 мир':
                return $this->handleWorld($chatId);

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
                // ADR-148 — нераспознанный текст: пометить действие как 'unrouted' в firehose.
                \App\Services\Logging\PlayerActionLogger::current()->markUnrouted();
                // ADR-103 Часть A — escape-hatch: подсказываем, как вернуть нижнее меню,
                // если игрок его потерял (свернул reply-клавиатуру). /start и /menu
                // гарантированно её пере-аттачивают; `/`-меню (☰ у поля ввода) всегда на месте.
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'parse_mode' => 'Markdown',
                    'text'       => "Не понял команду.\n\n"
                        . "Используй нижнее меню: *Перс* · *База* · *Крафт* · *Карта* · *Настройки*.\n\n"
                        . "_Меню пропало? Нажми_ /menu _или_ /start _— нижняя панель вернётся. "
                        . "Все команды также доступны через значок «☰» рядом с полем ввода._",
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

        // Логируем продажу сырья через ForceReply («своё число») в action_log — кнопочный
        // путь логируется в SellResourceAction, а этот (GenericmessageCommand) — здесь, иначе
        // продажи «своим числом» оставались невидимы в форензике расхода ресурсов.
        if ($direction === 'SELL' && $result['success'] === true) {
            try {
                (new \App\Models\ActionLogModel())->save([
                    'character_id'  => is_numeric($character['id'] ?? null) ? (int) $character['id'] : null,
                    'chat_id'       => $chatId,
                    'action_name'   => 'SELL_RESOURCE',
                    'action_status' => 'Completed',
                    'description'   => mb_substr(
                        "res={$resourceId} qty=" . ($result['qty'] ?? '?')
                            . ' gold=+' . ($result['amount'] ?? '?') . ' (forcereply)',
                        0,
                        500
                    ),
                ]);
            } catch (\Throwable $e) {
                log_message('error', '[handleTradeReply] SELL log insert failed: ' . $e->getMessage());
            }
        }

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $result['message'],
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * N4 (ADR-039): обработка ForceReply-ответа на промпт ввода имени (онбординг).
     * Имя из ответа → NameService->applyName (та же валидация/tier-логика, что и /name slash).
     */
    private function handleNameReply(int $chatId, string $rawName): ServerResponse
    {
        $telegramId = $this->getMessage()->getFrom()->getId();
        $user       = (new TelegramUserModel())->asArray()->where('telegram_id', $telegramId)->first();
        if (!is_array($user)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }

        $character = (new CharacterModel())->asArray()->where('telegram_user_id', $user['id'])->first();
        if (!is_array($character)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $result = (new \App\Services\Player\NameService())->applyName($character, $rawName);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $result['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $result['keyboard'] !== null ? json_encode($result['keyboard']) : null,
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
        $response     = $craftService->showCraftMenu($chatId);

        // ADR-103 just-in-time: новичок открыл крафт-хаб (нижняя кнопка «Крафт» / текст
        // «крафт» — основной путь), но ещё ничего не скрафтил → подсказываем, с чего начать.
        // One-shot + killswitch/opt-out/level-гейт внутри сервиса. Закрывает горлышко
        // OnbStepCraft (пере-срез A+B 2026-06-20: 12/36 = 33%). Дубль — в BotMenuService::openCraft
        // (slash /craft); one-shot дедуп не даёт двойной отправки.
        if ($characterRow instanceof \App\Entities\CharacterEntity) {
            (new \App\Services\Onboarding\OnboardingHintService())
                ->maybeSendFirstCraftHint($characterRow, $chatId);
        }

        return $response;
    }

    /**
     * ADR-150 Слайс 1 — открыть поверхность «🌍 Мир» (компас ходьбы) из нижней кнопки/текста.
     * Единый рендер {@see \App\Services\World\MoveSurfaceService::show} (тот же экран,
     * что callback `move` и `/go`).
     */
    private function handleWorld(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel = new TelegramUserModel();
        $userRow   = $userModel->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (world).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (! $characterRow instanceof \App\Entities\CharacterEntity) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (world).',
            ]);
        }

        return (new \App\Services\World\MoveSurfaceService())->show($chatId, $characterRow);
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
        $response   = $mapService->showMapWithPlayer($chatId, $characterRow);

        // ADR-103 just-in-time (2026-06-21): нижняя кнопка «Карта» идёт сюда напрямую,
        // минуя BotMenuService::openMap, где висит хинт первого шага — без этого вызова
        // FIRST_MOVE срабатывал только на slash /map (а новички жмут кнопку). One-shot +
        // killswitch/opt-out/level/barely-moved внутри сервиса; one-shot дедуп общий с openMap.
        if ($characterRow instanceof \App\Entities\CharacterEntity) {
            (new \App\Services\Onboarding\OnboardingHintService())
                ->maybeSendFirstMoveHint($characterRow, $chatId);
        }

        return $response;
    }

}
