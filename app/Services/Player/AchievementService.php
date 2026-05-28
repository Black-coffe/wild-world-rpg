<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\AchievementModel;
use App\Models\CharacterAchievementModel;
use App\Services\GameSettings\GameSettingsService;
use Throwable;

/**
 * W9 (ADR-066) — Achievement foundation. Cron-poll state-driven award-движок
 * (зеркало QuestObjectiveHandler): достижения выдаются по проверке persistent state,
 * без правок gameplay-хэндлеров и без шины событий.
 *
 * Killswitch `achievement.enabled` (GameSettings, default false = dormant на ship;
 * W10 активирует с контентом + UI). Throttle `achievement.max_awards_per_tick`.
 *
 * criteria_type (set-based SQL):
 *  - char_level / gold_total      → колонки characters
 *  - explored_cells               → COUNT explored_cells
 *  - craft_total                  → SUM crafted_items_log.quantity
 *  - has_base                     → EXISTS claimed_cells (status=active)
 *  - has_faction                  → EXISTS character_factions (joined_at, faction_id<>5)
 *  - quests_completed             → COUNT quest_steps (is_completed=1)
 */
final class AchievementService
{
    private AchievementModel $achievements;
    private CharacterAchievementModel $charAchievements;
    private GameSettingsService $settings;

    public function __construct(
        ?AchievementModel $achievements = null,
        ?CharacterAchievementModel $charAchievements = null,
        ?GameSettingsService $settings = null
    ) {
        $this->achievements     = $achievements ?? new AchievementModel();
        $this->charAchievements = $charAchievements ?? new CharacterAchievementModel();
        $this->settings         = $settings ?? new GameSettingsService();
    }

    /** Killswitch всей системы. default false (dormant на ship W9). */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('achievement.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Сколько выдач за один тик cron (throttle против notification-шторма). */
    public function maxAwardsPerTick(): int
    {
        $v = $this->settings->get('achievement.max_awards_per_tick', 25);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 25;
    }

    /**
     * Включённые достижения по возрастанию sort_order.
     *
     * @return list<array<int|string,mixed>>
     */
    public function definitions(): array
    {
        $rows = $this->achievements
            ->where('enabled', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * ID достижений, уже выданных персонажу.
     *
     * @return list<int>
     */
    public function unlockedAchievementIds(int $characterId): array
    {
        $rows = $this->charAchievements
            ->select('achievement_id')
            ->where('character_id', $characterId)
            ->findAll();

        $ids = [];
        foreach ($rows as $row) {
            $raw = is_array($row) ? ($row['achievement_id'] ?? null) : null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /**
     * Идемпотентно выдать достижение персонажу. true — если выдано впервые,
     * false — если уже было (или гонка поймана UNIQUE).
     */
    public function award(int $characterId, int $achievementId): bool
    {
        if ($characterId <= 0 || $achievementId <= 0) {
            return false;
        }

        $exists = $this->charAchievements
            ->where('character_id', $characterId)
            ->where('achievement_id', $achievementId)
            ->first();
        if ($exists !== null) {
            return false;
        }

        try {
            $this->charAchievements->insert([
                'character_id'   => $characterId,
                'achievement_id' => $achievementId,
                'unlocked_at'    => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (Throwable) {
            // Гонка: UNIQUE(character_id, achievement_id) поймал параллельную выдачу.
            return false;
        }
    }

    /**
     * ID персонажей, которые ВЫПОЛНИЛИ критерий достижения и ещё НЕ получили его.
     * Set-based (один запрос). $limit ограничивает выборку (cron-cap).
     *
     * @param array<int|string,mixed> $achievement строка из achievements
     * @return list<int>
     */
    public function qualifyingCharacterIds(array $achievement, int $limit = 0): array
    {
        $achId  = is_numeric($achievement['id'] ?? null) ? (int) $achievement['id'] : 0;
        $type   = is_string($achievement['criteria_type'] ?? null) ? $achievement['criteria_type'] : '';
        $target = is_numeric($achievement['criteria_target'] ?? null) ? (int) $achievement['criteria_target'] : 1;
        if ($achId <= 0) {
            return [];
        }

        $criteria = $this->criteriaFragment($type, $target);
        if ($criteria === null) {
            return [];
        }
        [$fragment, $fragBindings] = $criteria;

        $sql = 'SELECT c.id FROM characters c '
            . 'LEFT JOIN character_achievements ca ON ca.character_id = c.id AND ca.achievement_id = ? '
            . 'WHERE ca.id IS NULL AND ' . $fragment;
        $bindings = array_merge([$achId], $fragBindings);

        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $bindings[] = $limit;
        }

        $db    = \Config\Database::connect();
        $query = $db->query($sql, $bindings);
        if (! is_object($query) || ! method_exists($query, 'getResultArray')) {
            return [];
        }

        $ids = [];
        foreach ($query->getResultArray() as $row) {
            $raw = is_array($row) ? ($row['id'] ?? null) : null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /**
     * SQL-фрагмент критерия (whitelist по criteria_type — без инъекции; target — bound int).
     *
     * @return array{0:string,1:list<mixed>}|null
     */
    private function criteriaFragment(string $type, int $target): ?array
    {
        switch ($type) {
            case 'char_level':
                return ['c.level >= ?', [$target]];

            case 'gold_total':
                return ['c.gold >= ?', [$target]];

            case 'explored_cells':
                return ['(SELECT COUNT(*) FROM explored_cells ec WHERE ec.character_id = c.id) >= ?', [$target]];

            case 'craft_total':
                return ['(SELECT COALESCE(SUM(cil.quantity), 0) FROM crafted_items_log cil WHERE cil.character_id = c.id) >= ?', [$target]];

            case 'quests_completed':
                return ['(SELECT COUNT(*) FROM quest_steps qs WHERE qs.character_id = c.id AND qs.is_completed = 1) >= ?', [$target]];

            case 'has_base':
                return ['EXISTS (SELECT 1 FROM claimed_cells cc WHERE cc.character_id = c.id AND cc.status = ?)', ['active']];

            case 'has_faction':
                return ['EXISTS (SELECT 1 FROM character_factions cf WHERE cf.character_id = c.id AND cf.joined_at IS NOT NULL AND cf.faction_id <> ?)', [5]];

            default:
                return null;
        }
    }
}
