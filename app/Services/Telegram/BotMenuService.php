<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\BaseService;
use App\Services\Player\CharacterService;
use App\Services\Player\CraftService;
use App\Services\World\MapService;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-103 Часть A — навигационная устойчивость.
 *
 * Единый источник истины навигационного контракта бота:
 *  - постоянная reply-клавиатура (нижнее меню) — раньше определялась только внутри
 *    {@see \App\Controllers\Telegram\Commands\StartCommand};
 *  - список команд для `setMyCommands` (вечный `/`-меню-гамбургер у поля ввода —
 *    его, в отличие от reply-клавиатуры, НЕЛЬЗЯ свернуть/потерять);
 *  - открытие основных экранов по `(chatId, telegramId)` — переиспользуется
 *    slash-командами `/me /base /craft /map` ({@see commandList}).
 *
 * Зачем (триггерный инцидент m8k2b, 2026-06-08): до ADR-103 вся навигация висела
 * ТОЛЬКО на reply-клавиатуре, которую отправляет лишь `/start`. Стоит игроку её
 * свернуть — и пути к базе/крафту/персу нет, а единственная точка восстановления
 * (`/start`) нигде не подсказана. `setMyCommands` даёт несворачиваемый запасной путь.
 */
class BotMenuService
{
    /**
     * Постоянная нижняя reply-клавиатура (главное меню навигации).
     * Единственное определение в коде — {@see StartCommand} берёт отсюда.
     */
    public static function mainReplyKeyboard(): Keyboard
    {
        return new Keyboard([
            'keyboard' => [
                ['Перс', 'База', 'Крафт', 'Карта'],
                ['Настройки'],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'selective'         => false,
        ]);
    }

    /**
     * Список команд бота для `setMyCommands` (вечный `/`-меню-гамбургер).
     *
     * Имена команд — латиница `[a-z0-9_]`, ≤32 символов (жёсткое требование Telegram;
     * кириллические `/база` недопустимы). Описания — на русском, emoji разрешены.
     *
     * @return list<array{command: string, description: string}>
     */
    public static function commandList(): array
    {
        return [
            ['command' => 'start',    'description' => '🚀 Старт и возврат нижнего меню'],
            ['command' => 'menu',     'description' => '🧭 Показать нижнее меню'],
            ['command' => 'me',       'description' => '🧑 Мой персонаж'],
            ['command' => 'base',     'description' => '🏚 Моя база и стройка'],
            ['command' => 'craft',    'description' => '🔧 Крафт'],
            ['command' => 'map',      'description' => '🗺 Карта мира'],
            ['command' => 'settings', 'description' => '⚙️ Настройки'],
            ['command' => 'tasks',    'description' => '📋 Активные задачи'],
            ['command' => 'tips',     'description' => '💡 Совет по игре'],
            // ADR-127 — «📖 Путь новичка»: пройти обучение и справочник заново в любой момент.
            ['command' => 'guide',    'description' => '📖 Путь новичка (обучение заново)'],
        ];
    }

    /**
     * Лёгкий escape-hatch: пере-аттач нижнего меню без тяжёлой карточки персонажа
     * (в отличие от `/start`, который для существующих игроков рендерит карточку).
     */
    public static function sendMainMenu(int $chatId): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "🧭 Нижнее меню возвращено.\n\n"
                . "Кнопки внизу экрана: *Перс* · *База* · *Крафт* · *Карта* · *Настройки*.\n"
                . "_Если их не видно — нажми значок «☰» справа от поля ввода._",
            'parse_mode'   => 'Markdown',
            'reply_markup' => self::mainReplyKeyboard(),
        ]);
    }

    public static function openCharacter(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new CharacterService())->showCharacterInfo($chatId, $character);
    }

    public static function openBase(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new BaseService())->showBaseInfo($chatId, $character);
    }

    public static function openCraft(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new CraftService())->showCraftMenu($chatId);
    }

    public static function openMap(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new MapService())->showMapWithPlayer($chatId, $character);
    }

    /**
     * Резолв персонажа по telegram_id. null — если нет Telegram-юзера или персонажа.
     * TelegramUserModel отдаёт array; CharacterModel — CharacterEntity.
     *
     * @return array<int|string, mixed>|CharacterEntity|null
     */
    private static function resolveCharacter(int $telegramId): array|CharacterEntity|null
    {
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return null;
        }
        $rawId  = $userRow['id'] ?? null;
        $userId = is_numeric($rawId) ? (int) $rawId : 0;

        $character = (new CharacterModel())->where('telegram_user_id', $userId)->first();
        if (is_array($character) || $character instanceof CharacterEntity) {
            return $character;
        }

        return null;
    }

    /** Единый ответ «нет персонажа» с подсказкой пути восстановления. */
    private static function noCharacter(int $chatId): ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => 'Персонаж не найден. Нажми /start, чтобы создать персонажа и вернуть нижнее меню.',
        ]);
    }
}
