<?php

declare(strict_types=1);

namespace App\Services\Oracle;

use App\Models\CharacterModel;
use App\Models\OracleBetModel;
use App\Models\OracleMarketModel;
use App\Models\OracleOutcomeModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * ADR-133 — движок «Оракул острова»: золотой пари-мютюэль рынок предсказаний.
 *
 * Гарант-сток (НЕ фонтан): выигрыш платится ТОЛЬКО из ставок проигравших, дом сжигает
 * рейк. Инвариант {@see self::computeSettlement()} — Σвыплат ≤ Σставок (чистая функция,
 * unit-тестируется без БД). Метрики исходов — серверные агрегаты без соло-рычага
 * (гонка фракций) или чистый RNG (событие дня). Всё под killswitch oracle.enabled.
 *
 * Жизненный цикл рынка (крон OracleSettlementHandler, зеркало PvpLadderWeekly):
 *   open → (locks_at) locked → (resolves_at) settled | void.
 */
class OracleService
{
    use GameSettingsReaderTrait;

    private const DAY = 86400;

    // ───────────────────────── Чистое ядро (без БД) ─────────────────────────

    /**
     * Пари-мютюэль расчёт. Чистая функция: по ставкам и победному исходу возвращает
     * выплату на каждую ставку (тот же порядок, что во входе). Инвариант: Σвыплат ≤ Σставок.
     *
     * void (полный возврат, рейк 0) при: пустом/тонком пуле (< minPool), отсутствии
     * победного исхода, одностороннем пуле (<2 исходов), нуле ставок на победный исход.
     *
     * @param list<array{outcome:string, stake:int}> $bets
     * @return array{void:bool, pool:int, rake:int, distributable:int, winningPool:int, payouts:list<int>}
     */
    public static function computeSettlement(array $bets, ?string $winningOutcome, float $rakePct, int $minPool): array
    {
        $pool         = 0;
        $outcomesSeen = [];
        foreach ($bets as $b) {
            $pool += $b['stake'];
            $outcomesSeen[$b['outcome']] = true;
        }

        $voidRefund = static function () use ($bets, $pool): array {
            $payouts = [];
            foreach ($bets as $b) {
                $payouts[] = $b['stake'];
            }

            return ['void' => true, 'pool' => $pool, 'rake' => 0, 'distributable' => $pool, 'winningPool' => 0, 'payouts' => $payouts];
        };

        if ($bets === [] || $pool < $minPool || $winningOutcome === null || count($outcomesSeen) < 2) {
            return $voidRefund();
        }

        $winningPool = 0;
        foreach ($bets as $b) {
            if ($b['outcome'] === $winningOutcome) {
                $winningPool += $b['stake'];
            }
        }
        if ($winningPool <= 0) {
            return $voidRefund();
        }

        $rake = (int) floor($pool * $rakePct);
        $rake = max(0, min($pool, $rake));
        $distributable = $pool - $rake;

        $payouts = [];
        foreach ($bets as $b) {
            if ($b['outcome'] === $winningOutcome && $b['stake'] > 0) {
                $payouts[] = (int) floor($b['stake'] * $distributable / $winningPool);
            } else {
                $payouts[] = 0;
            }
        }

        return ['void' => false, 'pool' => $pool, 'rake' => $rake, 'distributable' => $distributable, 'winningPool' => $winningPool, 'payouts' => $payouts];
    }

    // ───────────────────────── Конфиг (GameSettings) ────────────────────────

    public function enabled(): bool
    {
        return $this->gsBool('oracle.enabled', false);
    }

    public function rakePct(): float
    {
        return $this->gsFloat('oracle.rake_pct', 0.08);
    }

    public function minStake(): int
    {
        return $this->gsInt('oracle.min_stake', 50);
    }

    public function maxStake(): int
    {
        return $this->gsInt('oracle.max_stake', 5000);
    }

    public function minLevel(): int
    {
        return $this->gsInt('oracle.min_level', 10);
    }

    public function reserveFloor(): int
    {
        return $this->gsInt('oracle.reserve_floor', 0);
    }

    public function minPoolToSettle(): int
    {
        return $this->gsInt('oracle.min_pool_to_settle', 500);
    }

