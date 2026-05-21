<?php

declare(strict_types=1);

namespace App\TaskHandlers\Quests;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CraftedItemsModel;
use App\Models\ExploredCellsModel;
use App\Models\QuestStepsModel;
use App\Models\TelegramUserModel;
use App\Services\Endgame\EndgameProgressionService;
use App\Services\Quest\QuestChainService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * V12 (ADR-037) — генерик-завершение data-driven квестов цепочек.
 *
 * Recurring (Tasks.php everyMinute): берёт незавершённые quest_steps, чей квест
 * имеет objective_type ∈ {craft_item, explore_cells, char_level} (discover_object
 * завершается в StrategicLootHandler, не здесь), проверяет цель для персонажа и при
 * достижении — завершает шаг + награда (gold) + endgame + уведомление + advanceChain
 * (авто-назначение следующего этапа).
 *
 * Старые bespoke-квесты (objective_type=null) генерик НЕ касается (0 регрессии).
 */
#[HandlerKey(
    key: 'quest_objective',
    displayName: 'Квесты: генерик-objective завершение (цепочки)',
    description: 'Recurring (Tasks.php everyMinute): завершает data-driven квесты (craft_item/explore_cells/char_level), награда + advanceChain. discover_object — в StrategicLootHandler.',
)]
class QuestObjectiveHandler extends BaseTaskHandler
{
    protected CharacterModel $characterModel;
    protected QuestStepsModel $questStepsModel;
    protected CraftedItemsModel $craftedItemsModel;
    protected ExploredCellsModel $exploredCellsModel;
    protected TelegramUserModel $telegramUserModel;
    protected EndgameProgressionService $endgameService;
    protected QuestChainService $chainService;

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->questStepsModel    = new QuestStepsModel();
        $this->craftedItemsModel  = new CraftedItemsModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->endgameService     = new EndgameProgressionService();
        $this->chainService       = new QuestChainService();
    }

    /**
     * @param array<string,mixed> $task
     */
    public function handle(array $task = []): void
    {
        $db = \Config\Database::connect();

        $rows = $db->table('quest_steps qs')
            ->select('qs.id AS step_id, qs.character_id, q.id AS quest_id, q.title_en, q.title_ru, q.reward, q.objective_type, q.objective_target, q.objective_qty')
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('qs.is_completed', 0)
            ->whereIn('q.objective_type', ['craft_item', 'explore_cells', 'char_level'])
            ->where('q.status', 'active')
            ->get();
        if ($rows === false) {
            return;
        }

        foreach ($rows->getResultArray() as $row) {
            $charId = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            if ($charId <= 0) {
                continue;
            }
            $charRes = $db->table('characters')->where('id', $charId)->get();
            $character = $charRes === false ? null : $charRes->getRowArray();
            if (!is_array($character)) {
                continue;
            }

            if (!$this->objectiveMet($row, $character)) {
                continue;
            }

            // Завершаем шаг.
            $stepId = is_numeric($row['step_id'] ?? null) ? (int) $row['step_id'] : 0;
            $this->questStepsModel->update($stepId, ['is_completed' => 1]);

            // Награда gold.
            $reward = is_numeric($row['reward'] ?? null) ? (int) $row['reward'] : 0;
            if ($reward > 0) {
                $this->characterModel->increaseGold($charId, $reward);
            }
            $this->endgameService->recordQuestCompletion($charId);

            // Уведомление + следующий этап.
            $titleEn = is_string($row['title_en'] ?? null) ? $row['title_en'] : '';
            $titleRu = is_string($row['title_ru'] ?? null) ? $row['title_ru'] : '';
            $advanced = $this->chainService->advanceChain($charId, $titleEn);
            $this->notify($character, $titleRu, $reward, $advanced);

            log_message('info', "[QuestObjective] completed {$titleEn} char_id={$charId} (+{$reward} gold)");
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $character
     */
    private function objectiveMet(array $row, array $character): bool
    {
        $type = is_string($row['objective_type'] ?? null) ? $row['objective_type'] : '';
        $qty  = is_numeric($row['objective_qty'] ?? null) ? (int) $row['objective_qty'] : 1;
        $cid  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        switch ($type) {
            case 'char_level':
                $lvl = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
                return $lvl >= $qty;

            case 'explore_cells':
                $count = $this->exploredCellsModel->where('character_id', $cid)->countAllResults();
                return $count >= $qty;

            case 'craft_item':
                $target = $row['objective_target'] ?? null;
                if (!is_string($target) || $target === '') {
                    return false;
                }
                $db = \Config\Database::connect();
                $itemRes = $db->table('crafted_items')->select('id')->where('name_eng', $target)->get();
                $itemRow = $itemRes === false ? null : $itemRes->getRowArray();
                if (!is_array($itemRow) || !is_numeric($itemRow['id'] ?? null)) {
                    return false;
                }
                $logRes = $db->table('crafted_items_log')
                    ->selectSum('quantity', 'total')
                    ->where('character_id', $cid)
                    ->where('crafted_item_id', (int) $itemRow['id'])
                    ->get();
                $logRow = $logRes === false ? null : $logRes->getRowArray();
                $have = is_array($logRow) && is_numeric($logRow['total'] ?? null) ? (int) $logRow['total'] : 0;
                return $have >= $qty;

            default:
                return false;
        }
    }

    /**
     * @param array<string,mixed> $character
     * @param list<string> $advanced
     */
    private function notify(array $character, string $titleRu, int $reward, array $advanced): void
    {
        $tgUserId = is_numeric($character['telegram_user_id'] ?? null) ? (int) $character['telegram_user_id'] : 0;
        if ($tgUserId <= 0) {
            return;
        }
        $tg = $this->telegramUserModel->find($tgUserId);
        if (!is_array($tg) || empty($tg['telegram_id'])) {
            return;
        }
        $chatId = is_numeric($tg['telegram_id']) ? (int) $tg['telegram_id'] : 0;
        if ($chatId === 0) {
            return;
        }

        $msg  = "🎖 *Этап цепочки завершён!*\n";
        $msg .= "✅ *{$titleRu}*\n";
        if ($reward > 0) {
            $msg .= "🏆 Награда: *{$reward}* золота\n";
        }
        if (!empty($advanced)) {
            $msg .= "\n🔓 Открыт следующий этап цепочки — загляни в *«Активные квесты»*.";
        } else {
            $msg .= "\n🏁 Цепочка пройдена!";
        }

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
            ]],
        ];

        $this->safeSendMessage($chatId, $msg, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
