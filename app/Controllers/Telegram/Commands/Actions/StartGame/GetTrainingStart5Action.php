<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class GetTrainingStart5Action extends BaseAction
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

        $nextStep = 'getTrainedStart6';

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

        $text = "📍 *Шаг 5/7*\n\n"
            . "🤖 *Подсказки и советы* 💡\n\n"
            . "В игре уже больше *50 советов* — про крафт, фракции, экономику, защиту базы, дронов и эндгейм. Всё это держится в одной команде.\n\n"
            . "🔹 *Команда /tips* — открывает список советов с категориями. Заходи туда когда упёрся в стену или не понимаешь как двигаться дальше.\n\n"
            . "🔹 *Совет дня* — каждое утро в 10:00 по серверному времени я присылаю один совет в твой чат. Это можно отключить если мешает.\n\n"
            . "⚙️ *Не нравится?* Зайди в карточку персонажа → ⚙️ *Настройки* — там тумблер «Совет дня» и тумблер картинок (если хочешь играть на чистом тексте без фото).\n\n"
            . "_Чем дольше играешь — тем полезнее /tips. Там копится всё что мы добавляем в игру._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Прервать обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep],
                ],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $imagePath = base_url('uploads/telegram/character/final-step-image.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
