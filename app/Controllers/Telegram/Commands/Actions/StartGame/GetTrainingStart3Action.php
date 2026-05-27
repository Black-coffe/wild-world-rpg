<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GetTrainingStart3Action extends BaseAction
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

        // W7a (ADR-065): расширение Robi-chain 4 → 7 шагов под killswitch.
        $total = $this->gsBool('onboarding.robi_extended.enabled', true) ? 7 : 4;
        $text  = "📍 *Шаг 3/{$total}*\n\n"
            . "🤖 *События в мире Wild World!* 🌍\n\n"
            . "🎉 На острове тебя ждут разные уникальные события, готовые добавить приключений в твою игру!\n\n"
            . "⚡ События в игре бывают разных видов: некоторые дают усиливающие эффекты, другие ослабляют персонажа, а некоторые даже наносят серьезный урон.\n\n"
            . "🤖 _События могут значительно повлиять на твое выживание, особенно на начальных этапах игры, когда бафы и дополнительные ресурсы критически важны, а опасные события могут легко привести к гибели._\n\n"
            . "🔗 Подробное описание всех событий [ЗДЕСЬ](https://t.me/wild_world_info/268).\n\n"
            . "🤖 Помни! Чем больше вниманий к деталям, тем быстрее будет твое развитие и путь к господству над островом.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Прервать обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Продолжить обучение', 'callback_data' => $nextStep]
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/character/dynamic-collage-of-24-unique-events-from-the-game.jpg'); // Make sure this path is correctly specified
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): шаг обучения «события» — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
