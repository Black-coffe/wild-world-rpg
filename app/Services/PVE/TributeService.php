<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
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
    private CharacterResourceModel $resources;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?CharacterModel $characters = null,
        ?CharacterTributeModel $tributes = null,
        ?CharacterResourceModel $resources = null
    ) {
        $this->settings   = $settings ?? new GameSettingsService();
        $this->characters = $characters ?? new CharacterModel();
        $this->tributes   = $tributes ?? new CharacterTributeModel();
        $this->resources  = $resources ?? new CharacterResourceModel();
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

    public function dailyCapPerMaster(): int
    {
        return $this->intSetting('tribute.daily_cap_per_master', 200);
    }

    public function ransomBase(): int
    {
        return $this->intSetting('tribute.ransom_base', 2000);
    }

    public function ransomPerCollectedK(): float
    {
        $v = $this->settings->get('tribute.ransom_per_collected_k', 0.5);
        return is_numeric($v) ? (float) $v : 0.5;
    }

    public function ransomHardCap(): int
    {
        return $this->intSetting('tribute.ransom_hard_cap', 15000);
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

    /**
     * Есть ли у персонажа активная подать (как вассал ИЛИ как хозяин) — для показа
     * кнопки входа на экране Персонажа (Ф4). Вызывается только при enabled() (dormant → не идёт).
     */
    public function hasAnyTributeRelation(int $charId): bool
    {
        if ($charId <= 0) {
            return false;
        }
        try {
            $cnt = Database::connect()->table('character_tributes')
                ->where('status', 'active')
                ->groupStart()
                    ->where('vassal_id', $charId)
                    ->orWhere('master_id', $charId)
                ->groupEnd()
                ->countAllResults();
            return $cnt > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Активные подати, где персонаж — хозяин (его данники). Для экрана статуса (Ф4).
     *
     * @return list<array<string,mixed>>
     */
    public function activeAsMaster(int $masterId): array
    {
        if ($masterId <= 0) {
            return [];
        }
        try {
            $rows = $this->tributes
                ->where('master_id', $masterId)
                ->where('status', 'active')
                ->orderBy('started_at', 'DESC')
                ->findAll(50);
            $out = [];
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $out[] = $this->stringKeys($r);
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Фаза 2: сбор подати с добычи жертвы. Вызывается ДО зачисления `$foundResources`
     * жертве. Если жертва под активной податью — перетекает rate% КАЖДОГО ресурса хозяину
     * (zero-sum: жертва получает остаток), в пределах дневного cap хозяина. Возвращает
     * УМЕНЬШЕННЫЙ список для зачисления жертве. Killswitch/нет-подати/ошибка → identity.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity                $vassal
     * @param array<int, array{resource_id:int, amount:int, type?:string}>     $foundResources
     * @return array<int, array{resource_id:int, amount:int, type?:string}>
     */
    public function collectOnGather(array|\App\Entities\CharacterEntity $vassal, array $foundResources): array
    {
        if (! $this->enabled() || $foundResources === []) {
            return $foundResources;
        }
        try {
            $vassalId = $this->numField($vassal, 'id');
            if ($vassalId <= 0) {
                return $foundResources;
            }
            $tribute = $this->getActiveTribute($vassalId);
            if ($tribute === null) {
                return $foundResources;
            }
            // Истёкшую подать не доим (авто-снятие — Фаза 3).
            $expiresAt = $tribute['expires_at'] ?? null;
            if (is_string($expiresAt) && $expiresAt !== '') {
                $ts = strtotime($expiresAt);
                if ($ts !== false && $ts <= time()) {
                    return $foundResources;
                }
            }
            $masterId = is_numeric($tribute['master_id'] ?? null) ? (int) $tribute['master_id'] : 0;
            if ($masterId <= 0 || $masterId === $vassalId) {
                return $foundResources;
            }
            $rate      = is_numeric($tribute['rate'] ?? null) ? (float) $tribute['rate'] : $this->rate();
            $remaining = $this->masterDailyRemaining($masterId);
            if ($remaining <= 0 || $rate <= 0.0) {
                return $foundResources;
            }

            $split = self::splitGather($foundResources, $rate, $remaining);
            if ($split['transferred'] <= 0) {
                return $foundResources;
            }

            $this->creditResources($masterId, $split['masterCredits']);
            $this->recordCollection($tribute, $split['transferred']);

            return $split['vassal'];
        } catch (\Throwable $e) {
            log_message('error', 'TributeService::collectOnGather failed: ' . $e->getMessage());
            return $foundResources;
        }
    }

    /**
     * Чистый zero-sum раздел добычи: с каждого ресурса хозяину уходит floor(amount*rate)
     * (в пределах общего remainingCap), жертве остаётся остаток. Инвариант для каждого
     * ресурса: vassal_amount + masterShare == original. Без БД → unit-тестируемо.
     *
     * @param array<int, array{resource_id:int, amount:int, type?:string}> $foundResources
     * @return array{vassal: array<int, array{resource_id:int, amount:int, type?:string}>, masterCredits: array<int,int>, transferred:int}
     */
    public static function splitGather(array $foundResources, float $rate, int $remainingCap): array
    {
        $vassal        = [];
        $masterCredits = [];
        $transferred   = 0;
        $remaining     = $remainingCap > 0 ? $remainingCap : 0;

        foreach ($foundResources as $res) {
            $rid     = (int) $res['resource_id'];
            $amount  = (int) $res['amount'];
            $tribute = 0;
            if ($amount >= 1 && $rate > 0.0 && $remaining > 0) {
                $tribute = (int) floor($amount * $rate);
                if ($tribute > $remaining) {
                    $tribute = $remaining;
                }
                if ($tribute > 0) {
                    $remaining   -= $tribute;
                    $transferred += $tribute;
                    $masterCredits[$rid] = ($masterCredits[$rid] ?? 0) + $tribute;
                }
            }
            $newRes           = $res;
            $newRes['amount'] = $amount - $tribute;
            $vassal[]         = $newRes;
        }

        return ['vassal' => $vassal, 'masterCredits' => $masterCredits, 'transferred' => $transferred];
    }

    // ── Фаза 3: снятие / выход ───────────────────────────────────────────

    /**
     * Реванш: победитель (бывший данник) победил своего хозяина → подать снимается.
     * winner = vassal, loser = master. Killswitch-gated. Batch-UPDATE (атомарно).
     * Возвращает число снятых податей (0/1).
     */
    public function liftByRematch(int $winnerId, int $loserId): int
    {
        if (! $this->enabled() || $winnerId <= 0 || $loserId <= 0 || $winnerId === $loserId) {
            return 0;
        }
        try {
            $now = date('Y-m-d H:i:s');
            Database::connect()->table('character_tributes')
                ->where('master_id', $loserId)
                ->where('vassal_id', $winnerId)
                ->where('status', 'active')
                ->update(['status' => 'rematch', 'lifted_by' => 'rematch', 'lifted_at' => $now, 'updated_at' => $now]);
            $affected = Database::connect()->affectedRows();
            return $affected > 0 ? $affected : 0;
        } catch (\Throwable $e) {
            log_message('error', 'TributeService::liftByRematch failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Идемпотентное авто-снятие истёкших податей (cron-таймер). НЕ killswitch-gated —
     * чистка должна работать, даже если механику выключили (иначе данники зависнут навсегда).
     * Batch-UPDATE (атомарно; повторный прогон → 0 затронутых). Возвращает число снятых.
     */
    public function expireOverdue(): int
    {
        try {
            $now = date('Y-m-d H:i:s');
            Database::connect()->table('character_tributes')
                ->where('status', 'active')
                ->where('expires_at <=', $now)
                ->update(['status' => 'expired', 'lifted_by' => 'timer', 'lifted_at' => $now, 'updated_at' => $now]);
            $affected = Database::connect()->affectedRows();
            return $affected > 0 ? $affected : 0;
        } catch (\Throwable $e) {
            log_message('error', 'TributeService::expireOverdue failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Стоимость выкупа из-под подати (gold-burn). Растёт с собранного, clamp [base, hard_cap].
     *
     * @param array<string,mixed> $tribute
     */
    public function ransomCost(array $tribute): int
    {
        $collected = is_numeric($tribute['total_collected'] ?? null) ? (int) $tribute['total_collected'] : 0;
        return self::computeRansom($collected, $this->ransomBase(), $this->ransomPerCollectedK(), $this->ransomHardCap());
    }

    /**
     * Чистая формула выкупа: base + floor(collected*k), clamp [base, hardCap]. Без БД.
     */
    public static function computeRansom(int $collected, int $base, float $k, int $hardCap): int
    {
        $collected = max(0, $collected);
        $cost      = $base + (int) floor($collected * $k);
        if ($cost < $base) {
            $cost = $base;
        }
        if ($hardCap > 0 && $cost > $hardCap) {
            $cost = $hardCap;
        }
        return $cost;
    }

    /**
     * Выкуп жертвы из-под подати: списывает золото (СГОРАЕТ, не хозяину) и снимает подать.
     * Killswitch-gated. Backend Фазы 3 — кнопку вешает Фаза 4.
     *
     * @return array{ok:bool, reason:string, cost:int}
     */
    public function buyOut(int $vassalId): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'reason' => 'disabled', 'cost' => 0];
        }
        if ($vassalId <= 0) {
            return ['ok' => false, 'reason' => 'invalid', 'cost' => 0];
        }
        try {
            $tribute = $this->getActiveTribute($vassalId);
            if ($tribute === null) {
                return ['ok' => false, 'reason' => 'no_tribute', 'cost' => 0];
            }
            $cost   = $this->ransomCost($tribute);
            $vassal = $this->characters->find($vassalId);
            if ($vassal === null) {
                return ['ok' => false, 'reason' => 'not_found', 'cost' => $cost];
            }
            $gold = $this->numField($vassal, 'gold');
            if ($gold < $cost) {
                return ['ok' => false, 'reason' => 'insufficient_gold', 'cost' => $cost];
            }
            $id = is_numeric($tribute['id'] ?? null) ? (int) $tribute['id'] : 0;
            $db = Database::connect();
            $db->transStart();
            $this->characters->update($vassalId, ['gold' => $gold - $cost]); // burn (золото уходит из экономики)
            if ($id > 0) {
                $this->markLifted($id, 'bought_out', 'buyout');
            }
            $db->transComplete();
            if ($db->transStatus() === false) {
                return ['ok' => false, 'reason' => 'tx_failed', 'cost' => $cost];
            }
            return ['ok' => true, 'reason' => 'bought_out', 'cost' => $cost];
        } catch (\Throwable $e) {
            log_message('error', 'TributeService::buyOut failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'error', 'cost' => 0];
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

    /**
     * Остаток дневного cap хозяина (по ВСЕМ его активным податям за сегодня). Анти-снежок:
     * суммарный переток одному хозяину за сутки ограничен `tribute.daily_cap_per_master`.
     */
    private function masterDailyRemaining(int $masterId): int
    {
        $cap = $this->dailyCapPerMaster();
        if ($cap <= 0) {
            return 0;
        }
        $today = date('Y-m-d');
        $row   = Database::connect()->table('character_tributes')
            ->selectSum('collected_today', 'sum_today')
            ->where('master_id', $masterId)
            ->where('status', 'active')
            ->where('collected_today_date', $today)
            ->get();
        $arr = $row === false ? null : $row->getRowArray();
        $sum = (is_array($arr) && is_numeric($arr['sum_today'] ?? null)) ? (int) $arr['sum_today'] : 0;
        return max(0, $cap - $sum);
    }

    /**
     * Зачислить хозяину ресурсы (карта resource_id→qty). Один whereIn + цикл insert/update
     * (паттерн GatherResultPersister; без `where()->first()` в цикле — урок builder-quirk).
     *
     * @param array<int,int> $credits
     */
    private function creditResources(int $masterId, array $credits): void
    {
        if ($credits === []) {
            return;
        }
        $master = $this->characters->find($masterId);
        if ($master === null) {
            return;
        }
        $tuid = $this->numField($master, 'telegram_user_id');
        $rids = array_map('intval', array_keys($credits));

        $existing = $this->resources
            ->where('id_characters', $masterId)
            ->whereIn('id_resources', $rids)
            ->findAll();
        $byRid = [];
        foreach ($existing as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ridRaw = $row['id_resources'] ?? null;
            if (is_numeric($ridRaw)) {
                $byRid[(int) $ridRaw] = $row;
            }
        }

        foreach ($credits as $rid => $qty) {
            $rid = (int) $rid;
            $qty = (int) $qty;
            if ($qty < 1) {
                continue;
            }
            $ex = $byRid[$rid] ?? null;
            if (is_array($ex)) {
                $curRaw  = $ex['quantity'] ?? null;
                $cur     = is_numeric($curRaw) ? (int) $curRaw : 0;
                $exIdRaw = $ex['id'] ?? null;
                $exId    = is_numeric($exIdRaw) ? (int) $exIdRaw : 0;
                if ($exId > 0) {
                    $this->resources->update($exId, ['quantity' => $cur + $qty]);
                }
            } else {
                $this->resources->insert([
                    'id_characters'     => $masterId,
                    'id_resources'      => $rid,
                    'id_telegram_users' => $tuid,
                    'quantity'          => $qty,
                ]);
            }
        }
    }

    /**
     * Учесть собранное в строке подати: дневной аккумулятор (сброс при смене суток) + total.
     *
     * @param array<string,mixed> $tribute
     */
    private function recordCollection(array $tribute, int $transferred): void
    {
        $id = is_numeric($tribute['id'] ?? null) ? (int) $tribute['id'] : 0;
        if ($id <= 0 || $transferred <= 0) {
            return;
        }
        $today     = date('Y-m-d');
        $wasDate   = is_string($tribute['collected_today_date'] ?? null) ? $tribute['collected_today_date'] : null;
        $prevToday = is_numeric($tribute['collected_today'] ?? null) ? (int) $tribute['collected_today'] : 0;
        $prevTotal = is_numeric($tribute['total_collected'] ?? null) ? (int) $tribute['total_collected'] : 0;

        $collectedToday = ($wasDate === $today) ? $prevToday + $transferred : $transferred;

        $this->tributes->update($id, [
            'collected_today'      => $collectedToday,
            'collected_today_date' => $today,
            'total_collected'      => $prevTotal + $transferred,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
    }

    /** Снять подать (status/lifted_by/lifted_at) по id — общий помощник для buyout. */
    private function markLifted(int $id, string $status, string $by): void
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table('character_tributes')
            ->where('id', $id)
            ->update(['status' => $status, 'lifted_by' => $by, 'lifted_at' => $now, 'updated_at' => $now]);
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
