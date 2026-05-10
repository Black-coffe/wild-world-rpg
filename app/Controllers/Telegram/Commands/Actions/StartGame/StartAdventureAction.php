<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StartAdventureAction extends BaseAction
{
    protected $characterModel;
    protected $actionLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->actionLogModel = new ActionLogModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
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

        // Формируем сообщение
        $text = "🤖 *Изучение местности в Wild World!* 🌍\n\n"
            . "🚀 Ты прошел краткое введение в мир игры — теперь настало время практики! Остров полон опасностей и загадок, а разведка новых локаций — ключ к выживанию.\n\n"
            . "🌫️ Главное правило: *ты видишь только круг вокруг себя* — 1 клетку во все стороны. Дальше — туман. Но *двигаясь, ты сам открываешь местность* — каждый шаг подсвечивает клетку, в которую пришёл, и её соседей, и они остаются в памяти твоего персонажа навсегда. Никакого отдельного «исследования» — просто иди и смотри.\n\n"
            . "🚜 А для дальних переходов есть «Дальний поход» — задаёшь курс и сколько клеток пройти, идёшь сам, отчёт по прибытии.\n\n"
            . "🔍 *Готов сделать первый шаг и раскрыть секреты острова?* 🌌";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Завершить обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'exploreAreaTips']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/character/ready-for-adventure.jpg'); // Make sure this path is correctly specified
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getMessage()->getChat()->getId()]);

        // Отправляем фото с опциями
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
