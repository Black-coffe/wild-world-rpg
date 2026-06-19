<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\TitleService;
use Config\Database;

/**
 * ADR-135 Ф3b — «Доска розыска» (bounty на доминатора).
 *
 * Игрок, держащий активную трофейную подать над другими («хозяин»/угнетатель), попадает
 * в публичный розыск. Третья сторона, сразившая его в полевом PvP, получает охотничий
 * трофей — ПРЕСТИЖ (счётчик клеймов + титул «Охотник за головами»), БЕЗ золота/ресурсов
 * (owner-pick: 0 эмиссии, 0 вектора отмывания master↔альт-охотник). Доминирование само
 * себя наказывает статусом и охотой, а не скучным налогом (цель ADR-135).
 *
 * Под killswitch tribute.enabled И tribute.bounty_enabled (оба default false → dormant).
 * Список «в розыске» — derived live из character_tributes (active master_id). Клеймы —
 * append-only журнал bounty_claims (PLAYER_DATA). Безопасная деградация: сбой БД/настроек
 * не должен ронять PvP-бой.
 */
final class BountyService
{
    private GameSettingsService $settings;
    private TitleService $titles;

    public function __construct(?GameSettingsService $settings = null, ?TitleService $titles = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
        $this->titles   = $titles ?? new TitleService();
    }

    // ── Killswitch + настройки (ADR-024) ─────────────────────────────────

    /** Bounty-слой активен, только если включены И подать, И сам bounty. */
    public function enabled(): bool
    {
        return $this->boolSetting('tribute.enabled', false)
            && $this->boolSetting('tribute.bounty_enabled', false);
    }

    public function claimCooldownHours(): int
    {
        return $this->intSetting('tribute.bounty_claim_cooldown_hours', 24);
    }

    public function titleThreshold(): int
    {
        return $this->intSetting('tribute.bounty_hunter_title_threshold', 3);
    }

    // ── Чистые решения (тестируются без БД) ──────────────────────────────

    /**
     * Блокирует ли кулдаун новый трофей: true → нельзя засчитать (прошлый клейм пары свежее окна).
     * null lastClaimTs (пара ещё не охочена) → не блокирует.
     */
    public static function withinCooldown(?int $lastClaimTs, int $nowTs, int $cooldownHours): bool
    {
        if ($lastClaimTs === null || $cooldownHours <= 0) {
            return false;
        }
        return ($nowTs - $lastClaimTs) < ($cooldownHours * 3600);
    }

    /** Заслужил ли охотник титул при текущем числе трофеев. threshold ≤ 0 → нет. */
    public static function qualifiesForTitle(int $claims, int $threshold): bool
    {
        return $threshold > 0 && $claims >= $threshold;
    }

    // ── Состояние розыска (derived из character_tributes) ────────────────

