<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * E27 (ADR-126) — агрегатор «единого экрана Задания».
 *
 * Сводит в одну read-only сводку счётчики ВСЕХ источников квестов/заданий, разбросанных
 * по разным экранам: активные шаги (цепочки V11/V12), стартуемые «корни» (ADR-088),
 * ежедневные задания (ADR-109, раньше были видны ТОЛЬКО с карточки Перса), развилки
 * W11 (ADR-067), NPC-поручения (ADR-089 questgiver) и завершённые. Питает дашборд
 * {@see \App\Controllers\Telegram\Commands\Actions\Quest\QuestAndTaskAction}.
 *
 * `classifyFrom` — ЧИСТАЯ фильтрация «доступно/заблокировано» (зеркало старого in-loop
 * кода AvailableQuests, но без per-quest builder-state-quirk: шаги префетчатся одним
 * запросом). {@see AvailableQuests} делегирует сюда же → единый источник, без дрейфа
 * двух копий логики.
 *
 * Killswitch не нужен — чистая презентация поверх существующих фич; каждый источник
 * уважает свой собственный killswitch (daily.enabled / branching_enabled /
 * npc.questgiver_enabled). Класс НЕ final, БД-сеймы overridable — для unit-тестов.
 */
class QuestOverviewService
{
    use GameSettingsReaderTrait;

    private QuestModel $questModel;
    private QuestStepsModel $questStepsModel;
    private QuestChainService $chain;
    private DailyTaskService $daily;

    public function __construct(
        ?QuestModel $questModel = null,
        ?QuestStepsModel $questStepsModel = null,
        ?QuestChainService $chain = null,
        ?DailyTaskService $daily = null
    ) {
        $this->questModel      = $questModel ?? new QuestModel();
        $this->questStepsModel = $questStepsModel ?? new QuestStepsModel();
        $this->chain           = $chain ?? new QuestChainService();
        $this->daily           = $daily ?? new DailyTaskService();
    }

    /**
     * Разбивка доступных персонажу квестов на стартуемые сейчас и заблокированные цепочкой.
     *
     * @return array{available: list<array<int|string,mixed>>, locked: list<array{quest: array<int|string,mixed>, prereq: ?string}>}
     */
    public function classifyQuests(int $level, int $charId, int $charFactionId): array
    {
        return $this->classifyFrom(
            $this->questModel->getAvailableQuests($level),
            $this->existingStepQuestIds($charId),
            $this->questModel->getCompletedQuestTitles($charId),
            $charFactionId
        );
    }

    /**
     * Чистая фильтрация (без БД) — тестируется изолированно.
     *
     * Квест стартуем сейчас, если: status active + min_level ≤ level (уже отфильтровано
     * caller'ом), у персонажа ещё нет шага по нему, и либо это расширенный startable-«корень»
     * (ADR-088) прошедший фракционный гейт, либо bespoke-квест с выполненным предусловием.
     * Bespoke с невыполненным prerequisite → locked (тизер цепочки).
     *
     * @param array<array-key, mixed>       $quests       кандидаты (status active, min_level ≤ level)
     * @param list<int>                     $existingStepQuestIds quest_id квестов, по которым у перса уже есть шаг
     * @param list<string>                  $completedTitles      title_en завершённых квестов
     * @return array{available: list<array<int|string,mixed>>, locked: list<array{quest: array<int|string,mixed>, prereq: ?string}>}
     */
    public function classifyFrom(array $quests, array $existingStepQuestIds, array $completedTitles, int $charFactionId): array
    {
        $available = [];
        $locked    = [];

        foreach ($quests as $quest) {
            if (! is_array($quest)) {
                continue;
            }
            $qid = is_numeric($quest['id'] ?? null) ? (int) $quest['id'] : 0;
            if ($qid === 0 || in_array($qid, $existingStepQuestIds, true)) {
                continue; // нет id или шаг уже есть (взят/завершён)
            }

            // ADR-088: расширенный STANDALONE «корень» + (Фаза 3) фракционный гейт.
            // objective-квесты с prerequisite / discover_object — авто-управляемые (не корни).
            if (! empty($quest['objective_type'])) {
                if ($this->chain->isExtendedStartableRoot($quest) && $this->chain->factionGateOk($quest, $charFactionId)) {
                    $available[] = $quest;
                }
                continue;
            }

            // V11: bespoke-квест — доступен только при выполненном предусловии цепочки.
            $prereq = $this->chain->prerequisiteOf($quest);
            if (! $this->chain->prerequisiteMet($prereq, $completedTitles)) {
                $locked[] = ['quest' => $quest, 'prereq' => $prereq];
                continue;
            }

            $available[] = $quest;
        }

        return ['available' => $available, 'locked' => $locked];
    }

    /**
     * Сводка всех источников для дашборда «📜 Задания».
     *
     * @return array{
     *   active:int, available:int, locked:int, completed:int,
     *   daily:array{enabled:bool, assigned:bool, done:int, total:int, bonus:int},
     *   branches:int, npc_hint:bool
     * }
     */
    public function summary(int $level, int $charId, int $charFactionId): array
    {
        $cls = $this->classifyQuests($level, $charId, $charFactionId);

        $dailyEnabled = $this->daily->enabled();
        $dailyTasks   = $dailyEnabled ? $this->daily->today($charId) : [];
        $dailyDone    = 0;
        foreach ($dailyTasks as $t) {
            if (! empty($t['is_completed'])) {
                $dailyDone++;
            }
        }

        return [
            'active'    => $this->activeStepCount($charId),
            'available' => count($cls['available']),
            'locked'    => count($cls['locked']),
            'completed' => count($this->questModel->getCompletedQuestTitles($charId)),
            'daily'     => [
                'enabled'  => $dailyEnabled,
                'assigned' => $dailyTasks !== [],
                'done'     => $dailyDone,
                'total'    => count($dailyTasks),
                'bonus'    => $this->daily->allDoneBonusGold(),
            ],
            'branches'  => count($this->chain->pendingBranchesForCharacter($charId)),
            'npc_hint'  => $this->questgiverEnabled(),
        ];
    }

    /**
     * quest_id всех существующих шагов персонажа (любой статус) — один запрос
     * (вместо per-quest first() в цикле, см. feedback_ci4_model_builder_state_quirk).
     *
     * @return list<int>
     */
    protected function existingStepQuestIds(int $charId): array
    {
        $ids = [];
        foreach ($this->questStepsModel->where('character_id', $charId)->findAll() as $s) {
            if (is_array($s) && is_numeric($s['quest_id'] ?? null)) {
                $ids[] = (int) $s['quest_id'];
            }
        }

        return $ids;
    }

    /** Кол-во активных (незавершённых) шагов персонажа. */
    protected function activeStepCount(int $charId): int
    {
        return (int) $this->questStepsModel->db->table('quest_steps')
            ->where('character_id', $charId)
            ->where('is_completed', 0)
            ->countAllResults();
    }

    /** ADR-089: включён ли NPC-questgiver (для намёка «у выживших есть поручения»). */
    protected function questgiverEnabled(): bool
    {
        return $this->gsBool('npc.questgiver_enabled', false);
    }
}
