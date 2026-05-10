<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\ActionLogModel;

class MoveNorthEastTips
{
    protected $callbackQuery;
    protected $characterModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;
    protected $actionLogModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->biomeModel = new BiomeModel();
        $this->actionLogModel = new ActionLogModel(); // Ensure this model is properly configured
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Поиск пользователя и персонажа
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден в базе данных.']);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден или не имеет локации.']);
        }

        // Получение текущей локации персонажа и определение северной ячейки
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Локация персонажа не найдена.']);
        }

        $northeastCell = $this->mapModel
            ->where('coordinate_x', $currentCell['coordinate_x'] + 1)
            ->where('coordinate_y', $currentCell['coordinate_y'] - 1)->first();

        if (!$northeastCell) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Северо-восточная локация не найдена.']);
        }

        // ADR-019 Step 6: гейт «должна быть заранее исследована» снят (как в основном
        // MoveCharacterToDirectionAction — движение И есть разведка). В северо-восточную
        // клетку шагнуть можно; факт прихода раскрывает её + 8 соседей (туман войны radius-1).

        // Перемещение персонажа в северо-восточную ячейку
        $this->characterModel->update($character['id'], [
            'cell_number' => $northeastCell['cell_number'],
            'biome_id' => $northeastCell['biome_id'], // Добавляем ID биома новой локации
            // Увеличение параметров
            'strength' => $character['strength'] + 0.1,
            'agility' => $character['agility'] + 0.1,
            'experience' => $character['experience'] + 0.05,
        ]);

        // Туман войны: раскрываем 3×3 вокруг новой позиции (ADR-019).
        $this->exploredCellsModel->revealAround(
            (int) $character['id'],
            (int) $user['id'],
            (int) $northeastCell['coordinate_x'],
            (int) $northeastCell['coordinate_y'],
            isset($character['level']) ? (int) $character['level'] : null
        );

        // Предположим, что $northwestCell уже содержит 'biome_id'
        $biome = $this->biomeModel->find($northeastCell['biome_id']);
        if (!$biome) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Информация о биоме не найдена.']);
        }

        // Получаем имя класса без полного namespace
        $className = basename(str_replace('\\', '/', get_class($this)));
        $lastAction = $this->actionLogModel->where([
            'character_id' => $character['id'],
            'action_name' => $className
        ])->orderBy('created_at', 'DESC')->first();

        $nextStep = 'getTrainedStart4'; // Default next step
        if ($lastAction && $lastAction['action_status'] === 'Completed') {
            // Update to the actual next step if the last action is completed
            $nextStep = 'getTrainedStart5'; // Assume this is the correct next step
        }

        // Log this action as done only if it's not logged yet
        if (!$lastAction || $lastAction['action_status'] !== 'Completed') {
            $this->actionLogModel->save([
                'character_id' => $character['id'],
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'action_name' => $className,
                'action_status' => 'Completed',
                'description' => 'User completed training section: ' . $className
            ]);
        }

        $text = "🤖 Приветствую тебя, искатель приключений! Я снова с тобой, твой верный помощник *Роби*! 🚀\n\n"
            . "🎉 Ты успешно совершил свой *первый переход в новую локацию* на северо-восток. Наш мир огромен и полон загадочных мест, и хотя ближайшие локации не всегда содержат разные биомы, путешествия между ними могут затянуться на недели.\n\n"
            . "📍 _Ты сейчас в игровой ячейке №:_ *{$northeastCell['cell_number']}*\n"
            . "🧭 _Твои координаты:_ X *{$northeastCell['coordinate_x']}* | Y *{$northeastCell['coordinate_y']}*\n\n"
            . "🌿 *Текущий биом:* *{$biome['name']}*\n"
            . "⚠️ *Уровень опасности:* *{$biome['danger_level']}*\n"
            . "💪 *Сложность выживания:* *{$biome['survival_difficulty']}*\n\n"
            . "🌾 Теперь, когда ты освоил перемещение (а значит и разведку — каждый шаг открывает клетки вокруг), пришло время собирать ресурсы! Обращай внимание на уникальные предметы.\n\n"
            . "🚜 _Для дальних переходов есть «Дальний поход» — задаёшь курс и сколько клеток пройти, идёшь сам, отчёт по прибытии. Кнопка на экране «Переехать»._\n\n"
            . "🌍 *Важность ориентирования*: Мудро изучай карту мира и понимай, где находишься относительно других областей. Это поможет находить ценные объекты и избегать опасностей.\n\n"
            . "🗺️ Взгляни на карту мира, чтобы лучше ориентироваться и прокладывать маршруты к новым целям! 🌟";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Завершить обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gatherTips'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/map-lines-coordinates.jpg'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
