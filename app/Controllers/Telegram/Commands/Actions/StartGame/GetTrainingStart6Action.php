<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class GetTrainingStart6Action extends BaseAction
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

        $nextStep = 'getTrainedStart7';

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

        // Caption ≤ 1024 chars (Telegram limit для photo-сообщения).
        $text = "📍 *Шаг 6/7*\n\n"
            . "🤖 *Этапы прокачки* 🎯\n\n"
            . "Ориентиры, к которым стоит идти:\n\n"
            . "🎓 *L5* — *Специализация* (оружейник / медик / инженер). −10..−20% к крафту своей категории.\n\n"
            . "🏛 *L10* — выбор *фракции* (4 ветки, у каждой свой эндгейм и бронекомплекты).\n\n"
            . "🤖 *Мастерская робототехники L1-L4* — каскад дронов: 🚁 Разведчик → 🚚 Карго → 🔧 Ремонтник → 🛡 Боевой.\n\n"
            . "🛡 *Защита базы* — стена → ограда → вышка. Инициатива защитнику в PvP у базы.\n\n"
            . "🔧 *Ремонт* — на базе или у NPC-мастера; *крафт-полис* выкупает ~20% потерь.\n\n"
            . "💰 *Караваны NPC* — редкие ресурсы и готовые дроны за золото.\n\n"
            . "_Это карта возможностей, не обязательный путь._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Прервать обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep],
                ],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // W7a placeholder: JPG (не PNG) — editMessageMedia предсказуемо
        // конвертит между JPG-сообщениями. W7b заменит на dedicated LEXICON-asset.
        $imagePath = base_url('uploads/telegram/character/bioms-for-game-tips.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
