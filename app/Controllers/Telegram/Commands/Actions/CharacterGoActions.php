<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\TelegramUserModel;
use App\Models\ClaimedCellModel; // <-- Нужно для проверки базы

class CharacterGoActions extends BaseAction
{
    protected $claimedCellModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel = new ClaimedCellModel(); // Для проверки базы
    }

    public function handle(): ServerResponse
    {
        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Шаг 1: Поиск пользователя в базе
        $telegramUserModel = new TelegramUserModel();
        $user = $telegramUserModel->where('telegram_id', $telegramUserId)->first();

        // Получаем ID персонажа
        $character_id = $telegramUserModel->getCharacterIdByTelegramId($telegramUserId);

        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных.',
            ]);
        }

        // Проверяем, есть ли у этого игрока активная база
        // (claimed_cells: status='active')
        $hasBase = $this->claimedCellModel
            ->where('character_id', $character_id)
            ->where('status', 'active')
            ->first();

        // Текст сообщения
        $text = "*Привет, герой! 🙋‍♂️* 👋\n\n"
            . "**Скучно сидеть сложа руки? Не беда!** 🥱\n\n"
            . "**Мы найдем работу для самых трудолюбивых героев!** 💪\n\n"
            . "**Выбирай, чем хочешь заняться сегодня:**\n\n"
            . "**Пусть тебе сопутствует удача!** 🍀\n\n"
            . "**P.S.** Делись своими достижениями в чате! 🗣️\n";

        // Формируем кнопки
        // Первая строка
        $keyboardButtons = [
            // Метки — из единого источника (BotMenuService::actionLabel): те же две двери
            // рисует поверхность ходьбы, и расхождение подписи там уже стоило нам добычи
            // (инцидент 2026-07-24, безымянная «🧑‍🌾 🛠️» в ряду направлений).
            ['text' => \App\Services\Telegram\BotMenuService::actionLabel('actionsHub'), 'callback_data' => 'characterActions'],
            // E4 Слайс 2 (срез 07-07): «Переехать» читалось как переезд базы — новички
            // не находили простой ход, хотя все обучающие тексты зовут «Двигаться».
            ['text' => '🧭 Двигаться',       'callback_data' => 'move'],
        ];

        // Вторая строка: "Окопаться" либо "Телепорт" — в зависимости от наличия базы.
        if (!$hasBase) {
            // Нет базы => «Окопаться»
            $keyboardButtons[] = ['text' => '🏕️ Окопаться', 'callback_data' => 'entrench'];
        } else {
            // База есть => «Телепорт».
            $keyboardButtons[] = ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'];

            // ADR-095 Фаза 1b: если по уровню доступно ещё баз — показываем вход «Новая база»
            // (иначе мульти-бэйс был бы BUILT-BUT-INVISIBLE: создание разблокировано, а кнопки нет).
            $campCountRaw = $this->claimedCellModel
                ->where('character_id', $character_id)
                ->where('status', 'active')
                ->countAllResults();
            $campCount = is_numeric($campCountRaw) ? (int) $campCountRaw : 0;
            // Уровень — скалярным запросом (getRowArray → чистый array, без Entity/object-union).
            $lvlQuery = \Config\Database::connect()->table('characters')
                ->select('level')->where('id', $character_id)->get();
            $lvlRow   = $lvlQuery !== false ? $lvlQuery->getRowArray() : null;
            $levelRaw = is_array($lvlRow) ? ($lvlRow['level'] ?? null) : null;
            $level    = is_numeric($levelRaw) ? (int) $levelRaw : 1;
            if ((new \App\Services\Bases\BaseLimitService())->canBuildMore($level, $campCount)) {
                $keyboardButtons[] = ['text' => '🏕️ Новая база', 'callback_data' => 'entrench'];
            }
        }

        // Добавим остальные кнопки (например, Квесты, Исследования...)
        // ADR-150 Слайс 3: при tasks_hub ON вход ведёт в «📋 Дела» — дом ВСЕХ целей (что идёт
        // сейчас + полярная звезда + квесты + задания дня), а не сразу в один из источников.
        // Квесты остаются в одном тапе оттуда. OFF → byte-identical.
        $keyboardButtons[] = \App\Services\Telegram\BotMenuService::tasksHubEnabled()
            ? ['text' => '📋 Дела', 'callback_data' => 'tasksHub']
            : ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'];
        $keyboardButtons[] = ['text' => '🗺️ Поход', 'callback_data' => 'march'];
        $keyboardButtons[] = ['text' => \App\Services\Telegram\BotMenuService::actionLabel('gather'), 'callback_data' => 'gather'];
        // ADR-094 discoverability: аптечка (лечение/расходники) — раньше пряталась в
        // экране Окопаться (только при наличии базы). Выносим на главный хаб действий,
        // всегда доступна. PharmacyAction сам объяснит, если медикаментов нет.
        $keyboardButtons[] = ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'];

        // Превращаем список кнопок в массив по строкам (2 кнопки в строке)
        $inlineKeyboard = array_chunk($keyboardButtons, 2);

        // Ответ на колбэк, чтобы убрать "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // #12 edit-in-place (ADR-018): меню действий персонажа — навигация → редактируем
        // текущее сообщение. editOrSend при любой ошибке edit упадёт обратно на новое.
        $imagePath = base_url('uploads/telegram/character_ready_to_act.png');
        $response  = \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);

        // E4 Слайс 2 (срез 07-07): новички застревают на этом хабе и карту не открывают →
        // хинт «сделай первый шаг» (FIRST_MOVE) стреляет и отсюда. One-shot маркер общий
        // с карт-триггером (BotMenuService) — спама не будет. Defensive: подсказка не
        // должна валить хаб.
        try {
            (new \App\Services\Onboarding\OnboardingHintService())
                ->maybeSendFirstMoveHintById((int) $character_id, (int) $chatId);
        } catch (\Throwable $e) {
            log_message('error', 'FIRST_MOVE hub hint failed: ' . $e->getMessage());
        }

        return $response;
    }
}
