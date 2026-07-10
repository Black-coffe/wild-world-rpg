<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * Топ игроков (2026-07-10) — общий рейтинг по уровню.
 *
 * **Почему появился:** аудит `player_action_log.status='unrouted'` (ADR-148) показал, что
 * ДВА разных игрока спрашивали бота про топ («топ», «тут есть топ игроков?») и получали
 * «Не понял команду». Общего рейтинга в игре не было: `PvpLadderService` — это рейтинг
 * ДУЭЛЕЙ (и открывался лишь из Арены), а экран «📊 Прогресс» словом «рейтинг» обещал
 * именно его. Спрос измерен, ответа не было.
 *
 * **Две вкладки — потому что этого требуют данные, а не симметрия.** На проде 611 чаров,
 * из них 529 на L1 (86.6%), и топ «по уровню за всё время» наполовину состоит из мертвецов:
 * ветераны L70–170, которые заблокировали бота. Для новичка это стена из призраков, а не
 * ориентир. Поэтому:
 *
 *  - `topActive()` — **вкладка по умолчанию**: только те, кто заходил за последние N дней.
 *    Живой, достижимый ориентир (на 2026-07-10 таких 84).
 *  - `topLegends()` — история за всё время, включая ушедших. Престиж, не цель.
 *
 * 🔴 **Анти-абьюз:** рейтинг — ЧИСТЫЙ ПРЕСТИЖ, никаких наград (зеркало `PvpLadderService`).
 * Награда за место в топе превратила бы его в мотор гринда и наказала бы новичков.
 *
 * 🔒 **Приватность** ([[feedback_web_tier_privacy_own_only]]): наружу идут только brag-поля —
 * имя и уровень. Ни золота, ни координат, ни здоровья. Прецедент — публичный веб-профиль
 * (E30, `/profile/<id>`), где уровень и титул уже открыты.
 */
