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
     * @param array<string,mixed> $quest
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
}
