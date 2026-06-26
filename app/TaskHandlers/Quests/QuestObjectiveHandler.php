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
use App\Services\GameSettings\GameSettingsService;
use App\Services\Quest\QuestChainService;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseConnection;

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
    protected GameSettingsService $settings;

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->questStepsModel    = new QuestStepsModel();
        $this->craftedItemsModel  = new CraftedItemsModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->endgameService     = new EndgameProgressionService();
        $this->chainService       = new QuestChainService();
        $this->settings           = new GameSettingsService();
    }

    /**
     * ADR-088 killswitch: расширенные категории квестов (collect_resource /
     * building_level / npc_kills) прогрессят только при quests.extended_enabled=ON.
     * Default false → dormant (новый контент невидим/не прогрессит до активации).
     */
    private function extendedEnabled(): bool
    {
        $v = $this->settings->get('quests.extended_enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * ADR-103 Слой 2 killswitch: онбординг-цепочка «Первые шаги выжившего». При ON
     * её шаги (title_en LIKE OnbStep%) добавляются в обработку независимо от
     * extended_enabled, и засчитываются онбординг-типы (claim_base/open_base_screen/
     * craft_any/any_building). Default false → dormant.
     */
    private function onboardingChainEnabled(): bool
    {
        $v = $this->settings->get('onboarding.quest_chain.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string,mixed> $task
     */
    public function handle(array $task = []): void
    {
        $db = \Config\Database::connect();

        // ADR-088: расширенные типы добавляются в обработку только при killswitch ON.
        $types = ['craft_item', 'explore_cells', 'char_level'];
        if ($this->extendedEnabled()) {
            $types[] = 'collect_resource';
            $types[] = 'building_level';
            $types[] = 'level_milestone';
            $types[] = 'npc_kills';
        }

        // ADR-103 Слой 2: онбординг-шаги (title_en LIKE OnbStep%) обрабатываются при
        // своём killswitch'е, независимо от extended_enabled (онбординг-типы claim_base/
        // open_base_screen/craft_any/any_building засчитываются в objectiveMet).
        $onboardingOn = $this->onboardingChainEnabled();

        $builder = $db->table('quest_steps qs')
            ->select('qs.id AS step_id, qs.character_id, q.id AS quest_id, q.title_en, q.title_ru, q.reward, q.objective_type, q.objective_target, q.objective_qty, q.faction_id')
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('qs.is_completed', 0)
            ->where('q.status', 'active')
            ->groupStart()
                ->whereIn('q.objective_type', $types);
        if ($onboardingOn) {
            $builder->orLike('q.title_en', \App\Services\Onboarding\OnboardingChainCatalog::PREFIX, 'after');
        }
        $rows = $builder->groupEnd()->get();
        if ($rows === false) {
            return;
        }

        // ADR-088 Фаза 3: прогресс фракционных квестов гейтится отдельным killswitch'ом.
        $factionQuestsEnabled = $this->chainService->factionQuestsEnabled();

        foreach ($rows->getResultArray() as $row) {
            $charId = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            if ($charId <= 0) {
                continue;
            }

            // ADR-088 Фаза 3: фракционный квест (faction_id 1-4) прогрессит только при
            // killswitch ON И совпадении фракции персонажа с фракцией квеста.
            $questFaction = is_numeric($row['faction_id'] ?? null) ? (int) $row['faction_id'] : 0;
            if ($questFaction !== 0) {
                if (! $factionQuestsEnabled) {
                    continue;
                }
                $charFaction = (new \App\Models\CharacterFactionModel())->getFactionId($charId);
                if ($charFaction !== $questFaction) {
                    continue;
                }
            }
            $charRes = $db->table('characters')->where('id', $charId)->get();
            $character = $charRes === false ? null : $charRes->getRowArray();
            if (!is_array($character)) {
                continue;
            }

            if (!$this->objectiveMet($row, $character, $db)) {
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
            // W11 (ADR-067): если завершён branch-point — предлагаем выбор пути (вместо «след. этап»).
            $branchOptions = $this->chainService->pendingBranchOptions($charId, $titleEn);
            // ADR-103 Слой 2: онбординг-шаг → персональная just-in-time подсказка «что дальше».
            if (\App\Services\Onboarding\OnboardingChainCatalog::isChainQuest($titleEn)) {
                $this->notifyOnboarding($character, $titleEn, $reward);
            } else {
                $this->notify($character, $titleRu, $reward, $advanced, $branchOptions);
            }

            log_message('info', "[QuestObjective] completed {$titleEn} char_id={$charId} (+{$reward} gold)");
        }
    }

    /**
     * @param array<string,mixed>            $row
     * @param array<string,mixed>            $character
     * @param BaseConnection<object, object> $db
     */
    private function objectiveMet(array $row, array $character, BaseConnection $db): bool
    {
        $type = is_string($row['objective_type'] ?? null) ? $row['objective_type'] : '';
        $qty  = is_numeric($row['objective_qty'] ?? null) ? (int) $row['objective_qty'] : 1;
        $cid  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        switch ($type) {
            case 'char_level':
            case 'level_milestone': // ADR-088: майлстоун роста (startable-вариант char_level).
                $lvl = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
                return $lvl >= $qty;

            case 'npc_kills': // ADR-088 Фаза 2: побед над NPC ≥ qty (монотонный счётчик characters.npc_kills).
                $kills = is_numeric($character['npc_kills'] ?? null) ? (int) $character['npc_kills'] : 0;
                return $kills >= $qty;

            case 'explore_cells':
                $count = $this->exploredCellsModel->where('character_id', $cid)->countAllResults();
                return $count >= $qty;

            case 'craft_item':
                $target = $row['objective_target'] ?? null;
                if (!is_string($target) || $target === '') {
                    return false;
                }
                return $this->craftedCount($db, $cid, $target) >= $qty;

            case 'collect_resource':
                // ADR-088: владеть ≥qty ресурса (resources.name_en = target) в инвентаре.
                $target = $row['objective_target'] ?? null;
                if (!is_string($target) || $target === '') {
                    return false;
                }
                return $this->resourceCount($db, $cid, $target) >= $qty;

            case 'building_level':
                // ADR-088: построить/прокачать здание (buildings.name_en = target) до ур.≥qty.
                $target = $row['objective_target'] ?? null;
                if (!is_string($target) || $target === '') {
                    return false;
                }
                return $this->maxBuildingLevel($db, $cid, $target) >= $qty;

            // ── ADR-103 Слой 2: онбординг-типы (target не нужен, всегда qty=1) ──────

            case 'claim_base':
                // Есть активная база (разбит лагерь).
                return $db->table('claimed_cells')
                    ->where('character_id', $cid)
                    ->where('status', 'active')
                    ->countAllResults() >= $qty;

            case 'open_base_screen':
                // Открывал экран своей базы (event-флаг в action_log).
                return $db->table('action_log')
                    ->where('character_id', $cid)
                    ->where('action_name', \App\Services\Onboarding\OnboardingChainService::BASE_OPENED_EVENT)
                    ->countAllResults() >= $qty;

            case 'craft_any':
                // Скрафтил хоть что-нибудь (любая запись в crafted_items_log).
                return $db->table('crafted_items_log')
                    ->where('character_id', $cid)
                    ->countAllResults() >= $qty;

            case 'any_building':
                // Построил хоть одну постройку (любое здание ур.≥1).
                return $db->table('character_buildings')
                    ->where('character_id', $cid)
                    ->where('level >=', 1)
                    ->countAllResults() >= $qty;

            case 'sell_any':
                // E3 (ROADMAP-100): продал хоть что-нибудь — опирается на форензик-лог
                // продаж v0.51.389 (SELL_RESOURCE поштучно/forcereply, BULK_SELL оптом).
                return $db->table('action_log')
                    ->where('character_id', $cid)
                    ->whereIn('action_name', ['SELL_RESOURCE', 'BULK_SELL'])
                    ->countAllResults() >= $qty;

            default:
                return false;
        }
    }

    /**
     * Сумма quantity ресурса (resources.name_en=$target) в инвентаре персонажа
     * (character_resources.id_characters / id_resources). ADR-088.
     *
     * @param BaseConnection<object, object> $db
     */
    private function resourceCount(BaseConnection $db, int $characterId, string $target): int
    {
        $refRes = $db->table('resources')->select('id')->where('name_en', $target)->get();
        $refRow = $refRes === false ? null : $refRes->getRowArray();
        if (!is_array($refRow) || !is_numeric($refRow['id'] ?? null)) {
            return 0;
        }
        $sumRes = $db->table('character_resources')
            ->selectSum('quantity', 'total')
            ->where('id_characters', $characterId)
            ->where('id_resources', (int) $refRow['id'])
            ->get();
        $sumRow = $sumRes === false ? null : $sumRes->getRowArray();
        return is_array($sumRow) && is_numeric($sumRow['total'] ?? null) ? (int) $sumRow['total'] : 0;
    }

    /**
     * Максимальный уровень здания (buildings.name_en=$target), построенного персонажем
     * (character_buildings.character_id / building_id / level). 0 если не построено. ADR-088.
     *
     * @param BaseConnection<object, object> $db
     */
    private function maxBuildingLevel(BaseConnection $db, int $characterId, string $target): int
    {
        $refRes = $db->table('buildings')->select('id')->where('name_en', $target)->get();
        $refRow = $refRes === false ? null : $refRes->getRowArray();
        if (!is_array($refRow) || !is_numeric($refRow['id'] ?? null)) {
            return 0;
        }
        $maxRes = $db->table('character_buildings')
            ->selectMax('level', 'maxlvl')
            ->where('character_id', $characterId)
            ->where('building_id', (int) $refRow['id'])
            ->get();
        $maxRow = $maxRes === false ? null : $maxRes->getRowArray();
        return is_array($maxRow) && is_numeric($maxRow['maxlvl'] ?? null) ? (int) $maxRow['maxlvl'] : 0;
    }

    /**
     * Сколько единиц предмета `$target` (name) есть у персонажа — суммарно по ВСЕМ
     * путям материализации крафта:
     *   - crafted_items_log (default crafted_item output, name_eng);
     *   - characters_weapons (output_type=weapon, weapons.name_en);
     *   - characters_outfits (output_type=outfit, outfits.name_en).
     *
     * Без weapon/outfit-ветки craft_item-цель на faction-weapon никогда не
     * выполнялась бы: оружие пишется в characters_weapons мимо crafted_items_log
     * (а crafted_items строк для оружия нет вовсе) → финалы цепочек V12/V13
     * (Bunker/Technopark/GhostCity/IslandFarm) были бы недостижимы. ADR-037.
     *
     * @param BaseConnection<object, object> $db
     */
    private function craftedCount(BaseConnection $db, int $characterId, string $target): int
    {
        $total = 0;

        // 1. crafted_item → crafted_items_log.
        $itemRes = $db->table('crafted_items')->select('id')->where('name_eng', $target)->get();
        $itemRow = $itemRes === false ? null : $itemRes->getRowArray();
        if (is_array($itemRow) && is_numeric($itemRow['id'] ?? null)) {
            $logRes = $db->table('crafted_items_log')
                ->selectSum('quantity', 'total')
                ->where('character_id', $characterId)
                ->where('crafted_item_id', (int) $itemRow['id'])
                ->get();
            $logRow = $logRes === false ? null : $logRes->getRowArray();
            if (is_array($logRow) && is_numeric($logRow['total'] ?? null)) {
                $total += (int) $logRow['total'];
            }
        }

        // 2. weapon → characters_weapons. 3. outfit → characters_outfits.
        $total += $this->ownedCount($db, $characterId, 'weapons', 'characters_weapons', 'weapon_id', $target);
        $total += $this->ownedCount($db, $characterId, 'outfits', 'characters_outfits', 'outfit_id', $target);

        return $total;
    }

    /**
     * Сумма quantity в pivot-таблице ($pivotTable) для предмета с name_en=$target
     * из справочника ($refTable). 0, если справочник не содержит такого name_en.
     *
     * @param BaseConnection<object, object> $db
     */
    private function ownedCount(BaseConnection $db, int $characterId, string $refTable, string $pivotTable, string $fkColumn, string $target): int
    {
        $refRes = $db->table($refTable)->select('id')->where('name_en', $target)->get();
        $refRow = $refRes === false ? null : $refRes->getRowArray();
        if (!is_array($refRow) || !is_numeric($refRow['id'] ?? null)) {
            return 0;
        }
        $sumRes = $db->table($pivotTable)
            ->selectSum('quantity', 'total')
            ->where('character_id', $characterId)
            ->where($fkColumn, (int) $refRow['id'])
            ->get();
        $sumRow = $sumRes === false ? null : $sumRes->getRowArray();
        return is_array($sumRow) && is_numeric($sumRow['total'] ?? null) ? (int) $sumRow['total'] : 0;
    }

    /**
     * ADR-103 Слой 2 — завершение онбординг-шага: вместо generic-уведомления шлём
     * персональную just-in-time подсказку «что делать дальше» из OnboardingChainCatalog
     * (ведёт к следующему конкретному действию). Самодостаточна в тексте (media-off).
     *
     * @param array<string,mixed> $character
     */
    private function notifyOnboarding(array $character, string $titleEn, int $reward): void
    {
        $chatId = $this->resolveChatId($character);
        if ($chatId === 0) {
            return;
        }

        $completion = \App\Services\Onboarding\OnboardingChainCatalog::completion($titleEn);
        if ($completion === null) {
            return;
        }

        // ADR-139 слайс 4 (win-beat): усиление завершения шага (полоса прогресса /
        // капстон «глава 1 закрыта»). При killswitch OFF compose()→null → легаси-путь
        // ниже шлёт текст байт-в-байт.
        $winBeat = (new \App\Services\Onboarding\WinBeatService())->compose($titleEn, $completion['text'], $reward);
        if ($winBeat !== null) {
            $text = $winBeat;
        } else {
            $text = $completion['text'];
            if ($reward > 0) {
                $text .= "\n\n🏆 +{$reward} золота за шаг.";
            }
        }

        $this->safeSendMessage($chatId, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => $completion['reply_markup'],
        ]);
    }

    /**
     * chat_id персонажа (telegram_users.telegram_id) или 0, если не найден.
     *
     * @param array<string,mixed> $character
     */
    private function resolveChatId(array $character): int
    {
        $tgUserId = is_numeric($character['telegram_user_id'] ?? null) ? (int) $character['telegram_user_id'] : 0;
        if ($tgUserId <= 0) {
            return 0;
        }
        $tg = $this->telegramUserModel->find($tgUserId);
        if (!is_array($tg) || empty($tg['telegram_id'])) {
            return 0;
        }
        return is_numeric($tg['telegram_id']) ? (int) $tg['telegram_id'] : 0;
    }

    /**
     * @param array<string,mixed> $character
     * @param list<string> $advanced
     * @param list<array{quest_id:int,title_en:string,title_ru:string,label:string}> $branchOptions
     */
    private function notify(array $character, string $titleRu, int $reward, array $advanced, array $branchOptions = []): void
    {
        $chatId = $this->resolveChatId($character);
        if ($chatId === 0) {
            return;
        }

        // W11 (ADR-067): branch-point завершён → развилка с выбором пути.
        if (!empty($branchOptions)) {
            $msg  = "🔀 *Развилка!*\n";
            $msg .= "✅ *{$titleRu}*";
            if ($reward > 0) {
                $msg .= " (+{$reward} золота)";
            }
            $msg .= "\n\nВыбери, как продолжить — *решение необратимо*:";

            $rows = [];
            $pair = [];
            foreach ($branchOptions as $opt) {
                $pair[] = ['text' => $opt['label'], 'callback_data' => 'questBranch_' . $opt['quest_id']];
                if (count($pair) === 2) {
                    $rows[] = $pair;
                    $pair = [];
                }
            }
            if (!empty($pair)) {
                $rows[] = $pair;
            }
            $this->safeSendMessage($chatId, $msg, [
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $rows]),
            ]);
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
