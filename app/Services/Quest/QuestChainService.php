<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Services\GameSettings\GameSettingsService;

/**
 * V11 (ADR-036) — цепочки квестов: квест доступен только после завершения его
 * предусловия (`quests.prerequisite_quest` = title_en предыдущего квеста).
 *
 * Чистая логика prerequisite (caller отдаёт список завершённых title_en) →
 * тестируемо без БД. Killswitch `quests.chains_enabled`. Многоэтапность = цепочка
 * связанных квестов; foundation для V12/V13 + T3→T4 (S25 required_quest на финал).
 */
final class QuestChainService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
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
}
