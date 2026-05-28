<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * V11 (ADR-036) — цепочки квестов: квест доступен только после завершения его
 * предусловия (`quests.prerequisite_quest` = title_en предыдущего квеста).
 * V12 (ADR-037) — advanceChain: авто-назначение следующего этапа при завершении.
 *
 * Чистая логика prerequisite (caller отдаёт список завершённых title_en) →
 * тестируемо без БД. Killswitch `quests.chains_enabled`. Многоэтапность = цепочка
 * связанных квестов; foundation для V12/V13 + T3→T4 (S25 required_quest на финал).
 */
final class QuestChainService
{
    private GameSettingsService $settings;
    private ?QuestModel $questModel;
    private ?QuestStepsModel $questStepsModel;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?QuestModel $questModel = null,
        ?QuestStepsModel $questStepsModel = null
    ) {
        $this->settings        = $settings ?? new GameSettingsService();
        $this->questModel      = $questModel;
        $this->questStepsModel = $questStepsModel;
    }

    /** Killswitch: false → prerequisite-гейт отключён (все квесты доступны). */
    public function chainsEnabled(): bool
    {
        $v = $this->settings->get('quests.chains_enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Выполнено ли предусловие квеста. true если: гейт выключен / нет предусловия /
     * предусловие в списке завершённых квестов персонажа.
     *
     * @param list<string> $completedQuestTitles title_en завершённых квестов
     */
    public function prerequisiteMet(?string $prerequisite, array $completedQuestTitles): bool
    {
        if (! $this->chainsEnabled()) {
            return true;
        }
        if ($prerequisite === null || $prerequisite === '') {
            return true;
        }
        return in_array($prerequisite, $completedQuestTitles, true);
    }

    /**
     * prerequisite_quest квеста (нормализация значения колонки). '' / null → null.
     *
     * @param array<int|string,mixed> $quest
     */
    public function prerequisiteOf(array $quest): ?string
    {
        $p = $quest['prerequisite_quest'] ?? null;
        return is_string($p) && $p !== '' ? $p : null;
    }

    /**
     * V12 (ADR-037) — авто-назначение следующих этапов цепочки персонажу при
     * завершении квеста `$completedTitleEn`: для каждого active-квеста с
     * prerequisite_quest=$completedTitleEn, у которого у персонажа ещё нет шага,
     * создаётся незавершённый шаг (→ его подхватит QuestObjectiveHandler).
     *
     * Без manual-start: цепочка прогрессирует сама. Killswitch-aware.
     *
     * @return list<string> title_en назначенных этапов
     */
    public function advanceChain(int $characterId, string $completedTitleEn): array
    {
        if ($completedTitleEn === '' || ! $this->chainsEnabled()) {
            return [];
        }
        $questModel      = $this->questModel ?? new QuestModel();
        $questStepsModel = $this->questStepsModel ?? new QuestStepsModel();

        $next = $questModel->where('prerequisite_quest', $completedTitleEn)
            ->where('status', 'active')
            ->findAll();
        if (empty($next)) {
            return [];
        }

        // Один запрос: quest_id всех существующих шагов персонажа (избегаем where-in-loop).
        $existingQuestIds = [];
        foreach ($questStepsModel->where('character_id', $characterId)->findAll() as $s) {
            if (is_array($s) && is_numeric($s['quest_id'] ?? null)) {
                $existingQuestIds[] = (int) $s['quest_id'];
            }
        }

        $advanced = [];
        foreach ($next as $q) {
            if (! is_array($q)) {
                continue;
            }
            $qId = is_numeric($q['id'] ?? null) ? (int) $q['id'] : 0;
            if ($qId === 0 || in_array($qId, $existingQuestIds, true)) {
                continue;
            }
            // W11 (ADR-067): ветки развилки (branch_group непустой) НЕ авто-назначаются —
            // только через явный выбор игрока (pendingBranchOptions → chooseBranch).
            // Линейные звенья (branch_group=null) — как прежде (0 регрессии V11/V12/V13).
            if ($this->branchGroupOf($q) !== '') {
                continue;
            }
            $questStepsModel->insert([
                'quest_id'     => $qId,
                'character_id' => $characterId,
                'step_order'   => 1,
                'description'  => is_string($q['title_ru'] ?? null) ? $q['title_ru'] : 'Этап цепочки',
                'is_completed' => 0,
            ]);
            $advanced[] = is_string($q['title_en'] ?? null) ? $q['title_en'] : '';
        }
        return $advanced;
    }

    // ── W11 (ADR-067): branching engine ──────────────────────────────────

    /** Killswitch branching: false → развилки dormant (ветки не предлагаются). Default OFF. */
    public function branchingEnabled(): bool
    {
        $v = $this->settings->get('quests.branching_enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * branch_group квеста, нормализованный ('' если нет).
     *
     * @param array<int|string,mixed> $quest
     */
    public function branchGroupOf(array $quest): string
    {
        $g = $quest['branch_group'] ?? null;
        return is_string($g) ? trim($g) : '';
    }

    /**
     * branch_label квеста (fallback title_ru → title_en).
     *
     * @param array<int|string,mixed> $quest
     */
    public function branchLabelOf(array $quest): string
    {
        $l = $quest['branch_label'] ?? null;
        if (is_string($l) && trim($l) !== '') {
            return trim($l);
        }
        if (is_string($quest['title_ru'] ?? null) && $quest['title_ru'] !== '') {
            return $quest['title_ru'];
        }
        return is_string($quest['title_en'] ?? null) ? $quest['title_en'] : '';
    }

    /**
     * Опции развилки, если выбор для персонажа сейчас pending (branching включён,
     * branch-квесты с prerequisite=$prereqTitleEn существуют, и персонаж ещё ни одну
     * ветку не выбрал). Killswitch OFF → [] (dormant).
     *
     * @return list<array{quest_id:int,title_en:string,title_ru:string,label:string}>
     */
    public function pendingBranchOptions(int $characterId, string $prereqTitleEn): array
    {
        if ($prereqTitleEn === '' || ! $this->branchingEnabled()) {
            return [];
        }
        $questModel      = $this->questModel ?? new QuestModel();
        $questStepsModel = $this->questStepsModel ?? new QuestStepsModel();

        $branches = $questModel->getActiveBranchQuests($prereqTitleEn);
        if ($branches === []) {
            return [];
        }
        $branchIds = $this->questIds($branches);
        if ($branchIds === []) {
            return [];
        }
        // Выбор уже сделан (есть шаг у любой ветки группы) → не pending.
        if ($questStepsModel->countStepsForQuests($characterId, $branchIds) > 0) {
            return [];
        }
        return $this->mapBranchOptions($branches);
    }

    /**
     * Все pending-развилки персонажа (для hub-экрана AvailableQuests). Killswitch OFF → [].
     *
     * @return list<array{branch_point_ru:string,options:list<array{quest_id:int,title_en:string,title_ru:string,label:string}>}>
     */
    public function pendingBranchesForCharacter(int $characterId): array
    {
        if (! $this->branchingEnabled()) {
            return [];
        }
        $questModel      = $this->questModel ?? new QuestModel();
        $questStepsModel = $this->questStepsModel ?? new QuestStepsModel();

        $completed = $questModel->getCompletedQuestTitles($characterId);
        if ($completed === []) {
            return [];
        }
        $candidates = $questModel->getActiveBranchQuestsForPrereqs($completed);
        if ($candidates === []) {
            return [];
        }

        // Группируем по [prerequisite_quest, branch_group].
        /** @var array<string,array{prereq:string,quests:list<array<int|string,mixed>>}> $groups */
        $groups = [];
        foreach ($candidates as $q) {
            $group  = $this->branchGroupOf($q);
            $prereq = $this->prerequisiteOf($q);
            if ($group === '' || $prereq === null) {
                continue;
            }
            $key = $prereq . "\x00" . $group;
            if (! isset($groups[$key])) {
                $groups[$key] = ['prereq' => $prereq, 'quests' => []];
            }
            $groups[$key]['quests'][] = $q;
        }

        $out = [];
        foreach ($groups as $g) {
            $ids = $this->questIds($g['quests']);
            if ($ids === [] || $questStepsModel->countStepsForQuests($characterId, $ids) > 0) {
                continue; // выбор уже сделан или нет валидных веток
            }
            $out[] = [
                'branch_point_ru' => $questModel->titleRuByEn($g['prereq']),
                'options'         => $this->mapBranchOptions($g['quests']),
            ];
        }
        return $out;
    }

    /**
     * Зафиксировать выбор ветки развилки: валидирует (branching on / квест — ветка /
     * prerequisite завершён / квест active / сиблинг ещё не выбран) и создаёт quest_steps
     * для выбранной ветки. Идемпотентно (повтор → already_chosen).
     *
     * @return array{ok:bool,reason:string,title_ru:string,reward:int}
     */
    public function chooseBranch(int $characterId, int $questId): array
    {
        $fail = static fn (string $reason): array => ['ok' => false, 'reason' => $reason, 'title_ru' => '', 'reward' => 0];

        if (! $this->branchingEnabled()) {
            return $fail('disabled');
        }
        if ($questId <= 0) {
            return $fail('not_found');
        }
        $questModel      = $this->questModel ?? new QuestModel();
        $questStepsModel = $this->questStepsModel ?? new QuestStepsModel();

        $quest = $questModel->find($questId);
        if (! is_array($quest)) {
            return $fail('not_found');
        }
        if ($this->branchGroupOf($quest) === '') {
            return $fail('not_a_branch');
        }
        if (($quest['status'] ?? '') !== 'active') {
            return $fail('inactive');
        }
        $prereq = $this->prerequisiteOf($quest);
        if ($prereq === null) {
            return $fail('not_a_branch');
        }
        if (! in_array($prereq, $questModel->getCompletedQuestTitles($characterId), true)) {
            return $fail('prereq_not_met');
        }

        // Сиблинги той же развилки (тот же prerequisite + тот же branch_group).
        $group    = $this->branchGroupOf($quest);
        $siblings = $questModel->getActiveBranchQuests($prereq);
        $siblingIds = [];
        foreach ($siblings as $s) {
            if ($this->branchGroupOf($s) === $group && is_numeric($s['id'] ?? null)) {
                $siblingIds[] = (int) $s['id'];
            }
        }
        if ($siblingIds !== [] && $questStepsModel->countStepsForQuests($characterId, $siblingIds) > 0) {
            return $fail('already_chosen');
        }

        $titleRu = is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : 'Этап развилки';
        $questStepsModel->insert([
            'quest_id'     => $questId,
            'character_id' => $characterId,
            'step_order'   => 1,
            'description'  => $titleRu,
            'is_completed' => 0,
        ]);

        return [
            'ok'       => true,
            'reason'   => '',
            'title_ru' => $titleRu,
            'reward'   => is_numeric($quest['reward'] ?? null) ? (int) $quest['reward'] : 0,
        ];
    }

    /**
     * @param list<array<int|string,mixed>> $branches
     * @return list<array{quest_id:int,title_en:string,title_ru:string,label:string}>
     */
    private function mapBranchOptions(array $branches): array
    {
        $out = [];
        foreach ($branches as $b) {
            if ($this->branchGroupOf($b) === '') {
                continue;
            }
            $qId = is_numeric($b['id'] ?? null) ? (int) $b['id'] : 0;
            if ($qId === 0) {
                continue;
            }
            $out[] = [
                'quest_id' => $qId,
                'title_en' => is_string($b['title_en'] ?? null) ? $b['title_en'] : '',
                'title_ru' => is_string($b['title_ru'] ?? null) ? $b['title_ru'] : '',
                'label'    => $this->branchLabelOf($b),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<int|string,mixed>> $quests
     * @return list<int>
     */
    private function questIds(array $quests): array
    {
        $ids = [];
        foreach ($quests as $q) {
            if (is_numeric($q['id'] ?? null)) {
                $ids[] = (int) $q['id'];
            }
        }
        return $ids;
    }
}
