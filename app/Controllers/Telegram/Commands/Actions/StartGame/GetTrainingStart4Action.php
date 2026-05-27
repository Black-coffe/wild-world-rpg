<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GetTrainingStart4Action extends BaseAction
{
    use GameSettingsReaderTrait;

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

        // W7a (ADR-065): killswitch для шагов 5-7. Если выключен — закрываем
        // онбординг на шаге 4/4 как раньше (startAdventure).
        $extended = $this->gsBool('onboarding.robi_extended.enabled', true);

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

        $total       = $extended ? 7 : 4;
        $nextStep    = $extended ? 'getTrainedStart5' : 'startAdventure';
        $continueBtn = $extended
            ? ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep]
            : ['text' => '🛣 К приключениям!',     'callback_data' => $nextStep];

        // Формируем финальное информационное сообщение
        $text = "📍 *Шаг 4/{$total}*\n\n"
            . "🤖 *Сеттинг: Wild World*\n\n"
            . "Это текстовая RPG с элементами песочницы — выживание после глобальной катастрофы на изолированном острове. Цивилизация рухнула, ты разбираешься как жить дальше.\n\n"
            . "📜 *В игре ты будешь:*\n"
            . "1️⃣ Открывать 9 биомов, собирать ресурсы, крафтить инструменты и оружие из подручного хлама.\n"
            . "2️⃣ Строить лагерь и постройки — от костра до вышки связи и центра телепортации.\n"
            . "3️⃣ Торговать с NPC и другими игроками, переживать события мира (погода, болезни, находки).\n"
            . "4️⃣ Противостоять стихиям, рейдерам и диким животным; защищать базу и территорию.\n\n"
            . "🎯 *На lvl 10 — выбор фракции. У каждой свой эндгейм:*\n"
            . "🛡️ *Военные* — доминирование и контроль острова.\n"
            . "🌲 *Партизаны* — анархия, обрушение чужих систем.\n"
            . "🛠️ *Инженеры* — научный прорыв, возрождение технологий из хлама.\n"
            . "🌾 *Фермеры* — мирное возрождение, эвакуация и новое общество за морем.\n\n"
            . "Ни один путь не «правильный» — выбираешь сам. 🌌";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Завершить обучение', 'callback_data' => 'withoutTrainingStart'],
                    $continueBtn,
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/character/final-step-image.jpg'); // Make sure this path is correctly specified
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): финальный шаг обучения «сеттинг» — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
