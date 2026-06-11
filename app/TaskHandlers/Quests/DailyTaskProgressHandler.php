<?php

declare(strict_types=1);

namespace App\TaskHandlers\Quests;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Quest\DailyTaskService;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * ADR-109 (E8) — завершение ежедневных заданий по baseline-дельте + награды.
 *
 * Tasks.php everyMinute + killswitch `quests.daily.enabled`. Для незавершённых сегодняшних
 * задач: `counter(char,type) − baseline ≥ qty` → is_completed=1 + золото + уведомление.
 * Когда все слоты дня закрыты — разовый бонус «все задания дня» (one-shot флаг в action_log).
 *
 * Реюзит {@see DailyTaskService::counter()} (те же монотонные счётчики). Класс НЕ final,
 * sendNotice/recordAllDone overridable — тесты (без eager telegram-init, lazy в safeSendMessage).
 */
#[HandlerKey(
    key: 'daily_tasks_progress',
    displayName: 'Ежедневные задания: прогресс + награды (E8)',
    description: 'everyMinute + killswitch quests.daily.enabled: завершает дейлики по baseline-дельте (counter−baseline≥qty), даёт золото + бонус за все 3. ADR-109.',
)]
class DailyTaskProgressHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    private const ALL_DONE_PREFIX = 'DailyAllDone_';

    private DailyTaskService $daily;
    private CharacterModel $characters;
    private TelegramUserModel $tgUsers;

    public function __construct(?DailyTaskService $daily = null)
    {
        $this->daily      = $daily ?? new DailyTaskService();
        $this->characters = new CharacterModel();
        $this->tgUsers    = new TelegramUserModel();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        if (! $this->daily->enabled()) {
            return;
        }

        $db    = Database::connect();
        $today = $this->daily->currentDate();

        $res = $db->table(DailyTaskService::TABLE)
            ->where('task_date', $today)
            ->where('is_completed', 0)
            ->get();
        if (! $res instanceof BaseResult) {
            return;
        }

        /** @var array<int,bool> $touchedChars */
        $touchedChars = [];

        foreach ($res->getResultArray() as $row) {
            $charId   = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            $type     = is_string($row['objective_type'] ?? null) ? $row['objective_type'] : '';
            $qty      = is_numeric($row['objective_qty'] ?? null) ? (int) $row['objective_qty'] : 1;
            $baseline = is_numeric($row['baseline'] ?? null) ? (int) $row['baseline'] : 0;
            $taskId   = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $reward   = is_numeric($row['reward_gold'] ?? null) ? (int) $row['reward_gold'] : 0;
            if ($charId <= 0 || $taskId <= 0 || $type === '') {
                continue;
            }

            if ($this->daily->counter($charId, $type, $db) - $baseline < $qty) {
                continue;
            }

            $db->table(DailyTaskService::TABLE)->where('id', $taskId)->update([
                'is_completed' => 1,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            if ($reward > 0) {
                $this->characters->increaseGold($charId, $reward);
            }
            $title = is_string($row['task_key'] ?? null)
                ? (\App\Services\Quest\DailyTaskCatalog::find($row['task_key'])['title'] ?? 'Задание дня')
                : 'Задание дня';
            $this->sendNotice($charId, "✅ *Задание дня выполнено!*\n{$title}" . ($reward > 0 ? "\n🏆 +{$reward} золота" : ''));

            $touchedChars[$charId] = true;
        }

        foreach (array_keys($touchedChars) as $charId) {
            $this->maybeAllDoneBonus($db, $charId, $today);
        }
    }

    /**
     * Разовый бонус «все задания дня»: если все сегодняшние слоты закрыты и флага ещё нет.
     *
     * @param BaseConnection<object, object> $db
     */
    protected function maybeAllDoneBonus(BaseConnection $db, int $charId, string $today): void
    {
        $total = $db->table(DailyTaskService::TABLE)
            ->where('character_id', $charId)->where('task_date', $today)->countAllResults();
        $done = $db->table(DailyTaskService::TABLE)
            ->where('character_id', $charId)->where('task_date', $today)->where('is_completed', 1)->countAllResults();
        if ($total === 0 || $done < $total) {
            return;
        }

        $flag = self::ALL_DONE_PREFIX . $today;
        $already = $db->table('action_log')
            ->where('character_id', $charId)->where('action_name', $flag)->countAllResults();
        if ($already > 0) {
            return;
        }

        $bonus = $this->gsInt('quests.daily.all_done_bonus_gold', 500);
        if ($bonus > 0) {
            $this->characters->increaseGold($charId, $bonus);
        }
        $this->recordAllDone($db, $charId, $flag);
        $this->sendNotice($charId, "🎉 *Все задания дня выполнены!*" . ($bonus > 0 ? "\n🏆 Бонус: +{$bonus} золота" : ''));
    }

    /**
     * One-shot флаг бонуса (enum-status строго 'Completed'). Overridable seam.
     *
     * @param BaseConnection<object, object> $db
     */
    protected function recordAllDone(BaseConnection $db, int $charId, string $flag): void
    {
        $db->table('action_log')->insert([
            'character_id'  => $charId,
            'chat_id'       => $this->resolveChatId($charId),
            'action_name'   => $flag,
            'action_status' => 'Completed',
            'description'   => 'E8 daily all-done bonus',
        ]);
    }

    /** Overridable seam отправки (тесты подменяют — без сети). */
    protected function sendNotice(int $charId, string $text): void
    {
        $chatId = $this->resolveChatId($charId);
        if ($chatId === 0) {
            return;
        }
        $this->safeSendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
    }

    private function resolveChatId(int $charId): int
    {
        $char = $this->characters->find($charId);
        $tgUserId = is_array($char) && is_numeric($char['telegram_user_id'] ?? null) ? (int) $char['telegram_user_id'] : 0;
        if ($tgUserId <= 0) {
            return 0;
        }
        $tg = $this->tgUsers->find($tgUserId);
        return is_array($tg) && is_numeric($tg['telegram_id'] ?? null) ? (int) $tg['telegram_id'] : 0;
    }
}
