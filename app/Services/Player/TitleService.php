<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;
use Throwable;

/**
 * E11 (ADR-112) — система титулов: видимый статус-тег за прогресс/достижения.
 *
 * Зеркало {@see AchievementService} (cron-poll state-driven award): титул выдаётся по проверке
 * persistent state — `source_type='level'` (characters.level ≥ source_ref) или
 * `source_type='achievement'` (есть достижение с key=source_ref). Один активный титул
 * (`characters.active_title_id`); первый выданный авто-экипируется.
 *
 * Killswitch `titles.enabled` (default false → dormant). Класс НЕ final, методы overridable.
 */
class TitleService
{
    public const TABLE          = 'titles';
    public const CHAR_TABLE     = 'character_titles';

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch всей системы. default false (dormant на ship). */
    public function enabled(): bool
    {
        $v = $this->settings->get('titles.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Сколько выдач за один тик cron (throttle анти-шторм). */
    public function maxAwardsPerTick(): int
    {
        $v = $this->settings->get('titles.max_awards_per_tick', 25);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 25;
    }

    /**
     * Включённые титулы по возрастанию sort_order.
     *
     * @return list<array<int|string,mixed>>
     */
    public function definitions(): array
    {
        $db   = Database::connect();
        $rows = $db->table(self::TABLE)->where('enabled', 1)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get();
        if ($rows === false) {
            return [];
        }
        $out = [];
        foreach ($rows->getResultArray() as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * ID титулов, уже выданных персонажу.
     *
     * @return list<int>
     */
    public function unlockedTitleIds(int $characterId): array
    {
        $db   = Database::connect();
        $rows = $db->table(self::CHAR_TABLE)->select('title_id')->where('character_id', $characterId)->get();
        if ($rows === false) {
            return [];
        }
        $ids = [];
        foreach ($rows->getResultArray() as $row) {
            $raw = $row['title_id'] ?? null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /**
     * WB11 (ADR-137 «Узлы») — выдать титул «Убийца [босс]» по npc_name_en сломленного узла.
     *
     * Идемпотентно (через {@see award}). Гейтится общим killswitch `titles.enabled` (без него — нет
     * выдачи: титул был бы невидим). Title-строка ищется по source_type='boss_kill' + source_ref=npc_name_en
     * (cron такой source_type пропускает → выдаёт только этот kill-путь). true — если выдан впервые.
     */
    public function awardByBossKill(int $characterId, string $npcNameEn): bool
    {
        if (! $this->enabled() || $characterId <= 0 || $npcNameEn === '') {
            return false;
        }
        $res = Database::connect()->table(self::TABLE)
            ->select('id')
            ->where('source_type', 'boss_kill')
            ->where('source_ref', $npcNameEn)
            ->where('enabled', 1)
            ->get();
        $row     = $res === false ? null : $res->getRowArray();
        $titleId = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        if ($titleId <= 0) {
            return false;
        }

        return $this->award($characterId, $titleId);
    }

    /**
     * Идемпотентно выдать титул. true — если выдан впервые. Первый титул персонажа авто-экипируется.
     */
    public function award(int $characterId, int $titleId): bool
    {
        if ($characterId <= 0 || $titleId <= 0) {
            return false;
        }
        $db = Database::connect();

        $exists = $db->table(self::CHAR_TABLE)->where('character_id', $characterId)->where('title_id', $titleId)->countAllResults();
        if ($exists > 0) {
            return false;
        }

        try {
            $db->table(self::CHAR_TABLE)->insert([
                'character_id' => $characterId,
                'title_id'     => $titleId,
                'unlocked_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            return false; // гонка: UNIQUE поймал
        }

        // Авто-экип первого титула (active_title_id пуст).
        if ($this->activeTitleId($characterId) === null) {
            $this->equip($characterId, $titleId);
        }
        return true;
    }

    /**
     * ID персонажей, выполнивших условие титула и ещё НЕ получивших его (set-based, cron-cap).
     *
     * @param array<int|string,mixed> $title строка titles
     * @return list<int>
     */
    public function qualifyingCharacterIds(array $title, int $limit = 0): array
    {
        $titleId = is_numeric($title['id'] ?? null) ? (int) $title['id'] : 0;
        $type    = is_string($title['source_type'] ?? null) ? $title['source_type'] : '';
        $refRaw  = $title['source_ref'] ?? '';
        $ref     = is_string($refRaw) ? $refRaw : (is_scalar($refRaw) ? (string) $refRaw : '');
        if ($titleId <= 0) {
            return [];
        }

        if ($type === 'level') {
            $lvl      = is_numeric($ref) ? (int) $ref : 0;
            $fragment = 'c.level >= ?';
            $binds    = [$lvl];
        } elseif ($type === 'achievement') {
            $fragment = 'EXISTS (SELECT 1 FROM character_achievements ca2 JOIN achievements a ON a.id = ca2.achievement_id '
                . 'WHERE ca2.character_id = c.id AND a.achievement_key = ?)';
            $binds    = [$ref];
        } else {
            return [];
        }

        $sql = 'SELECT c.id FROM characters c '
            . 'LEFT JOIN ' . self::CHAR_TABLE . ' ct ON ct.character_id = c.id AND ct.title_id = ? '
            . 'WHERE ct.id IS NULL AND ' . $fragment;
        $bindings = array_merge([$titleId], $binds);
        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $bindings[] = $limit;
        }

        $db    = Database::connect();
        $query = $db->query($sql, $bindings);
        if (! is_object($query) || ! method_exists($query, 'getResultArray')) {
            return [];
        }
        $ids = [];
        foreach ($query->getResultArray() as $row) {
            $raw = $row['id'] ?? null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /** Экипировать титул, только если персонаж им владеет. true — успех. */
    public function setActive(int $characterId, int $titleId): bool
    {
        if (! in_array($titleId, $this->unlockedTitleIds($characterId), true)) {
            return false;
        }
        $this->equip($characterId, $titleId);
        return true;
    }

    /** Прямая запись active_title_id (билдер — минуя allowedFields-фильтр Model). Overridable. */
    protected function equip(int $characterId, int $titleId): void
    {
        Database::connect()->table('characters')->where('id', $characterId)->update(['active_title_id' => $titleId]);
    }

    /** ID экипированного титула (или null). */
    public function activeTitleId(int $characterId): ?int
    {
        $r = Database::connect()->table('characters')->select('active_title_id')->where('id', $characterId)->get();
        $row = $r === false ? null : $r->getRowArray();
        $raw = is_array($row) ? ($row['active_title_id'] ?? null) : null;
        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * Экипированный титул персонажа (строка titles) или null.
     *
     * @return array<int|string,mixed>|null
     */
    public function activeTitle(int $characterId): ?array
    {
        $id = $this->activeTitleId($characterId);
        if ($id === null) {
            return null;
        }
        $r = Database::connect()->table(self::TABLE)->where('id', $id)->where('enabled', 1)->get();
        $row = $r === false ? null : $r->getRowArray();
        return is_array($row) ? $row : null;
    }

    /** Отформатированный тег активного титула для карточки Перса (или null). */
    public function activeTitleLabel(int $characterId): ?string
    {
        $t = $this->activeTitle($characterId);
        if ($t === null) {
            return null;
        }
        $icon = is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🎖';
        $name = is_string($t['name'] ?? null) ? $t['name'] : '';
        return trim("{$icon} {$name}");
    }

    /**
     * Карта title_id → % персонажей, открывших титул (редкость, паттерн ADR-100).
     *
     * @return array<int,float>
     */
    public function rarityPercentMap(): array
    {
        $db    = Database::connect();
        $total = 0;
        $totQ  = $db->table('characters')->countAllResults();
        $total = is_int($totQ) ? $totQ : 0;

        $counts = [];
        $cntRows = $db->query('SELECT title_id, COUNT(*) AS c FROM ' . self::CHAR_TABLE . ' GROUP BY title_id');
        if (is_object($cntRows) && method_exists($cntRows, 'getResultArray')) {
            foreach ($cntRows->getResultArray() as $r) {
                if (is_numeric($r['title_id'] ?? null)) {
                    $counts[(int) $r['title_id']] = is_numeric($r['c'] ?? null) ? (int) $r['c'] : 0;
                }
            }
        }

        $map = [];
        foreach ($this->definitions() as $t) {
            $id = is_numeric($t['id'] ?? null) ? (int) $t['id'] : 0;
            if ($id > 0) {
                $map[$id] = $total > 0 ? round(100.0 * ($counts[$id] ?? 0) / $total, 1) : 0.0;
            }
        }
        return $map;
    }
}