class LeaderboardService // не final: LeaderboardScreen тестируется с моком, без БД (seam)
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch. Default OFF → экран не строится, кнопки нет. */
    public function enabled(): bool
    {
        return $this->boolSetting('social.leaderboard.enabled', false);
    }

    /** Сколько строк показывать. Clamp — защита от опечатки в админке. */
    public function size(): int
    {
        return max(3, min(25, $this->intSetting('social.leaderboard.size', 10)));
    }

    /** Окно «живости» для вкладки активных, в днях. */
    public function activeDays(): int
    {
        return max(1, min(90, $this->intSetting('social.leaderboard.active_days', 7)));
    }

    /** Зеркало PvpLadderService::boolSetting — админка может хранить '1'/'true'/'on'. */
    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    /**
     * Топ активных: заходили за `activeDays()` дней (по `telegram_users.last_seen`).
     *
     * @return list<array{rank:int,id:int,name:string,level:int}>
     */
    public function topActive(?int $limit = null): array
    {
        $builder = $this->baseQuery()
            ->where('tu.last_seen >=', $this->activeSince());

        return $this->fetchRows($builder, $limit ?? $this->size());
    }

    /**
     * Топ за всё время, включая ушедших.
     *
     * @return list<array{rank:int,id:int,name:string,level:int}>
     */
    public function topLegends(?int $limit = null): array
    {
        return $this->fetchRows($this->baseQuery(), $limit ?? $this->size());
    }

    /**
     * Позиция персонажа среди активных. 0 — если сам не активен (или не найден).
     */
    public function rankOfActive(int $characterId): int
    {
        return $this->rankOf($characterId, true);
    }

    /** Позиция персонажа за всё время. 0 — если не найден. */
    public function rankOfLegends(int $characterId): int
    {
        return $this->rankOf($characterId, false);
    }

    /** Сколько всего участников во вкладке (для «#7 из 84»). */
    public function totalActive(): int
    {
        $db = Database::connect();

        return (int) $db->table('characters c')
            ->join('telegram_users tu', 'tu.id = c.telegram_user_id', 'inner')
            ->where('tu.last_seen >=', $this->activeSince())
            ->countAllResults();
    }

    public function totalLegends(): int
    {
        return (int) Database::connect()->table('characters')->countAllResults();
    }

    // ---------------------------------------------------------------- internals

    private function activeSince(): string
    {
        // DB-часы, не PHP: tz-skew иначе валит окно ([[feedback_db_clock_seed_not_php_in_time_window_tests]]).
        $result = Database::connect()
            ->query('SELECT DATE_SUB(NOW(), INTERVAL ? DAY) AS since', [$this->activeDays()]);

        if (! $result instanceof \CodeIgniter\Database\ResultInterface) {
            return '1970-01-01 00:00:00';
        }

        $row = $result->getRowArray();
        $since = is_array($row) && is_scalar($row['since'] ?? null) ? (string) $row['since'] : '';

        return $since !== '' ? $since : '1970-01-01 00:00:00';
    }

    private function baseQuery(): \CodeIgniter\Database\BaseBuilder
    {
        return Database::connect()->table('characters c')
            ->select('c.id, c.name, c.level, c.experience')
            ->join('telegram_users tu', 'tu.id = c.telegram_user_id', 'left')
            ->orderBy('c.level', 'DESC')
            ->orderBy('c.experience', 'DESC')
            ->orderBy('c.id', 'ASC'); // детерминированный tie-break
    }

    /**
     * @return list<array{rank:int,id:int,name:string,level:int}>
     */
    private function fetchRows(\CodeIgniter\Database\BaseBuilder $builder, int $limit): array
    {
        $result = $builder->limit($limit)->get();
        if ($result === false) {
            return [];
        }

        $rows = $result->getResultArray();
        $out  = [];
        $rank = 0;
        foreach ($rows as $row) {
            $rank++;
            $rawName = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
            $out[] = [
                'rank'  => $rank,
                'id'    => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'name'  => \App\Services\Display\MarkdownSafe::name($rawName),
                'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Ранг = сколько игроков строго выше + 1. Порядок тот же, что в `baseQuery()`
     * (уровень → опыт → id), иначе «#7» не совпало бы со строкой №7 в таблице.
     */
    private function rankOf(int $characterId, bool $activeOnly): int
    {
        if ($characterId <= 0) {
            return 0;
        }

        $db     = Database::connect();
        $result = $db->table('characters')
            ->select('id, level, experience, telegram_user_id')
            ->where('id', $characterId)
            ->get();

        if ($result === false) {
            return 0;
        }

        $me = $result->getRowArray();
        if (! is_array($me)) {
            return 0;
        }

        $myLevel = is_numeric($me['level'] ?? null) ? (int) $me['level'] : 0;
        $myExp   = is_numeric($me['experience'] ?? null) ? (float) $me['experience'] : 0.0;

        if ($activeOnly && ! $this->isActive($characterId)) {
            return 0;
        }

        $builder = $db->table('characters c')
            ->join('telegram_users tu', 'tu.id = c.telegram_user_id', $activeOnly ? 'inner' : 'left');

        if ($activeOnly) {
            $builder->where('tu.last_seen >=', $this->activeSince());
        }

        // Строго выше: больший уровень ЛИБО тот же уровень и больше опыта ЛИБО всё равно и меньший id.
        $builder->groupStart()
            ->where('c.level >', $myLevel)
            ->groupEnd()
            ->orGroupStart()
            ->where('c.level', $myLevel)
            ->where('c.experience >', $myExp)
            ->groupEnd()
            ->orGroupStart()
            ->where('c.level', $myLevel)
            ->where('c.experience', $myExp)
            ->where('c.id <', $characterId)
            ->groupEnd();

        return (int) $builder->countAllResults() + 1;
    }

    private function isActive(int $characterId): bool
    {
        $db = Database::connect();

        return $db->table('characters c')
            ->join('telegram_users tu', 'tu.id = c.telegram_user_id', 'inner')
            ->where('c.id', $characterId)
            ->where('tu.last_seen >=', $this->activeSince())
            ->countAllResults() > 0;
    }
}
