<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
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

        // Перемещение персонажа в северо-восточную ячейку.
        // Fix 2026-07-13 (класс lost-update): статы — атомарным relative-UPDATE от
        // свежих значений (CharacterStatsService), позиция — отдельным update.
        (new \App\Services\Player\CharacterStatsService())->adjust((int) $character['id'], [
            'strength'   => 0.1,
            'agility'    => 0.1,
            'experience' => 0.05,
        ]);
        $this->characterModel->update($character['id'], [
            'cell_number' => $northeastCell['cell_number'],
            'biome_id' => $northeastCell['biome_id'], // Добавляем ID биома новой локации
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

        // ⚠️ Это caption фото — лимит Telegram 1024 символа (UTF-16, эмодзи = 2). Держим текст коротким.
        $text = "🤖 Снова я — *Роби*! 🚀\n\n"
            . "🎉 Ты совершил свой *первый переход* — на северо-восток. Двигаясь, ты сам открываешь местность: "
            . "клетка, в которую ты пришёл, и её соседи теперь на твоей карте навсегда.\n\n"
            . "📍 Ячейка №*{$northeastCell['cell_number']}* | X *{$northeastCell['coordinate_x']}* / Y *{$northeastCell['coordinate_y']}*\n"
            . "🌿 Биом: *{$biome['name']}*  ⚠️ Опасность: *{$biome['danger_level']}*  💪 Выживание: *{$biome['survival_difficulty']}*\n\n"
            . "🌾 Перемещение освоено — пора собирать ресурсы! Обращай внимание на уникальные предметы биома.\n\n"
            . "🗺️ Для дальних переходов есть _«Поход»_ (кнопка на экране «Двигаться»): задаёшь курс и сколько клеток пройти, идёшь сам, отчёт по прибытии.\n\n"
            . "🗺️ Загляни в карту мира, чтобы ориентироваться и прокладывать маршруты.";

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
        // #12 edit-in-place (ADR-018): результат первого перехода — навигация → редактируем
        // сообщение, на котором нажата кнопка «↗️ Северо-восток» (fallback на новое). Класс не
        // extends BaseAction → navTarget строим вручную.
        $navTarget = [
            'chat_id'    => $chatId,
            'message_id' => (int) $this->callbackQuery->getMessage()->getMessageId(),
        ];
        return \App\Services\Notifications\MediaSender::editOrSend($navTarget + [
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
