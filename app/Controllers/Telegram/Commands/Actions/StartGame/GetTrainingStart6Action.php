<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

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

        $text = "📍 *Шаг 6/7*\n\n"
            . "🤖 *Этапы прокачки* 🎯\n\n"
            . "Чтобы не блуждать вслепую — вот ориентиры, к которым стоит идти:\n\n"
            . "🎓 *Уровень 5* — открывается *Специализация*. Три ветки: оружейник / медик / инженер. От −10% до −20% к стоимости крафта своей категории при росте уровня.\n\n"
            . "🏛 *Уровень 10* — выбор *фракции*. Военные / Партизаны / Инженеры / Фермеры. У каждой свой эндгейм и свои бронекомплекты.\n\n"
            . "🤖 *Мастерская робототехники L1-L4* — открывает дронов каскадом: L1 → 🚁 Разведчик, L2 → 🚚 Карго-дрон, L3 → 🔧 Дрон-ремонтник, L4 → 🛡 Боевой дрон. Каждый дрон расходует заряд, восстанавливается на базе.\n\n"
            . "🛡 *Защита базы* — стена → колючая ограда → дозорная вышка. Постройки дают защитнику инициативу в PvP-стычке у базы.\n\n"
            . "🔧 *Ремонт инструментов и брони* — на базе или через NPC-мастера (3-я зона), вечный *крафт-полис* выкупает 20% потерь. Не забывай чинить.\n\n"
            . "💰 *Караваны NPC* — спавнятся на карте, продают редкие ресурсы и иногда готовых дронов за золото со скидкой относительно крафта или с премиум-наценкой.\n\n"
            . "_Список не обязательный — это просто карта возможностей. Свой путь выбираешь сам._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Прервать обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep],
                ],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $imagePath = base_url('uploads/telegram/character/beautiful_map.png');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