    /** Держит ли персонаж активную подать как хозяин (= в розыске). */
    public function isBountied(int $charId): bool
    {
        if ($charId <= 0) {
            return false;
        }
        try {
            $cnt = Database::connect()->table('character_tributes')
                ->where('master_id', $charId)
                ->where('status', 'active')
                ->countAllResults();
            return is_numeric($cnt) && (int) $cnt > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Уровень розыска = число активных данников хозяина (флейвор «сразил доминатора N-го уровня»). */
    public function wantedLevel(int $charId): int
    {
        if ($charId <= 0) {
            return 0;
        }
        try {
            $cnt = Database::connect()->table('character_tributes')
                ->where('master_id', $charId)
                ->where('status', 'active')
                ->countAllResults();
            return is_numeric($cnt) ? (int) $cnt : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Доска розыска: активные доминаторы по убыванию числа данников. Каждая запись —
     * {master_id, name, wanted}. Для UI (BountyBoardAction).
     *
     * @return list<array{master_id:int, name:string, wanted:int}>
     */
    public function wantedBoard(int $limit = 10): array
    {
        if ($limit <= 0) {
            $limit = 10;
        }
        try {
            $rows = Database::connect()->table('character_tributes ct')
                ->select('ct.master_id AS master_id, COUNT(*) AS wanted, c.name AS name')
                ->join('characters c', 'c.id = ct.master_id', 'left')
                ->where('ct.status', 'active')
                ->groupBy('ct.master_id')
                ->orderBy('wanted', 'DESC')
                ->orderBy('ct.master_id', 'ASC')
                ->get($limit);
            $out  = [];
            $list = $rows === false ? [] : $rows->getResultArray();
            foreach ($list as $r) {
                $mid = is_numeric($r['master_id'] ?? null) ? (int) $r['master_id'] : 0;
                if ($mid <= 0) {
                    continue;
                }
                $name = is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : "#{$mid}";
                $out[] = [
                    'master_id' => $mid,
                    'name'      => $name,
                    'wanted'    => is_numeric($r['wanted'] ?? null) ? (int) $r['wanted'] : 0,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Сколько трофеев у охотника всего. */
    public function claimsBy(int $hunterId): int
    {
        if ($hunterId <= 0) {
            return 0;
        }
        try {
            $cnt = Database::connect()->table('bounty_claims')
                ->where('hunter_id', $hunterId)
                ->countAllResults();
            return is_numeric($cnt) ? (int) $cnt : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ── Запись трофея (хук из AttackPlayerAction) ────────────────────────

    /**
     * Записать охотничий трофей: hunter сразил target. Гейты: killswitch, не сам себя,
     * target в розыске (держит активную подать), кулдаун пары. При достижении порога —
     * выдаёт титул (если titles.enabled). Безопасно: любой сбой → recorded=false.
     *
     * @return array{recorded:bool, reason:string, claims:int, wanted:int}
     */
    public function recordClaim(int $hunterId, int $targetId): array
    {
        $fail = static fn (string $r): array => ['recorded' => false, 'reason' => $r, 'claims' => 0, 'wanted' => 0];

        if (! $this->enabled()) {
            return $fail('disabled');
        }
        if ($hunterId <= 0 || $targetId <= 0 || $hunterId === $targetId) {
            return $fail('invalid');
        }
        try {
            if (! $this->isBountied($targetId)) {
                return $fail('not_bountied');
            }
            if (self::withinCooldown($this->lastClaimTs($hunterId, $targetId), time(), $this->claimCooldownHours())) {
                return $fail('cooldown');
            }

            $wanted = $this->wantedLevel($targetId);
            $now    = date('Y-m-d H:i:s');
            Database::connect()->table('bounty_claims')->insert([
                'hunter_id'    => $hunterId,
                'target_id'    => $targetId,
                'wanted_level' => $wanted,
                'claimed_at'   => $now,
                'created_at'   => $now,
            ]);

            $claims = $this->claimsBy($hunterId);
            $this->maybeAwardTitle($hunterId, $claims);

            return ['recorded' => true, 'reason' => 'ok', 'claims' => $claims, 'wanted' => $wanted];
        } catch (\Throwable $e) {
            log_message('error', 'BountyService::recordClaim failed: ' . $e->getMessage());
            return $fail('error');
        }
    }

    // ── internals ────────────────────────────────────────────────────────

    /** UNIX-таймстамп последнего клейма пары (hunter→target) или null. */
    private function lastClaimTs(int $hunterId, int $targetId): ?int
    {
        $r = Database::connect()->table('bounty_claims')
            ->select('claimed_at')
            ->where('hunter_id', $hunterId)
            ->where('target_id', $targetId)
            ->orderBy('id', 'DESC')
            ->get(1);
        $row = $r === false ? null : $r->getRowArray();
        $raw = is_array($row) ? ($row['claimed_at'] ?? null) : null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    /** Выдать титул «Охотник за головами» при достижении порога (если titles.enabled). Идемпотентно. */
    private function maybeAwardTitle(int $hunterId, int $claims): void
    {
        if (! self::qualifiesForTitle($claims, $this->titleThreshold())) {
            return;
        }
        try {
            if (! $this->titles->enabled()) {
                return;
            }
            $r = Database::connect()->table('titles')
                ->select('id')
                ->where('title_key', 'bounty_hunter')
                ->where('enabled', 1)
                ->get(1);
            $row = $r === false ? null : $r->getRowArray();
            $tid = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            if ($tid > 0) {
                $this->titles->award($hunterId, $tid);
            }
        } catch (\Throwable $e) {
            log_message('error', 'BountyService::maybeAwardTitle failed: ' . $e->getMessage());
        }
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
