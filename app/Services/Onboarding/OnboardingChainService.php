<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Entities\CharacterEntity;
use App\Models\ActionLogModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * ADR-103 Часть B Слой 2 — обучающая квест-цепочка «Первые шаги выжившего».
 *
 * Оркестрирует онбординг-цепочку поверх движка квестов (ADR-036/037/088):
 *   • {@see ensureChainAssigned} — назначает новичку ПЕРВЫЙ шаг (остальные авто-advance
 *     движком цепочек через prerequisite). Вызывается на /start.
 *   • {@see recordBaseOpened} / {@see baseOpened} — event-флаг «игрок открыл экран базы»
 *     (objective_type=open_base_screen — событие, не состояние; трекинг в action_log,
 *     тот же паттерн, что у one-shot подсказок Слоя 1 → НОВОЙ таблицы не нужно).
 *
 * Гейты: собственный killswitch `onboarding.quest_chain.enabled` (ОТДЕЛЬНЫЙ от
 * `quests.extended_enabled` — онбординг катится независимо от расширенных квестов) +
 * потолок уровня (reuse `onboarding.contextual_hints.max_level`, единое определение
 * «новичка»). Default killswitch OFF → dormant (контент сидится, но молчит до активации).
 *
 * Тексты/порядок шагов — {@see OnboardingChainCatalog} (единый источник истины).
 * DB/seam'ы overridable (protected) → тестируется без БД на CI (memory
 * feedback_taskhandler_telegram_init_in_tests).
 */
class OnboardingChainService
{
    use GameSettingsReaderTrait;

    /** action_log.action_name события «открыл экран своей базы». */
    public const BASE_OPENED_EVENT = 'OnbEvent_open_base_screen';

    public function __construct(
        private readonly ?QuestModel $questModel = null,
        private readonly ?QuestStepsModel $questStepsModel = null,
        private readonly ?ActionLogModel $log = null,
    ) {
    }

    /** Killswitch: онбординг-цепочка активна. Default false → dormant. */
    public function chainEnabled(): bool
    {
        return $this->gsBool('onboarding.quest_chain.enabled', false);
    }

    /** Потолок уровня «новичка» (общий с контекстными подсказками Слоя 1). */
    public function maxLevel(): int
    {
        return $this->gsInt('onboarding.contextual_hints.max_level', 6);
    }

    /**
     * Назначить новичку первый шаг цепочки, если он ещё не назначен. Идемпотентно.
     * Гейты: killswitch ON + level ≤ max + корневой квест существует + шага ещё нет.
     *
     * @param array<string, mixed>|CharacterEntity $character
     * @return bool true — шаг только что назначен (иначе уже был / не положен)
     */
    public function ensureChainAssigned(array|CharacterEntity $character): bool
    {
        if (! $this->chainEnabled()) {
            return false;
        }
        if (self::intField($character, 'level') > $this->maxLevel()) {
            return false;
        }
        $charId = self::intField($character, 'id');
        if ($charId <= 0) {
            return false;
        }

        $rootId = $this->rootQuestId();
        if ($rootId <= 0) {
            return false;
        }

        // Уже есть шаг по корню (в т.ч. завершённый = цепочка уже стартовала) → не дублируем.
        if ($this->hasStep($charId, $rootId)) {
            return false;
        }

        $root = OnboardingChainCatalog::steps()[0];
        $this->insertStep($charId, $rootId, $root['title_ru']);

        return true;
    }

    /**
     * Записать событие «игрок открыл экран своей базы» (один раз). Гейтится killswitch'ем
     * и уровнем — чтобы не писать в action_log за ветеранов. Идемпотентно.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function recordBaseOpened(array|CharacterEntity $character, int $chatId): void
    {
        if (! $this->chainEnabled()) {
            return;
        }
        if (self::intField($character, 'level') > $this->maxLevel()) {
            return;
        }
        $charId = self::intField($character, 'id');
        if ($charId <= 0 || $this->hasBaseOpenedEvent($charId)) {
            return;
        }

        $this->writeBaseOpenedEvent($charId, $chatId);
    }

    /** Открывал ли игрок экран своей базы (для objective open_base_screen). */
    public function baseOpened(int $charId): bool
    {
        return $this->hasBaseOpenedEvent($charId);
    }

    // ── Overridable seam'ы (тесты подменяют — без БД на CI) ──────────────────

    /** id корневого active-квеста цепочки (0 если не засижен). */
    protected function rootQuestId(): int
    {
        $row = ($this->questModel ?? new QuestModel())
            ->where('title_en', OnboardingChainCatalog::ROOT)
            ->where('status', 'active')
            ->first();

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    protected function hasStep(int $charId, int $questId): bool
    {
        return ($this->questStepsModel ?? new QuestStepsModel())
            ->where('character_id', $charId)
            ->where('quest_id', $questId)
            ->countAllResults() > 0;
    }

    protected function insertStep(int $charId, int $questId, string $titleRu): void
    {
        ($this->questStepsModel ?? new QuestStepsModel())->insert([
            'quest_id'     => $questId,
            'character_id' => $charId,
            'step_order'   => 1,
            'description'  => $titleRu,
            'is_completed' => 0,
        ]);
    }

    protected function hasBaseOpenedEvent(int $charId): bool
    {
        return $this->logModel()
            ->where('character_id', $charId)
            ->where('action_name', self::BASE_OPENED_EVENT)
            ->countAllResults() > 0;
    }

    protected function writeBaseOpenedEvent(int $charId, int $chatId): void
    {
        $this->logModel()->insert([
            'character_id'  => $charId,
            'chat_id'       => $chatId,
            'action_name'   => self::BASE_OPENED_EVENT,
            'action_status' => 'done',
            'description'   => 'ADR-103 onboarding: открыл экран базы',
        ]);
    }

    private function logModel(): ActionLogModel
    {
        return $this->log ?? new ActionLogModel();
    }

    /**
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function intField(array|CharacterEntity $character, string $field): int
    {
        $raw = $character[$field] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
