<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use App\Services\Player\WhatsNewService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class GetTrainingStart7Action extends BaseAction
{
    protected ActionLogModel $actionLogModel;

    public function __construct(CallbackQuery $callbackQuery)
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
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $className  = basename(str_replace('\\', '/', get_class($this)));
        $lastAction = $this->actionLogModel->where([
            'character_id' => $character['id'],
            'action_name'  => $className,
        ])->orderBy('created_at', 'DESC')->first();

        $alreadyCompleted = is_array($lastAction)
            && ($lastAction['action_status'] ?? null) === 'Completed';

        if (! $alreadyCompleted) {
            $this->actionLogModel->save([
                'character_id'   => $character['id'],
                'chat_id'        => $this->callbackQuery->getMessage()->getChat()->getId(),
                'action_name'    => $className,
                'action_status'  => 'Completed',
                'description'    => 'User completed training section: ' . $className,
            ]);
        }

        $whatsNewEnabled = (new WhatsNewService())->isEnabled();

        $text = "📍 *Шаг 7/7*\n\n"
            . "🤖 *Готов идти?* 🌅\n\n"
            . "Это всё что нужно знать на старте. Дальше — на карту, добывать ресурсы, строить базу, разбираться с миром.\n\n"
            . "🧭 *Что делать прямо сейчас:*\n"
            . "1️⃣ Двигайся на север-восток — там твоя мирная стартовая зона.\n"
            . "2️⃣ Собирай ресурсы на каждой клетке, прокачивай уровень.\n"
            . "3️⃣ Когда наберёшь стартовые материалы — открой 🛠 *Крафт* и сделай первые инструменты.\n"
            . "4️⃣ Строй базу — она даёт хранилище, защиту и доступ к продвинутому крафту.\n\n";

        if ($whatsNewEnabled) {
            $text .= "📚 *Что нового* — в карточке персонажа есть раздел-справочник по механикам (крафт, роботы, дроны, фракции, оборона, экономика). Загляни, когда захочешь разобраться глубже.\n\n";
        }

        $text .= "🤖 *Я остаюсь рядом.* Если что-то непонятно — открой /tips или зайди в карточку персонажа. Большинство действий доступно прямо из кнопок.\n\n"
            . "_Удачи на острове. Возвращайся живым._";

        $buttons = [];
        if ($whatsNewEnabled) {
            $buttons[] = [['text' => '📚 Что нового', 'callback_data' => 'whatsNewCatalog']];
        }
        $buttons[] = [['text' => '🛣 К приключениям!', 'callback_data' => 'startAdventure']];

        $keyboard = ['inline_keyboard' => $buttons];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $imagePath = base_url('uploads/telegram/character/ready-for-adventure.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
