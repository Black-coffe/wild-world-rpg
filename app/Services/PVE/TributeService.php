<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharacterModel;
use App\Models\CharacterTributeModel;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\I18n\Time;
use Config\Database;

/**
 * ADR-135 «Трофейная подать» — Фаза 1: трекинг PvP-доминирования + создание подати.
 *
 * Когда победитель (master) набирает `tribute.dominance_wins_required` побед над одной
 * жертвой (vassal) за `tribute.dominance_window_days` дней И НЕ проигрывает ей в том же
 * окне (одностороннее превосходство) — накладывается временная подать (zero-sum переток
 * `tribute.rate` с raw-добычи жертвы; сбор — Фаза 2). Учитываются только летальные
 * полевые PvP-бои (`battle_logs.battle_type='PVP'`), НЕ дуэли/арена (консенсуальные).
 *
 * RNG-fence-safe (как PvpLadderService): вызывается ПОСЛЕ боя (simulateFight посчитан) →
 * 0 нового mt_rand. Всё под killswitch `tribute.enabled` → при dormant — no-op.
 *
 * Решение «накладывать ли подать» вынесено в ЧИСТУЮ `decideDomination()` (тестируется без
 * БД — паттерн DeathPenaltyCalculator); оркестрация `evaluateDomination()` собирает контекст
 * из БД/настроек и при eligible создаёт строку. Защиты слабых/новичков и анти-сталкинг —
 * через GameSettings (ADR-024), безопасная деградация к dormant при недоступной game_settings.
 */
