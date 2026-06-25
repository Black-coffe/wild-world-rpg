<?php
namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;  // чтобы узнать name_rus
use App\Services\Player\Progression\EarlyProgressionService;

class FinishTaskAction
{
    protected $callbackQuery;
    protected $characterModel;
    protected $telegramUserModel;
    protected $characterTaskModel;
    protected $taskModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery      = $callbackQuery;
        $this->characterModel     = new CharacterModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();
        $callbackData   = $this->callbackQuery->getData(); // напр. "finishTask_12"

        // 1) Находим TelegramUser
        $telegramUser = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$telegramUser) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден.',
            ]);
        }

        // 2) Персонаж
        $character = $this->characterModel->where('telegram_user_id', $telegramUser['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
            ]);
        }

        // 3) Парсим колбэк
        $parts = explode('_', $callbackData);
        if (count($parts) !== 2 || $parts[0] !== 'finishAllTasks') {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text' => 'Непонятный формат команды.',
                'show_alert' => false,
            ]);
        }

        $taskId = (int) $parts[1];

        // 4) Ищем задачу
        $taskRow = $this->characterTaskModel
            ->where('id', $taskId)
            ->where('character_id', $character['id'])
            ->where('status', 'in_work')
            ->first();

        if (!$taskRow) {
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text' => 'Задача не найдена или уже завершена.',
                'show_alert' => false,
            ]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Задача не найдена или уже завершена.'
            ]);
        }

        // Берём название задачи
        $taskInfo = $this->taskModel->find($taskRow['task_id']);
        $taskName = $taskInfo ? $taskInfo['name_rus'] : '???';

        // 5) Меняем статус
        $this->characterTaskModel->update($taskRow['id'], [
            'status' => 'interrupted'
        ]);

        // При прерывании задачи игрок теряет характеристики.
        // ADR-138 (S3) рычаг D: для новичка (level<cap) штраф ×interrupt_penalty_factor
        // (default 0.0 → не списываем — анти-фрустрация). Dormant/ветеран → factor 1.0 = легаси.
        $penaltyFactor = (new EarlyProgressionService())
            ->interruptPenaltyFactor((float) ($character['level'] ?? 1));

        $lostExp       = 0.5  * $penaltyFactor;
        $lostHealth    = 1.0  * $penaltyFactor;
        $lostStrength  = 0.25 * $penaltyFactor;
        $lostAgility   = 0.5  * $penaltyFactor;
        $lostIntellect = 0.5  * $penaltyFactor;
        $lostTired     = 0.2  * $penaltyFactor;

        $newExperience = max(0.1, $character['experience'] - $lostExp);
        $newHealth     = max(0.1, $character['health'] - $lostHealth);
        $newStrength   = max(0.1, $character['strength'] - $lostStrength);
        $newAgility    = max(0.1, $character['agility'] - $lostAgility);
        $newIntellect  = max(0.1, $character['intellect'] - $lostIntellect);
        $newTired      = max(0.1, $character['tired'] - $lostTired);

        $this->characterModel->update($character['id'], [
            'experience' => $newExperience,
            'health'     => $newHealth,
            'strength'   => $newStrength,
            'agility'    => $newAgility,
            'intellect'  => $newIntellect,
            'tired'      => $newTired,
        ]);

        // 6) Ответ пользователю
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text' => 'Задача прервана.',
            'show_alert' => false,
        ]);

        if ($penaltyFactor >= 1.0) {
            // Легаси (dormant / ветеран): полный штраф — текст byte-identical.
            $text = "Задача *«{$taskName}»* была прервана! \n\n"
                . "Вы потеряли немного в параметрах:\n"
                . "- 🌟 Опыт: -0,5\n"
                . "- 💖 Здоровье: -1\n"
                . "- 💪 Сила: -0,25\n"
                . "- 🤸‍♂️ Ловкость: -0,5\n"
                . "- 🧠 Интеллект: -0,5\n"
                . "- 🥱 Выносливость: -0,2\n\n"
                . "Думаю, это было продуманное и верное решение. Лучше потерять ресурсы, нежели потерять жизнь! 💡\n\n"
                . "Не падай духом! Выживание - это искусство принятия трудных решений и учеба на собственных ошибках. "
                . "Всякий опыт ценен, а каждая неудача приближает тебя к пониманию, как стать сильнее и мудрее. 💪📚\n\n"
                . "Вперед, к новым вызовам! С каждым днем ты становишься только лучше. 🚀🌈";
        } else {
            // ADR-138 рычаг D: новичку штраф смягчён/убран — честный текст (не врём о потерях).
            $reliefLine = $penaltyFactor <= 0.0
                ? "Как новичку, характеристики тебе *не списали* — прерывай и пробуй свободно, пока осваиваешься. 💡"
                : "Как новичку, потери характеристик тебе *смягчили*. 💡";

            $text = "Задача *«{$taskName}»* прервана.\n\n"
                . $reliefLine . "\n\n"
                . "Лучше отложить начатое, чем потерять жизнь. Вперёд, к новым вызовам! 🚀🌈";
        }

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}
