<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\BaseService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\CharacterService;
use App\Services\More\MoreSurfaceService;
use App\Services\Player\CraftService;
use App\Services\Tasks\TasksSurfaceService;
use App\Services\World\MapService;
use App\Services\World\MoveSurfaceService;
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
            'keyboard'          => self::replyRows(),
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'selective'         => false,
        ]);
    }

    /**
     * Ряды нижнего меню — ЕДИНЫЙ источник истины (клавиатура + тексты, которые её называют).
     *
     * @return list<list<string>>
     */
    private static function replyRows(): array
    {
        // ADR-150 Слайс 1: при world_hub ON нижняя «Карта» → «🌍 Мир».
        $mapLabel = self::worldHubEnabled() ? '🌍 Мир' : 'Карта';

        // ADR-150 Слайс 3: при tasks_hub ON во втором ряду появляется «📋 Дела» — дом целей
        // (таймеры/квесты/задания дня). Раньше активные задачи жили ТОЛЬКО в slash `/tasks`:
        // ни одной кнопки → их находили 30% активных игроков. OFF → ряд byte-identical.
        //
        // ADR-150 Слайс 4: при more_hub ON «Настройки» уступают место «⚙️ Ещё» — дому группы
        // (магазин/арена/развлечения/фракция/помощь/настройки). Сами Настройки живут ВНУТРИ
        // «Ещё» (один тап) + slash `/settings` + текст «настройки» — путь не теряется.
        $secondRow = [];
        if (self::tasksHubEnabled()) {
            $secondRow[] = '📋 Дела';
        }
        $secondRow[] = self::moreHubEnabled() ? '⚙️ Ещё' : 'Настройки';

        return [
            ['Перс', 'База', 'Крафт', $mapLabel],
            $secondRow,
        ];
    }

    /**
     * Killswitch ADR-150 Слайс 1 (navigation.world_hub.enabled). false (default) —
     * DORMANT: меню/роутинг byte-identical (нижняя «Карта» → фото). true — «🌍 Мир»
     * ведёт к компасу, фото демотировано в «🗺 Обзор».
     */
    public static function worldHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.world_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Killswitch ADR-150 Слайс 2 (navigation.me_hub.enabled). false (default) — DORMANT:
     * карточка Перс + экраны byte-identical. true — персональный блок (🎒/⚔️/💊/🧍)
     * сгруппирован + кнопка возврата «◀️ Я» на экранах Аптечки/Страховки.
     */
    public static function meHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.me_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Killswitch ADR-150 Слайс 3 (navigation.tasks_hub.enabled). false (default) — DORMANT:
     * `/tasks` рендерит легаси-список, нижнее меню и хаб «Действия» byte-identical.
     * true — «📋 Дела» становится единым домом целей (таймеры + звезда + квесты + дейлики).
     */
    public static function tasksHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.tasks_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Killswitch ADR-150 Слайс 4 (navigation.more_hub.enabled). false (default) — DORMANT:
     * нижняя кнопка «Настройки» как раньше, экрана «Ещё» нет. true — «⚙️ Ещё» становится домом
     * группы (магазин/арена/развлечения/фракция/помощь/настройки).
     */
    public static function moreHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.more_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
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
            // ADR-150 Слайс 1 — прямой прыжок к компасу ходьбы (прямой ответ «как идти»).
            // /map при world_hub ON тоже ведёт к компасу; фото карты — кнопкой «🗺 Обзор».
            ['command' => 'go',       'description' => '🧭 Идти (компас ходьбы)'],
            ['command' => 'map',      'description' => '🗺 Карта мира'],
            ['command' => 'settings', 'description' => '⚙️ Настройки'],
            // ADR-150 Слайс 3 — «📋 Дела»: что идёт сейчас + квесты + задания дня в одном экране.
            ['command' => 'tasks',    'description' => '📋 Дела: задачи, квесты, задания дня'],
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
                . 'Кнопки внизу экрана: ' . self::replyButtonsLine() . ".\n"
                . "_Если их не видно — нажми значок «☰» справа от поля ввода._",
            'parse_mode'   => 'Markdown',
            'reply_markup' => self::mainReplyKeyboard(),
        ]);
    }

    /**
     * Перечисление кнопок нижнего меню одной markdown-строкой — ЕДИНЫЙ источник для текстов,
     * которые их называют (`sendMainMenu`, fallback «не понял команду»). Раньше список был
     * захардкожен в двух местах: при смене каркаса (ADR-150) тексты начинали врать про UI.
     */
    public static function replyButtonsLine(): string
    {
        $labels = [];
        foreach (self::replyRows() as $row) {
            foreach ($row as $label) {
                $labels[] = '*' . $label . '*';
            }
        }

        return implode(' · ', $labels);
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

        $response = (new CraftService())->showCraftMenu($chatId);

        // ADR-103 just-in-time: новичок открыл крафт-хаб, но ещё ничего не скрафтил —
        // подсказываем, с чего начать («🔨 Общий крафт», верстак не нужен). One-shot +
        // killswitch/opt-out внутри сервиса; level-гейт дёшево (ветераны без DB).
        // Закрывает горлышко OnbStepCraft (пере-срез A+B 2026-06-20: 12/36 = 33%).
        // Тот же хинт продублирован в GenericmessageCommand::handleCraft (нижняя кнопка
        // «Крафт» / текст «крафт» идут туда напрямую, минуя этот метод).
        (new \App\Services\Onboarding\OnboardingHintService())
            ->maybeSendFirstCraftHint($character, $chatId);

        return $response;
    }

    public static function openMap(int $chatId, int $telegramId): ServerResponse
    {
        // ADR-150 Слайс 1: при world_hub ON «/map» и нижняя кнопка становятся входом к
        // компасу ходьбы (а не к фото-тупику). Фото карты доступно кнопкой «🗺 Обзор».
        if (self::worldHubEnabled()) {
            return self::openWorld($chatId, $telegramId);
        }

        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        $response = (new MapService())->showMapWithPlayer($chatId, $character);

        // ADR-103 just-in-time: новичок открыл карту, но ещё не двигался — подсказываем,
        // где компас «🧭 Двигаться» (карта мира = статичное фото без кнопок ходьбы).
        // One-shot (action_log) + killswitch/opt-out внутри сервиса; гейтится по level
        // дёшево (ветераны отсекаются без DB). Закрывает холодный старт OnbStepMove
        // (пере-срез A+B 2026-06-20).
        (new \App\Services\Onboarding\OnboardingHintService())
            ->maybeSendFirstMoveHint($character, $chatId);

        return $response;
    }

    /**
     * ADR-150 Слайс 1 — открыть поверхность «🌍 Мир» (компас ходьбы). Используется
     * нижней кнопкой «🌍 Мир», slash `/go` и (при world_hub ON) `/map`. Единый рендер —
     * {@see MoveSurfaceService::show} (тот же экран, что у callback `move`).
     */
    public static function openWorld(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new MoveSurfaceService())->show($chatId, $character);
    }

    /**
     * ADR-150 Слайс 3 — открыть поверхность «📋 Дела» (таймеры + звезда + квесты + дейлики).
     * Используется нижней кнопкой «📋 Дела» и slash `/tasks` (при tasks_hub ON). Единый рендер —
     * {@see TasksSurfaceService::show} (тот же экран, что у callback `tasksHub`).
     */
    public static function openTasks(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new TasksSurfaceService())->show($chatId, $character);
    }

    /**
     * ADR-150 Слайс 4 — открыть поверхность «⚙️ Ещё». Используется нижней кнопкой «⚙️ Ещё»
     * и текстом «ещё». Единый рендер — {@see MoreSurfaceService::show} (тот же экран, что у
     * callback `moreHub`).
     */
    public static function openMore(int $chatId, int $telegramId): ServerResponse
    {
        $character = self::resolveCharacter($telegramId);
        if ($character === null) {
            return self::noCharacter($chatId);
        }

        return (new MoreSurfaceService())->show($chatId, $character);
    }

    /**
     * Резолв персонажа по telegram_id. null — если нет Telegram-юзера или персонажа.
     * TelegramUserModel отдаёт array; CharacterModel типизирован `returnType=CharacterEntity`
     * → `first()` даёт CharacterEntity (или null).
     *
     * @return CharacterEntity|null
     */
    private static function resolveCharacter(int $telegramId): ?CharacterEntity
    {
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return null;
        }
        $rawId  = $userRow['id'] ?? null;
        $userId = is_numeric($rawId) ? (int) $rawId : 0;

        $character = (new CharacterModel())->where('telegram_user_id', $userId)->first();

        return $character instanceof CharacterEntity ? $character : null;
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