final class TributeService
{
    private GameSettingsService $settings;
    private CharacterModel $characters;
    private CharacterTributeModel $tributes;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?CharacterModel $characters = null,
        ?CharacterTributeModel $tributes = null
    ) {
        $this->settings   = $settings ?? new GameSettingsService();
        $this->characters = $characters ?? new CharacterModel();
        $this->tributes   = $tributes ?? new CharacterTributeModel();
    }

    // ── Killswitch + настройки (ADR-024) ─────────────────────────────────

    /** Главный killswitch механики. Default OFF = dormant. */
    public function enabled(): bool
    {
        return $this->boolSetting('tribute.enabled', false);
    }

    public function rate(): float
    {
        $v = $this->settings->get('tribute.rate', 0.10);
        return is_numeric($v) ? (float) $v : 0.10;
    }

    public function winsRequired(): int
    {
        return $this->intSetting('tribute.dominance_wins_required', 5);
    }

    public function windowDays(): int
    {
        return $this->intSetting('tribute.dominance_window_days', 30);
    }

    public function durationDays(): int
    {
        return $this->intSetting('tribute.duration_days', 14);
    }

    public function recaptureCooldownDays(): int
    {
        return $this->intSetting('tribute.recapture_cooldown_days', 7);
    }

    public function minLevel(): int
    {
        return $this->intSetting('tribute.min_level', 20);
    }

    public function maxLevelGap(): int
    {
        return $this->intSetting('tribute.max_level_gap', 10);
    }

    public function minAccountAgeDays(): int
    {
        return $this->intSetting('tribute.min_account_age_days', 21);
    }

    public function respawnImmunityHours(): int
    {
        return $this->intSetting('tribute.respawn_immunity_hours', 24);
    }

    // ── Чистое решение (тестируется без БД) ──────────────────────────────

    /**
     * Чистая функция: накладывать ли подать, исходя из собранного контекста.
     * Порядок — сначала защиты (гейты), потом порог доминирования; первая сработавшая
     * причина и возвращается (детерминированно). Без БД/времени/настроек → unit-тестируема.
     *
     * Ключи $ctx: winsByMaster, winsByVassal, winsRequired, masterLevel, vassalLevel,
     * minLevel, maxLevelGap, vassalAccountAgeDays, minAccountAgeDays, vassalRespawnHoursAgo
     * (float|int|null), respawnImmunityHours, hasActiveTribute (bool), daysSinceLastLift
     * (float|int|null), recaptureCooldownDays. Все читаются с дефолтами/кастами → безопасно.
     *
     * @param array<string,mixed> $ctx
     * @return array{eligible:bool, reason:string}
     */
    public static function decideDomination(array $ctx): array
    {
        $winsByMaster   = self::ctxInt($ctx, 'winsByMaster', 0);
        $winsByVassal   = self::ctxInt($ctx, 'winsByVassal', 0);
        $winsRequired   = self::ctxInt($ctx, 'winsRequired', 5);
        $masterLevel    = self::ctxInt($ctx, 'masterLevel', 0);
        $vassalLevel    = self::ctxInt($ctx, 'vassalLevel', 0);
        $minLevel       = self::ctxInt($ctx, 'minLevel', 20);
        $maxLevelGap    = self::ctxInt($ctx, 'maxLevelGap', 10);
        $accountAge     = self::ctxInt($ctx, 'vassalAccountAgeDays', 0);
        $minAccountAge  = self::ctxInt($ctx, 'minAccountAgeDays', 21);
        $respawnImmH    = self::ctxInt($ctx, 'respawnImmunityHours', 24);
        $recaptureCd    = self::ctxInt($ctx, 'recaptureCooldownDays', 7);
        $hasActive      = filter_var($ctx['hasActiveTribute'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $respawnHoursAgo = $ctx['vassalRespawnHoursAgo'] ?? null;
        $daysSinceLift   = $ctx['daysSinceLastLift'] ?? null;

        // Защита слабых/новичков.
        if ($vassalLevel < $minLevel) {
            return ['eligible' => false, 'reason' => 'vassal_below_min_level'];
        }
        if (($masterLevel - $vassalLevel) > $maxLevelGap) {
            return ['eligible' => false, 'reason' => 'level_gap_too_large'];
        }
        if ($accountAge < $minAccountAge) {
            return ['eligible' => false, 'reason' => 'vassal_account_too_new'];
        }
        // Иммунитет после респавна (закрывает серийный respawn-farming).
        if (is_numeric($respawnHoursAgo) && (float) $respawnHoursAgo < $respawnImmH) {
            return ['eligible' => false, 'reason' => 'respawn_immunity'];
        }
        // Анти-сталкинг: уже под данью у этого хозяина / кулдаун пере-захвата.
        if ($hasActive) {
            return ['eligible' => false, 'reason' => 'already_active'];
        }
        if (is_numeric($daysSinceLift) && (float) $daysSinceLift < $recaptureCd) {
            return ['eligible' => false, 'reason' => 'recapture_cooldown'];
        }
        // Одностороннее превосходство: жертва не должна побеждать хозяина в окне.
        if ($winsByVassal > 0) {
            return ['eligible' => false, 'reason' => 'not_one_sided'];
        }
        // Порог доминирования.
        if ($winsByMaster < $winsRequired) {
            return ['eligible' => false, 'reason' => 'insufficient_dominance'];
        }

        return ['eligible' => true, 'reason' => 'eligible'];
    }

    // ── Оркестрация (БД) ─────────────────────────────────────────────────

    /**
     * Вызывается ПОСЛЕ записи летальной PvP-победы (master победил vassal). При выполнении
     * всех условий создаёт активную подать. Killswitch-gated: при dormant — no-op (null).
     *
     * @return int|null id созданной подати или null (не создана/dormant)
     */
    public function evaluateDomination(int $masterId, int $vassalId): ?int
    {
        if (! $this->enabled()) {
            return null;
        }
        if ($masterId <= 0 || $vassalId <= 0 || $masterId === $vassalId) {
            return null;
        }

        try {
            $master = $this->characters->find($masterId);
            $vassal = $this->characters->find($vassalId);
            if ($master === null || $vassal === null) {
                return null;
            }

            $since = $this->windowStart();
            $ctx   = [
                'winsByMaster'          => $this->countDirectionalWins($masterId, $vassalId, $since),
                'winsByVassal'          => $this->countDirectionalWins($vassalId, $masterId, $since),
                'winsRequired'          => $this->winsRequired(),
                'masterLevel'           => $this->numField($master, 'level'),
                'vassalLevel'           => $this->numField($vassal, 'level'),
                'minLevel'              => $this->minLevel(),
                'maxLevelGap'           => $this->maxLevelGap(),
                'vassalAccountAgeDays'  => $this->accountAgeDays($vassal),
                'minAccountAgeDays'     => $this->minAccountAgeDays(),
                'vassalRespawnHoursAgo' => $this->respawnHoursAgo($vassal),
                'respawnImmunityHours'  => $this->respawnImmunityHours(),
                'hasActiveTribute'      => $this->hasActiveTribute($masterId, $vassalId),
                'daysSinceLastLift'     => $this->daysSinceLastLift($masterId, $vassalId),
                'recaptureCooldownDays' => $this->recaptureCooldownDays(),
            ];

            $decision = self::decideDomination($ctx);
            if (! $decision['eligible']) {
                return null;
            }

            return $this->createTribute($masterId, $vassalId);
        } catch (\Throwable $e) {
            // Безопасная деградация: сбой БД не должен ронять PvP-бой.
            log_message('error', 'TributeService::evaluateDomination failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Прогресс доминирования для UI («доминирование N/5»). Не мутирует данные.
     *
     * @return array{enabled:bool, wins:int, required:int, oneSided:bool, eligible:bool, reason:string}
     */
    public function dominanceProgress(int $masterId, int $vassalId): array
    {
        $required = $this->winsRequired();
        if (! $this->enabled() || $masterId <= 0 || $vassalId <= 0 || $masterId === $vassalId) {
            return ['enabled' => false, 'wins' => 0, 'required' => $required, 'oneSided' => true, 'eligible' => false, 'reason' => 'disabled'];
        }

        try {
            $master = $this->characters->find($masterId);
            $vassal = $this->characters->find($vassalId);
            if ($master === null || $vassal === null) {
                return ['enabled' => true, 'wins' => 0, 'required' => $required, 'oneSided' => true, 'eligible' => false, 'reason' => 'not_found'];
            }

            $since        = $this->windowStart();
            $winsByMaster = $this->countDirectionalWins($masterId, $vassalId, $since);
            $winsByVassal = $this->countDirectionalWins($vassalId, $masterId, $since);
            $ctx          = [
                'winsByMaster'          => $winsByMaster,
                'winsByVassal'          => $winsByVassal,
                'winsRequired'          => $required,
                'masterLevel'           => $this->numField($master, 'level'),
                'vassalLevel'           => $this->numField($vassal, 'level'),
                'minLevel'              => $this->minLevel(),
                'maxLevelGap'           => $this->maxLevelGap(),
                'vassalAccountAgeDays'  => $this->accountAgeDays($vassal),
                'minAccountAgeDays'     => $this->minAccountAgeDays(),
                'vassalRespawnHoursAgo' => $this->respawnHoursAgo($vassal),
                'respawnImmunityHours'  => $this->respawnImmunityHours(),
                'hasActiveTribute'      => $this->hasActiveTribute($masterId, $vassalId),
                'daysSinceLastLift'     => $this->daysSinceLastLift($masterId, $vassalId),
                'recaptureCooldownDays' => $this->recaptureCooldownDays(),
            ];
            $decision = self::decideDomination($ctx);

            return [
                'enabled'  => true,
                'wins'     => $winsByMaster,
                'required' => $required,
                'oneSided' => $winsByVassal === 0,
                'eligible' => $decision['eligible'],
                'reason'   => $decision['reason'],
            ];
        } catch (\Throwable $e) {
            return ['enabled' => true, 'wins' => 0, 'required' => $required, 'oneSided' => true, 'eligible' => false, 'reason' => 'error'];
        }
    }

    /**
     * Активная подать, под которой сейчас находится жертва (для сбора в Фазе 2).
     *
     * @return array<string,mixed>|null
     */
    public function getActiveTribute(int $vassalId): ?array
    {
        if ($vassalId <= 0) {
            return null;
        }
        try {
            $row = $this->tributes
                ->where('vassal_id', $vassalId)
                ->where('status', 'active')
                ->orderBy('id', 'DESC')
                ->first();
            return is_array($row) ? $this->stringKeys($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * Кол-во летальных полевых PvP-побед winnerId над otherId с момента $since.
     * Считаем только battle_type='PVP' (поле), НЕ дуэли/арена.
     */
    private function countDirectionalWins(int $winnerId, int $otherId, string $since): int
    {
        $builder = Database::connect()->table('battle_logs');
        $builder->where('battle_type', 'PVP')
            ->where('winner_id', $winnerId)
            ->where('created_at >=', $since)
            ->groupStart()
                ->groupStart()
                    ->where('player1_id', $winnerId)
                    ->where('player2_id', $otherId)
                ->groupEnd()
                ->orGroupStart()
                    ->where('player1_id', $otherId)
                    ->where('player2_id', $winnerId)
                ->groupEnd()
            ->groupEnd();
        $cnt = $builder->countAllResults();
        return is_numeric($cnt) ? (int) $cnt : 0;
    }

    private function hasActiveTribute(int $masterId, int $vassalId): bool
    {
        $cnt = $this->tributes
            ->where('master_id', $masterId)
            ->where('vassal_id', $vassalId)
            ->where('status', 'active')
            ->countAllResults();
        return is_numeric($cnt) && (int) $cnt > 0;
    }

    /**
     * Дней с момента последнего СНЯТИЯ подати на эту пару (любой не-active статус).
     * null — если такой записи нет (пара ещё не была под данью).
     */
    private function daysSinceLastLift(int $masterId, int $vassalId): ?float
    {
        $row = $this->tributes
            ->where('master_id', $masterId)
            ->where('vassal_id', $vassalId)
            ->where('status !=', 'active')
            ->orderBy('lifted_at', 'DESC')
            ->first();
        if (! is_array($row)) {
            return null;
        }
        $liftedAt = $row['lifted_at'] ?? null;
        if (! is_string($liftedAt) || $liftedAt === '') {
            return null;
        }
        return abs((float) (new Time($liftedAt))->difference(Time::now())->getDays());
    }

    private function createTribute(int $masterId, int $vassalId): ?int
    {
        $now      = date('Y-m-d H:i:s');
        $expiresT = Time::now()->addDays($this->durationDays())->toDateTimeString();
        $expires  = is_string($expiresT) && $expiresT !== '' ? $expiresT : $now;

        $id = $this->tributes->insert([
            'master_id'       => $masterId,
            'vassal_id'       => $vassalId,
            'rate'            => $this->rate(),
            'status'          => 'active',
            'total_collected' => 0,
            'collected_today' => 0,
            'started_at'      => $now,
            'expires_at'      => $expires,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return is_numeric($id) ? (int) $id : null;
    }

    private function windowStart(): string
    {
        $s = Time::now()->subDays($this->windowDays())->toDateTimeString();
        return is_string($s) && $s !== '' ? $s : date('Y-m-d H:i:s');
    }

    private function accountAgeDays(mixed $char): int
    {
        $created = $this->strField($char, 'created_at') ?? '1970-01-01';
        return (int) abs((new Time($created))->difference(Time::now())->getDays());
    }

    private function respawnHoursAgo(mixed $char): ?float
    {
        $lr = $this->strField($char, 'last_respawn_at');
        if ($lr === null || $lr === '') {
            return null;
        }
        return abs((float) (new Time($lr))->difference(Time::now())->getHours());
    }

    private function numField(mixed $char, string $key): int
    {
        if (is_array($char) || $char instanceof \ArrayAccess) {
            $v = $char[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    private function strField(mixed $char, string $key): ?string
    {
        if (is_array($char) || $char instanceof \ArrayAccess) {
            $v = $char[$key] ?? null;
            if (is_string($v)) {
                return $v;
            }
            return is_numeric($v) ? (string) $v : null;
        }
        return null;
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

    /**
     * Безопасное чтение int-ключа из mixed-контекста (strict L9: без (int)$mixed).
     *
     * @param array<string,mixed> $ctx
     */
    private static function ctxInt(array $ctx, string $key, int $default): int
    {
        $v = $ctx[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /**
     * Нормализует ключи строки БД в string (strict L9: array<string,mixed>).
     *
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private function stringKeys(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
