<?php
namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;  // чтобы узнать name_rus
use App\Services\Player\Progression\EarlyProgressionService;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;

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
            return $this->replyNotFound($chatId);
        }

        // Берём название задачи
        $taskInfo = $this->taskModel->find($taskRow['task_id']);
        $taskName = $taskInfo ? $taskInfo['name_rus'] : '???';

        // 5) Меняем статус — условным переходом (s2-conditional-writes F21, ADR-181).
        // Двойной тап проходит гейт-чтение выше дважды; штраф идёт только тому вызову,
        // чей `in_work → interrupted` реально применился.
        $outcome = (new ConditionalWriteService())->transitionIfCurrent(
            'character_tasks',
            (int) $taskRow['id'],
            'status',
            'in_work',
            'interrupted'
        );
        switch ($outcome) {
            case WriteOutcome::Applied:
                break;
            case WriteOutcome::Missing:
                log_message('error', "[FinishTaskAction] character_tasks #{$taskRow['id']} исчезла между чтением и переходом в interrupted.");
                return $this->replyNotFound($chatId);
            case WriteOutcome::Refused:
                return $this->replyNotFound($chatId);
        }

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

        // Fix 2026-07-13 (класс lost-update): штраф — атомарным relative-UPDATE от
        // СВЕЖИХ значений (CharacterStatsService); floor 0.1 сохранён override'ом.
        $floors = [];
        foreach (['experience', 'health', 'strength', 'agility', 'intellect', 'tired'] as $f) {
            $floors[$f] = ['min' => 0.1];
        }
        (new \App\Services\Player\CharacterStatsService())->adjust((int) $character['id'], [
            'experience' => -$lostExp,
            'health'     => -$lostHealth,
            'strength'   => -$lostStrength,
            'agility'    => -$lostAgility,
            'intellect'  => -$lostIntellect,
            'tired'      => -$lostTired,
        ], $floors);

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

    private function replyNotFound(int|string $chatId): ServerResponse
    {
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
}
