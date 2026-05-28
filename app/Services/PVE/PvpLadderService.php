<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharacterFactionModel;
use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * W18 (ADR-072) — PvP-ладдер: post-combat scoring дуэлей (W17) + летальных PvP-атак.
 *
 * RNG-fence-safe (ADR-070): scoring вызывается ПОСЛЕ боя (simulateFight уже посчитан) →
 * 0 нового mt_rand, движок не трогается. Одна строка на персонажа: cumulative `points` +
 * `week_*` (сбрасывается weekly-cron'ом). Инкременты атомарны (field = field + delta, без escape).
 * Все записи под killswitch `pvp.ladder.enabled` → при dormant no-op (таблица пуста).
 */
final class PvpLadderService
{
    private const TABLE = 'pvp_ladder';

    private GameSettingsService $settings;
    private CharacterFactionModel $factions;

    public function __construct(?GameSettingsService $settings = null, ?CharacterFactionModel $factions = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
        $this->factions = $factions ?? new CharacterFactionModel();
    }

    /** Killswitch ладдера. Default OFF = dormant. */
    public function enabled(): bool
    {
        return $this->boolSetting('pvp.ladder.enabled', false);
    }

    public function broadcastEnabled(): bool
    {
        return $this->boolSetting('pvp.ladder.broadcast_enabled', false);
    }

    public function pointsDuelWin(): int
    {
        return $this->intSetting('pvp.ladder.points_duel_win', 3);
    }

    public function pointsDuelDraw(): int
    {
        return $this->intSetting('pvp.ladder.points_duel_draw', 1);
    }

    public function pointsPvpWin(): int
    {
        return $this->intSetting('pvp.ladder.points_pvp_win', 5);
    }

    public function broadcastTopN(): int
    {
        return $this->intSetting('pvp.ladder.broadcast_top_n', 5);
    }

    public function broadcastDay(): int
    {
        return $this->intSetting('pvp.ladder.broadcast_day', 1);
    }

    public function broadcastHour(): int
    {
        return $this->intSetting('pvp.ladder.broadcast_hour', 12);
    }

    /**
     * Записать исход дуэли (W17). Killswitch-gated: при dormant — no-op.
     * Ничья → обоим duel_draws + points_duel_draw. Иначе winner +duel_wins/points, loser +duel_losses.
     */
    public function recordDuel(int $winnerId, ?int $loserId, bool $isDraw): void
    {
        if (! $this->enabled()) {
            return;
        }
        if ($isDraw) {
            $draw = $this->pointsDuelDraw();
            if ($winnerId > 0) {
                $this->applyDelta($winnerId, ['duel_draws' => 1, 'points' => $draw, 'week_points' => $draw]);
            }
            if ($loserId !== null && $loserId > 0) {
                $this->applyDelta($loserId, ['duel_draws' => 1, 'points' => $draw, 'week_points' => $draw]);
            }
            return;
        }
        if ($winnerId > 0) {
            $win = $this->pointsDuelWin();
            $this->applyDelta($winnerId, ['duel_wins' => 1, 'points' => $win, 'week_points' => $win, 'week_wins' => 1]);
        }
        if ($loserId !== null && $loserId > 0) {
            $this->applyDelta($loserId, ['duel_losses' => 1]);
        }
    }

    /**
     * Записать исход летальной PvP-атаки. Killswitch-gated: при dormant — no-op.
     */
    public function recordPvpAttack(int $winnerId, int $loserId): void
    {
        if (! $this->enabled()) {
            return;
        }
        if ($winnerId > 0) {
            $win = $this->pointsPvpWin();
            $this->applyDelta($winnerId, ['pvp_wins' => 1, 'points' => $win, 'week_points' => $win, 'week_wins' => 1]);
        }
        if ($loserId > 0) {
            $this->applyDelta($loserId, ['pvp_losses' => 1]);
        }
    }

    /**
     * Топ-N по all-time points (global). Имя джойнится из characters.
     *
     * @return list<array<string,mixed>>
     */
    public function topGlobal(int $limit): array
    {
        return $this->topQuery($limit, null);
    }

    /**
     * Топ-N по all-time points внутри фракции.
     *
     * @return list<array<string,mixed>>
     */
    public function topByFaction(int $factionId, int $limit): array
    {
        return $this->topQuery($limit, $factionId);
    }

    /**
     * Топ-N по week_points (для weekly-broadcast).
     *
     * @return list<array<string,mixed>>
     */
    public function weeklyTop(int $limit): array
    {
        $limit = $limit > 0 ? $limit : 5;
        $q = $this->table()
            ->select('pvp_ladder.character_id, pvp_ladder.faction_id, pvp_ladder.week_points, pvp_ladder.week_wins, characters.name')
            ->join('characters', 'characters.id = pvp_ladder.character_id', 'left')
            ->where('pvp_ladder.week_points >', 0)
            ->orderBy('pvp_ladder.week_points', 'DESC')
            ->orderBy('pvp_ladder.week_wins', 'DESC')
            ->limit($limit)
            ->get();
        return $this->normalizeRows($q === false ? [] : $q->getResultArray());
    }

    /** Сбросить недельные счётчики (старт нового сезона). */
    public function resetWeek(): void
    {
        $this->table()->where('week_points >', 0)->update(['week_points' => 0, 'week_wins' => 0]);
    }

    /** Позиция персонажа в global-ладдере (1-based), 0 если не в рейтинге. */
    public function rankOf(int $characterId): int
    {
        if ($characterId <= 0) {
            return 0;
        }
        $row = $this->table()->select('points')->where('character_id', $characterId)->get();
        $arr = $row === false ? null : $row->getRowArray();
        if (! is_array($arr) || ! is_numeric($arr['points'] ?? null)) {
            return 0;
        }
        $myPoints = (int) $arr['points'];
        if ($myPoints <= 0) {
            return 0;
        }
        $cntRaw = $this->table()->where('points >', $myPoints)->countAllResults();
        $cnt    = is_numeric($cntRaw) ? (int) $cntRaw : 0;
        return $cnt + 1;
    }

    /**
     * @return array<string,mixed>|null Строка ладдера персонажа (или null).
     */
    public function rowOf(int $characterId): ?array
    {
        if ($characterId <= 0) {
            return null;
        }
        $row = $this->table()->where('character_id', $characterId)->get();
        $arr = $row === false ? null : $row->getRowArray();
        return is_array($arr) ? $arr : null;
    }

    // ---- internals ----

    /**
     * @return list<array<string,mixed>>
     */
    private function topQuery(int $limit, ?int $factionId): array
    {
        $limit = $limit > 0 ? $limit : 5;
        $builder = $this->table()
            ->select('pvp_ladder.character_id, pvp_ladder.faction_id, pvp_ladder.points, pvp_ladder.duel_wins, pvp_ladder.pvp_wins, characters.name')
            ->join('characters', 'characters.id = pvp_ladder.character_id', 'left')
            ->where('pvp_ladder.points >', 0);
        if ($factionId !== null) {
            $builder->where('pvp_ladder.faction_id', $factionId);
        }
        $q = $builder->orderBy('pvp_ladder.points', 'DESC')
            ->orderBy('pvp_ladder.duel_wins', 'DESC')
            ->limit($limit)
            ->get();
        return $this->normalizeRows($q === false ? [] : $q->getResultArray());
    }

    /**
     * getResultArray() возвращает нетипизированный array → нормализуем в list<array<string,mixed>>
     * (ключи строками) без @var/assert/cast-силансинга (strict L9).
     *
     * @param array<int|string,mixed> $res
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $res): array
    {
        $out = [];
        foreach ($res as $r) {
            if (! is_array($r)) {
                continue;
            }
            $clean = [];
            foreach ($r as $k => $v) {
                $clean[(string) $k] = $v;
            }
            $out[] = $clean;
        }
        return $out;
    }

    /**
     * Атомарный инкремент полей строки персонажа (создаёт строку при отсутствии).
     * field = field + delta (raw, без escape) — без read-modify-write гонки.
     *
     * @param array<string,int> $deltas
     */
    private function applyDelta(int $characterId, array $deltas): void
    {
        $factionId = $this->factions->getFactionId($characterId);

        // Гарантируем строку (idempotent: UNIQUE character_id).
        $exists = $this->table()->select('id')->where('character_id', $characterId)->get();
        $existsArr = $exists === false ? null : $exists->getRowArray();
        if (! is_array($existsArr)) {
            $this->table()->ignore(true)->insert([
                'character_id' => $characterId,
                'faction_id'   => $factionId,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $builder = $this->table()->where('character_id', $characterId);
        foreach ($deltas as $field => $delta) {
            $builder->set($field, "{$field} + " . (int) $delta, false);
        }
        $builder->set('faction_id', (string) $factionId);
        $builder->set('updated_at', date('Y-m-d H:i:s'));
        $builder->update();
    }

    private function table(): \CodeIgniter\Database\BaseBuilder
    {
        return Database::connect()->table(self::TABLE);
    }

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
}
