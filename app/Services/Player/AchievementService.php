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
     * Текущее значение прогресса персонажа по criteria_type (для UI «X/Y» на locked-ачивках).
     * has_base/has_faction → 0/1. Неизвестный тип → 0.
     */
    public function currentValue(int $characterId, string $criteriaType): int
    {
        if ($characterId <= 0) {
            return 0;
        }
        $db = \Config\Database::connect();

        $sql = match ($criteriaType) {
            'char_level'       => 'SELECT level AS v FROM characters WHERE id = ?',
            'gold_total'       => 'SELECT gold AS v FROM characters WHERE id = ?',
            'explored_cells'   => 'SELECT COUNT(*) AS v FROM explored_cells WHERE character_id = ?',
            'craft_total'      => 'SELECT COALESCE(SUM(quantity), 0) AS v FROM crafted_items_log WHERE character_id = ?',
            'quests_completed' => 'SELECT COUNT(*) AS v FROM quest_steps WHERE character_id = ? AND is_completed = 1',
            'has_base'         => 'SELECT EXISTS(SELECT 1 FROM claimed_cells WHERE character_id = ? AND status = \'active\') AS v',
            'has_faction'      => 'SELECT EXISTS(SELECT 1 FROM character_factions WHERE character_id = ? AND joined_at IS NOT NULL AND faction_id <> 5) AS v',
            // Контент-пасс «оцифровка механик» (ADR-066):
            'survival_days'         => 'SELECT DATEDIFF(NOW(), COALESCE(last_respawn_at, created_at)) AS v FROM characters WHERE id = ?',
            'buildings_count'       => 'SELECT COUNT(*) AS v FROM character_buildings WHERE character_id = ?',
            'buildings_max_level'   => 'SELECT COALESCE(MAX(level), 0) AS v FROM character_buildings WHERE character_id = ?',
            'distinct_biomes'       => 'SELECT COUNT(DISTINCT biome_id) AS v FROM explored_cells WHERE character_id = ?',
            'distinct_items_crafted' => 'SELECT COUNT(DISTINCT crafted_item_id) AS v FROM crafted_items_log WHERE character_id = ?',
            default            => null,
        };
        if ($sql === null) {
            return 0;
        }

        $query = $db->query($sql, [$characterId]);
        if (! is_object($query) || ! method_exists($query, 'getRowArray')) {
            return 0;
        }
        $row = $query->getRowArray();
        $raw = is_array($row) ? ($row['v'] ?? 0) : 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Публичная статистика достижений для веб-страницы (Asana, ADR-066-web): каждое enabled
     * достижение + сколько персонажей его разблокировали + глобальный процент (% от всех
     * персонажей). Процент, не число — чтобы не демотивировать (требование владельца).
     *
     * @return array{total:int, achievements:list<array<int|string,mixed>>}
     */
    public function statsWithPercent(): array
    {
        $db    = \Config\Database::connect();
        $total = 0;
        $totQ  = $db->query('SELECT COUNT(*) AS v FROM characters');
        if (is_object($totQ) && method_exists($totQ, 'getRowArray')) {
            $totRow = $totQ->getRowArray();
            $total  = is_array($totRow) && is_numeric($totRow['v'] ?? null) ? (int) $totRow['v'] : 0;
        }

        // Один запрос: разблокировки по достижению.
        $counts = [];
        $cntRows = $db->query('SELECT achievement_id, COUNT(*) AS c FROM character_achievements GROUP BY achievement_id');
        if (is_object($cntRows) && method_exists($cntRows, 'getResultArray')) {
            foreach ($cntRows->getResultArray() as $r) {
                if (is_array($r) && is_numeric($r['achievement_id'] ?? null)) {
                    $counts[(int) $r['achievement_id']] = is_numeric($r['c'] ?? null) ? (int) $r['c'] : 0;
                }
            }
        }

        $out = [];
        foreach ($this->definitions() as $a) {
            $id  = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
            $cnt = $counts[$id] ?? 0;
            $a['unlocked_count'] = $cnt;
            $a['percent']        = $total > 0 ? round(100.0 * $cnt / $total, 1) : 0.0;
            $out[] = $a;
        }

        return ['total' => $total, 'achievements' => $out];
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

            // Контент-пасс «оцифровка механик» (ADR-066) — все монотонны (растут или булевы):
            case 'survival_days':
                // «Время бессмертия»: дни с последнего возрождения (или создания, если не умирал).
                return ['DATEDIFF(NOW(), COALESCE(c.last_respawn_at, c.created_at)) >= ?', [$target]];

            case 'buildings_count':
                return ['(SELECT COUNT(*) FROM character_buildings cb WHERE cb.character_id = c.id) >= ?', [$target]];

            case 'buildings_max_level':
                return ['(SELECT COALESCE(MAX(cb.level), 0) FROM character_buildings cb WHERE cb.character_id = c.id) >= ?', [$target]];

            case 'distinct_biomes':
                return ['(SELECT COUNT(DISTINCT ec.biome_id) FROM explored_cells ec WHERE ec.character_id = c.id) >= ?', [$target]];

            case 'distinct_items_crafted':
                return ['(SELECT COUNT(DISTINCT cil.crafted_item_id) FROM crafted_items_log cil WHERE cil.character_id = c.id) >= ?', [$target]];

            default:
                return null;
        }
    }
}
