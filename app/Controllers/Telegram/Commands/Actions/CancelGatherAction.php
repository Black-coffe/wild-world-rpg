<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TaskModel;
use DateTime;

class CancelGatherAction extends BaseAction
{
    protected $resourceModel;
    protected $characterResourceModel;
    protected $biomeModel;
    protected $mapModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $taskModel; // Для поиска задачи Gather

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->resourceModel         = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->biomeModel            = new BiomeModel();
        $this->mapModel              = new MapModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->taskModel             = new TaskModel();  // Подключаем модель TaskModel для поиска задачи
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // 1. Находим задачу "Gather" в таблице tasks
        $gatherTask = $this->taskModel->where('name', 'Gather')->first();
        if (!$gatherTask) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Задача "Сбор ресурсов" не найдена в таблице tasks.',
            ]);
        }

        // 2. Ищем активную запись в character_tasks (status='in_work') по этому персонажу
        $task = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $gatherTask['id'])
            ->where('status', 'in_work')
            ->first();

        // Если задача не найдена или уже не in_work
        if (!$task) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Задача сбора ресурсов не найдена или уже завершена.',
            ]);
        }

        // 3. Прерываем задачу — меняем статус на "interrupted"
        $this->characterTaskModel->update($task['id'], ['status' => 'interrupted']);

        // 4. Формируем сообщение о том, что игрок уходит с пустыми руками
        $messageText = "*Ты прервал добычу ресурсов!* 😥\n\n";
        $messageText .= "Увы, все старания пошли насмарку — ничего собрать не успел.\n\n";
        $messageText .= "_В следующий раз будь уверен, что у тебя достаточно времени и сил._\n\n";
        $messageText .= "*Пустые руки, но целая жизнь — тоже результат!* 😜\n";

        // Клавиатура для продолжения
        // ADR-150 (чистка дублей): «Инвентарь»/«События» — в один-два тапа с нижней панели.
        $keyboard = \App\Services\Telegram\NavKeyboards::simplified()
            ? \App\Services\Telegram\NavKeyboards::whatNextWith()
            : [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ]
                ]
            ];

        // Отвечаем на callbackQuery, чтобы убрать значок «загрузка» в Telegram
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Возвращаем фото или текст — на ваше усмотрение.
        // Пример с фото «character_rushes_back_to_his_base.png»
        $imagePath = base_url('uploads/telegram/character_rushes_back_to_his_base.png');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $messageText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
