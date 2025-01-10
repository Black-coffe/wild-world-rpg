<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GetTrainingStart2Action extends BaseAction
{
    protected $characterModel;
    protected $actionLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->actionLogModel = new ActionLogModel(); // Ensure this model is properly configured
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

        $nextStep = 'getTrainedStart3'; // Default next step
        if ($lastAction && $lastAction['action_status'] === 'Completed') {
            // Update to the actual next step if the last action is completed
            $nextStep = 'getTrainedStart4'; // Assume this is the correct next step
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

        // Form the message
        $text = "🤖 *Биомы в Wild World!* 🌳\n\n"
            . "🔍 На острове ты можешь найти *9 различных биомов*, каждый со своими особенностями, рисками и ресурсами:\n\n"
            . "- Леса, Горы, Тундра и ледяные пустоши, Реки, Тропические джунгли, Поля и равнины, Пещеры и подземелья, Вулканические территории и Пустыни\n\n"
            . "📖 *Узнать больше о каждом биоме* [можно здесь](https://t.me/wild_world_info/343).\n\n"
            . "🤖 Биомы не просто обозначают новые места. Они представляют разные уровни выживания: опасных диких зверей, дефицит воды и еды, а также уникальные ресурсы и возможности для крафта.\n"
            . "\n\n_Чтобы выжить и добиться успеха на острове, изучи все биомы, ведь каждый из них открывает новые горизонты для твоего персонажа._\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Прервать обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep]
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/character/bioms-for-game-tips.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Send a photo with options
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