    /**
     * @return list<int>
     */
    public function betOptions(): array
    {
        $raw  = $this->gsString('oracle.bet_options', '50,100,250,500,1000');
        $bets = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $bets[] = (int) $part;
            }
        }

        return $bets !== [] ? $bets : [50, 100, 250, 500, 1000];
    }

    /**
     * Каталог разрешённых рынков (вайтлист — игрок их не создаёт).
     *
     * @return list<array{key:string, type:string, metric:string, question:string, enabledKey:string}>
     */
    private function marketCatalog(): array
    {
        return [
            [
                'key'        => 'faction_race',
                'type'       => 'weekly',
                'metric'     => 'faction_race',
                'question'   => 'Какая фракция наберёт больше очков за эту неделю?',
                'enabledKey' => 'oracle.weekly_market_enabled',
            ],
            [
                'key'        => 'event_today',
                'type'       => 'daily',
                'metric'     => 'event_today',
                'question'   => 'Сработает ли сегодня мировое событие?',
                'enabledKey' => 'oracle.daily_market_enabled',
            ],
        ];
    }

    // ───────────────────────── Жизненный цикл рынков ────────────────────────

    /** Время сейчас (UNIX ts). Seam для тестов. */
    protected function now(): int
    {
        return time();
    }

    /**
     * Открывает рынки текущего цикла, если их ещё нет (идемпотентно — UNIQUE(market_key,cycle)).
     */
    public function ensureMarkets(?int $nowTs = null): void
    {
        if (! $this->enabled()) {
            return;
        }
        $now = $nowTs ?? $this->now();
        foreach ($this->marketCatalog() as $def) {
            if (! $this->gsBool($def['enabledKey'], true)) {
                continue;
            }
            $this->ensureMarket($def, $now);
        }
    }

    /**
     * @param array{key:string, type:string, metric:string, question:string, enabledKey:string} $def
     */
    private function ensureMarket(array $def, int $now): void
    {
        $schedule = $def['type'] === 'weekly' ? $this->weeklySchedule($now) : $this->dailySchedule($now);

        $model    = new OracleMarketModel();
        $existing = $model->where('market_key', $def['key'])->where('cycle', $schedule['cycle'])->first();
        if ($existing !== null) {
            return;
        }

        $nowStr        = date('Y-m-d H:i:s', $now);
        $snapshotStart = null;
        if ($def['metric'] === 'faction_race') {
            $j             = json_encode($this->factionScores());
            $snapshotStart = $j === false ? null : $j;
        }

        try {
            $model->insert([
                'market_key'      => $def['key'],
                'market_type'     => $def['type'],
                'question'        => $def['question'],
                'metric_key'      => $def['metric'],
                'cycle'           => $schedule['cycle'],
                'status'          => 'open',
                'rake_pct'        => $this->rakePct(),
                'snapshot_start'  => $snapshotStart,
                'snapshot_final'  => null,
                'winning_outcome' => null,
                'opens_at'        => date('Y-m-d H:i:s', $schedule['opens']),
                'locks_at'        => date('Y-m-d H:i:s', $schedule['locks']),
                'resolves_at'     => date('Y-m-d H:i:s', $schedule['resolves']),
                'created_at'      => $nowStr,
                'updated_at'      => $nowStr,
            ]);
        } catch (\Throwable) {
            // Гонка на UNIQUE(market_key,cycle) — рынок создал параллельный тик, ок.
            return;
        }

        $marketId = (int) $model->getInsertID();
        if ($marketId > 0) {
            $this->createOutcomes($marketId, $def);
        }
    }

    /**
     * @param array{key:string, type:string, metric:string, question:string, enabledKey:string} $def
     */
    private function createOutcomes(int $marketId, array $def): void
    {
        /** @var list<array{code:string, label:string, sort:int}> $outcomes */
        $outcomes = [];

        if ($def['metric'] === 'faction_race') {
            $labels = $this->factionLabels();
            $sort   = 0;
            foreach (array_keys($this->factionScores()) as $fid) {
                $outcomes[] = ['code' => (string) $fid, 'label' => $labels[$fid] ?? ('Фракция ' . $fid), 'sort' => $sort++];
            }
            if ($outcomes === []) {
                for ($i = 1; $i <= 4; $i++) {
                    $outcomes[] = ['code' => (string) $i, 'label' => 'Фракция ' . $i, 'sort' => $i];
                }
            }
        } else {
            $outcomes[] = ['code' => 'yes', 'label' => 'Да, событие будет', 'sort' => 0];
            $outcomes[] = ['code' => 'no', 'label' => 'Нет, спокойный день', 'sort' => 1];
        }

        $om = new OracleOutcomeModel();
        foreach ($outcomes as $o) {
            try {
                $om->insert(['market_id' => $marketId, 'code' => $o['code'], 'label' => $o['label'], 'sort_order' => $o['sort']]);
            } catch (\Throwable) {
                // дубликат UNIQUE(market_id,code) — ок.
            }
        }
    }

    /**
     * @return array{cycle:string, opens:int, locks:int, resolves:int}
     */
    private function weeklySchedule(int $now): array
    {
        $dow       = (int) date('N', $now);
        $dayStart  = strtotime(date('Y-m-d', $now) . ' 00:00:00');
        $dayStart  = $dayStart === false ? $now : $dayStart;
        $weekStart = $dayStart - ($dow - 1) * self::DAY;

        $lockDay    = $this->gsInt('oracle.weekly_lock_day', 6);
        $lockHour   = $this->gsInt('oracle.weekly_lock_hour', 21);
        $settleDay  = $this->gsInt('oracle.weekly_settle_day', 7);
        $settleHour = $this->gsInt('oracle.weekly_settle_hour', 23);

        return [
            'cycle'    => date('o-W', $now),
            'opens'    => $weekStart,
            'locks'    => $weekStart + ($lockDay - 1) * self::DAY + $lockHour * 3600,
            'resolves' => $weekStart + ($settleDay - 1) * self::DAY + $settleHour * 3600,
        ];
    }

    /**
     * @return array{cycle:string, opens:int, locks:int, resolves:int}
     */
    private function dailySchedule(int $now): array
    {
        $dayStart = strtotime(date('Y-m-d', $now) . ' 00:00:00');
        $dayStart = $dayStart === false ? $now : $dayStart;

        $lockHour   = $this->gsInt('oracle.daily_lock_hour', 20);
        $settleHour = $this->gsInt('oracle.daily_settle_hour', 0);

        return [
            'cycle'    => date('Y-m-d', $now),
            'opens'    => $dayStart,
            'locks'    => $dayStart + $lockHour * 3600,
            'resolves' => $dayStart + self::DAY + $settleHour * 3600,
        ];
    }

    /** Закрывает ставки у рынков, чьё locks_at наступило. @return int кол-во закрытых. */
    public function lockDueMarkets(?int $nowTs = null): int
    {
        $now = date('Y-m-d H:i:s', $nowTs ?? $this->now());
        $db  = \Config\Database::connect();
        $db->table('oracle_markets')
            ->where('status', 'open')
            ->where('locks_at <=', $now)
            ->update(['status' => 'locked', 'updated_at' => $now]);

        return $db->affectedRows();
    }

    /**
     * Рассчитывает рынки, чьё resolves_at наступило. Возвращает уведомления участникам.
     *
     * @return list<array{character_id:int, telegram_id:int|null, text:string}>
     */
    public function settleDueMarkets(?int $nowTs = null): array
    {
        $now    = $nowTs ?? $this->now();
        $nowStr = date('Y-m-d H:i:s', $now);

        /** @var list<array<string,mixed>> $due */
        $due = (new OracleMarketModel())
            ->where('status', 'locked')
            ->where('resolves_at <=', $nowStr)
            ->findAll();

        $notifications = [];
        foreach ($due as $market) {
            foreach ($this->settleMarket($market, $now) as $note) {
                $notifications[] = $note;
            }
        }

        return $notifications;
    }

    /**
     * @param array<string,mixed> $market
     * @return list<array{character_id:int, telegram_id:int|null, text:string}>
     */
    private function settleMarket(array $market, int $now): array
    {
        $id = is_numeric($market['id'] ?? null) ? (int) $market['id'] : 0;
        if ($id <= 0) {
            return [];
        }
        $nowStr = date('Y-m-d H:i:s', $now);
        $db     = \Config\Database::connect();

        // Атомарный claim рынка (locked → settling) — гонка-сейф при пересекающихся тиках.
        $db->table('oracle_markets')->where('id', $id)->where('status', 'locked')->update(['status' => 'settling', 'updated_at' => $nowStr]);
        if ($db->affectedRows() !== 1) {
            return [];
        }

        $betModel = new OracleBetModel();
        /** @var list<array<string,mixed>> $betRows */
        $betRows = $betModel->where('market_id', $id)->where('status', 'placed')->findAll();

        /** @var list<array{id:int, character_id:int, outcome:string, stake:int}> $bets */
        $bets = [];
        foreach ($betRows as $r) {
            $bets[] = [
                'id'           => is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0,
                'character_id' => is_numeric($r['character_id'] ?? null) ? (int) $r['character_id'] : 0,
                'outcome'      => is_string($r['outcome_code'] ?? null) ? $r['outcome_code'] : '',
                'stake'        => is_numeric($r['stake'] ?? null) ? (int) $r['stake'] : 0,
            ];
        }

        $rakePct = is_numeric($market['rake_pct'] ?? null) ? (float) $market['rake_pct'] : $this->rakePct();
        $winning = $this->resolveWinningOutcome($market);

        $settleInput = [];
        foreach ($bets as $b) {
            $settleInput[] = ['outcome' => $b['outcome'], 'stake' => $b['stake']];
        }
        $result = self::computeSettlement($settleInput, $winning, $rakePct, $this->minPoolToSettle());

        $charModel = new CharacterModel();
        /** @var array<int, array{stake:int, payout:int}> $perChar */
        $perChar = [];

        foreach ($bets as $i => $b) {
            $payout = $result['payouts'][$i] ?? 0;

            if ($result['void']) {
                if ($payout > 0 && $b['character_id'] > 0) {
                    $charModel->increaseGold($b['character_id'], $payout);
                }
                $betModel->update($b['id'], ['status' => 'refunded', 'payout' => $payout, 'resolved_at' => $nowStr]);
            } elseif ($payout > 0 && $b['character_id'] > 0) {
                $charModel->increaseGold($b['character_id'], $payout);
                $betModel->update($b['id'], ['status' => 'won', 'payout' => $payout, 'resolved_at' => $nowStr]);
            } else {
                $betModel->update($b['id'], ['status' => 'lost', 'payout' => 0, 'resolved_at' => $nowStr]);
            }

            if ($b['character_id'] > 0) {
                if (! isset($perChar[$b['character_id']])) {
                    $perChar[$b['character_id']] = ['stake' => 0, 'payout' => 0];
                }
                $perChar[$b['character_id']]['stake'] += $b['stake'];
                $perChar[$b['character_id']]['payout'] += $payout;
            }
        }

        $finalStatus = $result['void'] ? 'void' : 'settled';
        $db->table('oracle_markets')->where('id', $id)->update([
            'status'          => $finalStatus,
            'winning_outcome' => $result['void'] ? null : $winning,
            'snapshot_final'  => $this->snapshotFinal($market),
            'updated_at'      => $nowStr,
        ]);

        log_message('info', "[Oracle] market#{$id} {$finalStatus} pool={$result['pool']} rake={$result['rake']} winner=" . ($winning ?? 'void'));

        $question = is_string($market['question'] ?? null) ? $market['question'] : '';
        $winLabel = $winning !== null ? $this->outcomeLabel($id, $winning) : '';

        $notifications = [];
        foreach ($perChar as $charId => $agg) {
            $notifications[] = [
                'character_id' => $charId,
                'telegram_id'  => $this->telegramIdForCharacter($charId),
                'text'         => $this->renderSettlementText($result['void'], $question, $winLabel, $agg['stake'], $agg['payout']),
            ];
        }

        return $notifications;
    }

    private function renderSettlementText(bool $void, string $question, string $winLabel, int $stake, int $payout): string
    {
        if ($void) {
            return "🎲 *Оракул острова* — рынок аннулирован\n«{$question}»\n\n"
                . "Ставок за период было слишком мало (тонкий пул) → твоя ставка {$stake}🪙 возвращена полностью.";
        }
        if ($payout > 0) {
            $net  = $payout - $stake;
            $sign = $net >= 0 ? '+' : '';

            return "🎲 *Оракул острова* — котировка снята\n«{$question}»\nИтог: *{$winLabel}*\n\n"
                . "Ты угадал! Ставка {$stake}🪙 → выплата *{$payout}🪙* ({$sign}{$net}). Зачислено ✅";
        }

        return "🎲 *Оракул острова* — котировка снята\n«{$question}»\nИтог: *{$winLabel}*\n\n"
            . "Увы, в этот раз мимо. Ставка {$stake}🪙 не сыграла.";
    }

    // ───────────────────────── Резолверы исходов (метрики) ──────────────────

    /**
     * @param array<string,mixed> $market
     */
    public function resolveWinningOutcome(array $market): ?string
    {
        $metric = is_string($market['metric_key'] ?? null) ? $market['metric_key'] : '';

        return match ($metric) {
            'faction_race' => $this->resolveFactionRace($market),
            'event_today'  => $this->resolveEventToday($market),
            default        => null,
        };
    }

    /**
     * @param array<string,mixed> $market
     */
    private function resolveFactionRace(array $market): ?string
    {
        $startJson = is_string($market['snapshot_start'] ?? null) ? $market['snapshot_start'] : '';
        $decoded   = json_decode($startJson, true);
        /** @var array<array-key,mixed> $start */
        $start = is_array($decoded) ? $decoded : [];

        $current = $this->factionScores();
        if ($current === []) {
            return null;
        }

        $bestCode  = null;
        $bestDelta = null;
        foreach ($current as $fid => $score) {
            $startRaw   = $start[(string) $fid] ?? ($start[$fid] ?? 0);
            $startScore = is_numeric($startRaw) ? (int) $startRaw : 0;
            $delta      = $score - $startScore;
            if ($bestDelta === null || $delta > $bestDelta) {
                $bestDelta = $delta;
                $bestCode  = (string) $fid;
            }
        }

        return $bestCode;
    }

    /**
     * @param array<string,mixed> $market
     */
    private function resolveEventToday(array $market): ?string
    {
        $cycle = is_string($market['cycle'] ?? null) ? $market['cycle'] : '';
        if ($cycle === '') {
            return null;
        }
        $startTs = strtotime($cycle . ' 00:00:00');
        if ($startTs === false) {
            return null;
        }

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('active_events')) {
                return 'no';
            }
            $count = $db->table('active_events')
                ->where('created_at >=', date('Y-m-d H:i:s', $startTs))
                ->where('created_at <', date('Y-m-d H:i:s', $startTs + self::DAY))
                ->countAllResults();

            return $count > 0 ? 'yes' : 'no';
        } catch (\Throwable) {
            return null;
        }
    }

    // ───────────────────────── Размещение ставки ───────────────────────────

    /**
     * Принимает ставку: гейты + эскроу-дебет золота + запись oracle_bets.
     *
     * @return array{ok:bool, error:string, balance:int, stake:int}
     */
    public function placeBet(int $characterId, int $marketId, string $outcomeCode, int $stake): array
    {
        $fail = static fn (string $e): array => ['ok' => false, 'error' => $e, 'balance' => 0, 'stake' => 0];

        if (! $this->enabled()) {
            return $fail('disabled');
        }

        $charModel = new CharacterModel();
        // Читаем level/gold массивом через builder (CharacterModel->find отдаёт Entity —
        // phpstan ругается на offset-доступ к object). decreaseGold ниже берёт только id.
        $db   = \Config\Database::connect();
        $crow = $db->table('characters')->select('level, gold')->where('id', $characterId)->get();
        $char = $crow === false ? null : $crow->getRowArray();
        if (! is_array($char)) {
            return $fail('no_char');
        }
        $level = is_numeric($char['level'] ?? null) ? (int) $char['level'] : 0;
        $gold  = is_numeric($char['gold'] ?? null) ? (int) $char['gold'] : 0;
        if ($level < $this->minLevel()) {
            return $fail('level');
        }

        /** @var array<string,mixed>|null $market */
        $market = (new OracleMarketModel())->find($marketId);
        if (! is_array($market) || ($market['status'] ?? '') !== 'open') {
            return $fail('market_closed');
        }

        $outcome = (new OracleOutcomeModel())->where('market_id', $marketId)->where('code', $outcomeCode)->first();
        if ($outcome === null) {
            return $fail('bad_outcome');
        }

        $min = $this->minStake();
        $max = $this->maxStake();
        if ($stake < $min || $stake > $max) {
            return $fail('bad_stake');
        }

        $already = $this->myStakeOnMarket($characterId, $marketId);
        if ($already + $stake > $max) {
            return $fail('over_cap');
        }

        if ($gold < $stake) {
            return $fail('no_gold');
        }
        if ($gold - $stake < $this->reserveFloor()) {
            return $fail('reserve');
        }

        if (! $charModel->decreaseGold($characterId, $stake)) {
            return $fail('debit_failed');
        }

        $now = date('Y-m-d H:i:s', $this->now());
        (new OracleBetModel())->insert([
            'market_id'    => $marketId,
            'character_id' => $characterId,
            'outcome_code' => $outcomeCode,
            'stake'        => $stake,
            'status'       => 'placed',
            'payout'       => 0,
            'created_at'   => $now,
            'resolved_at'  => null,
        ]);

        return ['ok' => true, 'error' => '', 'balance' => $gold - $stake, 'stake' => $stake];
    }

    // ───────────────────────── Чтение для UI ───────────────────────────────

    /**
     * Открытые рынки для экрана-хаба.
     *
     * @return list<array{id:int, type:string, question:string, bank:int, locks_at:string}>
     */
    public function openMarketsForDisplay(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = (new OracleMarketModel())->where('status', 'open')->orderBy('market_type', 'ASC')->findAll();

        $out = [];
        foreach ($rows as $m) {
            $id = is_numeric($m['id'] ?? null) ? (int) $m['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'type'     => is_string($m['market_type'] ?? null) ? $m['market_type'] : 'weekly',
                'question' => is_string($m['question'] ?? null) ? $m['question'] : '',
                'bank'     => $this->totalPool($id),
                'locks_at' => is_string($m['locks_at'] ?? null) ? $m['locks_at'] : '',
            ];
        }

        return $out;
    }

    /**
     * Детальные данные рынка для экрана ставки.
     *
     * @return array{id:int, question:string, type:string, status:string, bank:int, rake:int, rake_pct:float, distributable:int, locks_at:string, outcomes:list<array{code:string, label:string, pool:int, share:int, odds:float}>}|null
     */
    public function marketForDisplay(int $marketId): ?array
    {
        /** @var array<string,mixed>|null $m */
        $m = (new OracleMarketModel())->find($marketId);
        if ($m === null) {
            return null;
        }

        $pools   = $this->poolByOutcome($marketId);
        $bank    = array_sum($pools);
        $rakePct = is_numeric($m['rake_pct'] ?? null) ? (float) $m['rake_pct'] : $this->rakePct();
        $rake    = (int) floor($bank * $rakePct);
        $dist    = $bank - $rake;

        /** @var list<array{code:string, label:string, pool:int, share:int, odds:float}> $outcomes */
        $outcomes = [];
        /** @var list<array<string,mixed>> $orows */
        $orows = (new OracleOutcomeModel())->where('market_id', $marketId)->orderBy('sort_order', 'ASC')->findAll();
        foreach ($orows as $o) {
            $code  = is_string($o['code'] ?? null) ? $o['code'] : '';
            $label = is_string($o['label'] ?? null) && $o['label'] !== '' ? $o['label'] : $code;
            $opool = $pools[$code] ?? 0;
            $share = $bank > 0 ? (int) round(100 * $opool / $bank) : 0;
            $odds  = $opool > 0 ? $dist / $opool : 0.0;
            $outcomes[] = ['code' => $code, 'label' => $label, 'pool' => $opool, 'share' => $share, 'odds' => $odds];
        }

        return [
            'id'            => $marketId,
            'question'      => is_string($m['question'] ?? null) ? $m['question'] : '',
            'type'          => is_string($m['market_type'] ?? null) ? $m['market_type'] : 'weekly',
            'status'        => is_string($m['status'] ?? null) ? $m['status'] : '',
            'bank'          => $bank,
            'rake'          => $rake,
            'rake_pct'      => $rakePct,
            'distributable' => $dist,
            'locks_at'      => is_string($m['locks_at'] ?? null) ? $m['locks_at'] : '',
            'outcomes'      => $outcomes,
        ];
    }

    /**
     * @return array<string,int> code => суммарный пул ставок
     */
    public function poolByOutcome(int $marketId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->table('oracle_bets')
            ->select('outcome_code, SUM(stake) AS pool')
            ->where('market_id', $marketId)
            ->where('status', 'placed')
            ->groupBy('outcome_code')
            ->get();
        if ($q === false) {
            return [];
        }

        $out = [];
        foreach ($q->getResultArray() as $r) {
            $code = is_string($r['outcome_code'] ?? null) ? $r['outcome_code'] : '';
            $pool = is_numeric($r['pool'] ?? null) ? (int) $r['pool'] : 0;
            if ($code !== '') {
                $out[$code] = $pool;
            }
        }

        return $out;
    }

    public function totalPool(int $marketId): int
    {
        return array_sum($this->poolByOutcome($marketId));
    }

    public function myStakeOnMarket(int $characterId, int $marketId): int
    {
        $db = \Config\Database::connect();
        $q  = $db->table('oracle_bets')
            ->selectSum('stake')
            ->where('market_id', $marketId)
            ->where('character_id', $characterId)
            ->where('status', 'placed')
            ->get();
        if ($q === false) {
            return 0;
        }
        $row = $q->getRowArray();

        return is_array($row) && is_numeric($row['stake'] ?? null) ? (int) $row['stake'] : 0;
    }

    /**
     * Активные ставки игрока (на открытых/закрытых рынках, ещё не рассчитанных).
     *
     * @return list<array{question:string, outcome:string, stake:int, status:string}>
     */
    public function myActiveBets(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->table('oracle_bets ob')
            ->select('ob.stake AS stake, ob.outcome_code AS outcome_code, om.question AS question, oo.label AS label')
            ->join('oracle_markets om', 'om.id = ob.market_id')
            ->join('oracle_outcomes oo', 'oo.market_id = ob.market_id AND oo.code = ob.outcome_code', 'left')
            ->where('ob.character_id', $characterId)
            ->where('ob.status', 'placed')
            ->orderBy('ob.id', 'DESC')
            ->get();
        if ($q === false) {
            return [];
        }

        $out = [];
        foreach ($q->getResultArray() as $r) {
            $out[] = [
                'question' => is_string($r['question'] ?? null) ? $r['question'] : '',
                'outcome'  => is_string($r['label'] ?? null) && $r['label'] !== '' ? $r['label'] : (is_string($r['outcome_code'] ?? null) ? $r['outcome_code'] : ''),
                'stake'    => is_numeric($r['stake'] ?? null) ? (int) $r['stake'] : 0,
                'status'   => 'placed',
            ];
        }

        return $out;
    }

    // ───────────────────────── DB-помощники ────────────────────────────────

    /**
     * @return array<int,int> faction_id => score
     */
    private function factionScores(): array
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('faction_endgame_scores')) {
                return [];
            }
            $q = $db->table('faction_endgame_scores')->select('faction_id, score')->get();
            if ($q === false) {
                return [];
            }
            $out = [];
            foreach ($q->getResultArray() as $r) {
                $fid = is_numeric($r['faction_id'] ?? null) ? (int) $r['faction_id'] : null;
                $sc  = is_numeric($r['score'] ?? null) ? (int) $r['score'] : 0;
                if ($fid !== null) {
                    $out[$fid] = $sc;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string> faction_id => name
     */
    private function factionLabels(): array
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('factions')) {
                return [];
            }
            $q = $db->table('factions')->select('id, name')->get();
            if ($q === false) {
                return [];
            }
            $out = [];
            foreach ($q->getResultArray() as $r) {
                $fid = is_numeric($r['id'] ?? null) ? (int) $r['id'] : null;
                $nm  = is_string($r['name'] ?? null) ? $r['name'] : '';
                if ($fid !== null && $nm !== '') {
                    $out[$fid] = $nm;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function outcomeLabel(int $marketId, string $code): string
    {
        $o = (new OracleOutcomeModel())->where('market_id', $marketId)->where('code', $code)->first();

        return is_array($o) && is_string($o['label'] ?? null) && $o['label'] !== '' ? $o['label'] : $code;
    }

    /**
     * @param array<string,mixed> $market
     */
    private function snapshotFinal(array $market): ?string
    {
        $metric = is_string($market['metric_key'] ?? null) ? $market['metric_key'] : '';
        if ($metric === 'faction_race') {
            $j = json_encode($this->factionScores());

            return $j === false ? null : $j;
        }

        return null;
    }

    private function telegramIdForCharacter(int $charId): ?int
    {
        $db = \Config\Database::connect();
        $q  = $db->table('characters')
            ->select('telegram_users.telegram_id AS tg')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.id', $charId)
            ->get();
        if ($q === false) {
            return null;
        }
        $row = $q->getRowArray();

        return is_array($row) && is_numeric($row['tg'] ?? null) ? (int) $row['tg'] : null;
    }
}
