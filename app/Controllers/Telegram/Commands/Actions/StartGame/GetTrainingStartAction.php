<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ResourceModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GetTrainingStartAction extends BaseAction
{
    protected $characterModel;
    protected $resourceModel;
    protected $actionLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->resourceModel = new ResourceModel();
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

        $nextStep = 'getTrainedStart2'; // Default next step
        if ($lastAction && $lastAction['action_status'] === 'Completed') {
            // Update to the actual next step if the last action is completed
            $nextStep = 'getTrainedStart3'; // Assume this is the correct next step
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
        $text = "🤖 *Приветствую тебя в академии выживания Wild World!* 🌟\n\n"
            . "🗺 Ты находишься на пороге увлекательного путешествия по огромному миру.\n\n"
            . "👆 На изображении выше карта острова, где каждая точка — это возможность для приключений, ресурсов и испытаний.\n\n"
            . "🌍 Все новички начинают своё путешествие с юга, в самой мирной и безопасной зоны. Это идеальное место для первых шагов, где возможно спокойно и мирно развивать свои навыки. \n\n"
            . "⚠️ *Советую не задерживаться здесь дольше 10 уровней прокачки*, поскольку истинные приключения и более ценные ресурсы ждут в глубине острова!\n\n"
            . "🤔 Решай сам, готов ли ты продолжить учиться и покорять этот мир или же предпочтешь начать действовать самостоятельно\n\n"
            . "В любом случае, 🤖 я – *Роби*, здесь! Чтобы помочь тебе на каждом шагу твоего пути.\n*Давай вместе раскроем все секреты Wild World!* 🚀\n\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Прервать обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep]
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/character/map-lines-coordinates-start-position.jpg'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Ответ в телеграм
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
