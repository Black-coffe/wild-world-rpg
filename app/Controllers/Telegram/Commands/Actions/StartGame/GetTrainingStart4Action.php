<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GetTrainingStart4Action extends BaseAction
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

        // Формируем финальное информационное сообщение
        $text = "🤖 *Игровой сеттинг: Wild World!* 🌍\n\n"
            . "🔔 Это заключительный шаг перед тем, как ты начнешь свои приключения. Я, *Роби*, хочу рассказать тебе самое главное об этой игре!\n\n"
            . "🌐 *Wild World* — это захватывающая _текстовая RPG_ с элементами песочницы и открытым миром в стиле постапокалипсиса. *Твоя цель* — создать свою империю, покорить остров и восстановить цивилизацию после глобальной катастрофы.\n\n"
            . "📜 *В игре ты сможешь:*\n"
            . "1️⃣ Открывать и исследовать регионы, собирать ресурсы, крафтить инструменты и оружие.\n"
            . "2️⃣ Присоединяться к фракциям, строить свою базу и укреплять защиту.\n"
            . "3️⃣ Торговать, взаимодействовать с NPC и другими игроками, чтобы развивать свою империю.\n"
            . "4️⃣ Противостоять стихийным бедствиям, разбойникам и роботам, завоевывать территории и укреплять позиции.\n\n"
            . "🚀 *Финальная миссия игры* — объединить или покорить весь остров, стать главной силой, возродить технологии и создать новое устойчивое общество!\n\n"
            . "🤖 *Готов к новым вызовам? Выбери свой путь и начни приключение!* 🌌";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Завершить обучение', 'callback_data' => 'endTraining'],
                    ['text' => '🛣 К приключениям!', 'callback_data' => 'startAdventure']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/character/final-step-image.jpg'); // Make sure this path is correctly specified
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
