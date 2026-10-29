<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\FactionProjectModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * V20 (ADR-051) — фракц-проект: асинхронный вклад золота членов фракции в общий
 * пул; при достижении порога — временный фракц-buff крафта для ВСЕХ членов.
 *
 * Async + опционально + population-robust (1 член копит соло-медленно, много —
 * быстро вместе). Чистый sink (золото) + общая цель фракции. Buff-множитель
 * читается в `calculateCraftingDuration` (цепочка building×food×spec×faction).
 * Нейтрал (faction_id=5) и без фракции исключены.
 */
final class FactionProjectService
{
    /**
     * 🔴 ЕДИНАЯ подпись кнопки входа в проект — и для хаба «Развитие», и для «⚙️ Ещё», и для
     * услуги в оплоте, и для любого текста, который эту кнопку называет.
     *
     * Зачем (аудит путей 2026-09-05): у одной двери было ЧЕТЫРЕ эмодзи — «🤝» в хабе Развития
     * и в шапке самого экрана, «💎» в «⚙️ Ещё» и в поселении-оплоте, «🏗» в /guide. Игрок ищет
     * кнопку глазами по эмодзи, поэтому разнобой читается как разные фичи. Канон — «🤝»: кнопка
     * обязана называться так же, как экран, который она открывает
     * ({@see \App\Controllers\Telegram\Commands\Actions\Faction\FactionProjectBaseAction}).
     */
    public const BUTTON_LABEL = '🤝 Проект фракции';

    /** Играбельные фракции (Нейтрал=5 исключён). */
    private const MIN_FACTION = 1;
    private const MAX_FACTION = 4;

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    // ── GameSettings readers ─────────────────────────────────────────────────

    public function enabled(): bool
    {
        $v = $this->settings->get('faction.project.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function threshold(): int
    {
        return max(1, $this->intSetting('faction.project.threshold', 20000));
    }

    public function depositGold(): int
    {
        return max(1, $this->intSetting('faction.project.deposit_gold', 1000));
    }

    public function buffDurationHours(): int
    {
        return max(1, $this->intSetting('faction.project.buff_duration_hours', 24));
    }

    public function craftTimeMultiplier(): float
    {
        $v = $this->floatSetting('faction.project.craft_time_multiplier', 0.90);
        return $v > 0 ? $v : 1.0;
    }

    // ── faction lookup ───────────────────────────────────────────────────────

    /** faction_id персонажа (1-4) или 0 (нет фракции / Нейтрал). */
    public function factionIdFor(int $characterId): int
    {
        $db    = Database::connect();
        $query = $db->table('character_factions')->where('character_id', $characterId)->get();
        $row   = $query !== false ? $query->getRowArray() : null;
        $fid   = is_array($row) && isset($row['faction_id']) && is_numeric($row['faction_id']) ? (int) $row['faction_id'] : 0;
        return ($fid >= self::MIN_FACTION && $fid <= self::MAX_FACTION) ? $fid : 0;
    }

    /**
     * Проект фракции (создаёт пустой, если ещё нет).
     *
     * @return array<array-key,mixed>
     */
    public function getOrCreateProject(int $factionId): array
    {
        $model = new FactionProjectModel();
        $row   = $model->where('faction_id', $factionId)->first();
        if (is_array($row)) {
            return $row;
        }
        $model->insert(['faction_id' => $factionId, 'progress' => 0, 'cycles_completed' => 0]);
        $fresh = $model->where('faction_id', $factionId)->first();
        return is_array($fresh) ? $fresh : ['id' => 0, 'faction_id' => $factionId, 'progress' => 0, 'buff_until' => null, 'cycles_completed' => 0];
    }

    // ── deposit ──────────────────────────────────────────────────────────────

    /**
     * Внести фиксированный вклад золота. Транзакция: списание + progress; при
     * достижении порога — активация buff + reset (carry) + cycles++.
     *
     * @return array{ok:bool, message:string, completed:bool, progress:int, threshold:int, factionId:int, deposit:int}
     */
    public function deposit(int $characterId): array
    {
        $fail = static function (string $m): array {
            return ['ok' => false, 'message' => $m, 'completed' => false, 'progress' => 0, 'threshold' => 0, 'factionId' => 0, 'deposit' => 0];
        };

        if (! $this->enabled()) {
            return $fail('Проект фракции сейчас недоступен.');
        }
        $factionId = $this->factionIdFor($characterId);
        if ($factionId === 0) {
            return $fail('Вносить вклад в проект могут только члены фракции (выбери фракцию на L10).');
        }

        $deposit = $this->depositGold();
        $char    = (new CharacterModel())->find($characterId);
        $gold    = is_array($char) || $char instanceof \App\Entities\CharacterEntity
            ? (is_numeric($char['gold'] ?? null) ? (int) $char['gold'] : 0)
            : 0;
        if ($gold < $deposit) {
            return $fail("Недостаточно золота: нужно {$deposit}, есть {$gold}.");
        }

        $threshold = $this->threshold();
        $project   = $this->getOrCreateProject($factionId);
        $projId    = isset($project['id']) && is_numeric($project['id']) ? (int) $project['id'] : 0;
        if ($projId === 0) {
            return $fail('Ошибка проекта фракции. Попробуй ещё раз.');
        }
        // s2-conditional-writes-03 (F9, ADR-181): золото — ОДНОЙ условной записью ДО вклада; прогресс —
        // относительным increment, а не «снимок + вклад». Раньше `decrement('gold')` шёл безусловно, а
        // progress писался абсолютным числом от прочитанного снимка: при золоте, ушедшем между проверкой
        // и оплатой, баланс уходил в минус, а два параллельных вклада теряли один из них.
        $db          = Database::connect();
        $writer      = new ConditionalWriteService($db);
        $depthBefore = $db->transDepth;
        $db->transStart();

        try {
            $paid = $writer->decrementIfAtLeast('characters', $characterId, 'gold', $deposit);
            if ($paid !== WriteOutcome::Applied) {
                $db->transRollback();
                if ($depthBefore === 0) {
                    $db->resetTransStatus();
                }
                if ($paid === WriteOutcome::Missing) {
                    log_message('error', "[FactionProject] персонаж {$characterId} не найден при списании вклада");
                    return $fail('Ошибка при внесении вклада. Попробуй ещё раз.');
                }
                return $fail("Недостаточно золота: нужно {$deposit}, есть {$this->goldOf($characterId)}.");
            }

            if ($writer->increment('faction_projects', ['id' => $projId], 'progress', $deposit) !== WriteOutcome::Applied) {
                $db->transRollback();
                if ($depthBefore === 0) {
                    $db->resetTransStatus();
                }
                log_message('error', "[FactionProject] проект {$projId} фракции {$factionId} исчез при вкладе character {$characterId}");
                return $fail('Ошибка проекта фракции. Попробуй ещё раз.');
            }

            // Строка проекта уже под X-локом нашего UPDATE до конца транзакции — чтение ниже видит
            // текущий прогресс; в запись оно не попадает: завершение цикла — условный относительный UPDATE.
            $progress  = $this->progressOf($projId);
            $completed = false;
            if ($progress >= $threshold) {
                $prefixed = $db->prefixTable('faction_projects');
                $db->query(
                    "UPDATE {$prefixed} SET progress = progress - ?, cycles_completed = cycles_completed + 1, buff_until = ?, updated_at = ? WHERE id = ? AND progress >= ?",
                    [$threshold, date('Y-m-d H:i:s', time() + $this->buffDurationHours() * 3600), date('Y-m-d H:i:s'), $projId, $threshold]
                );
                $completed = $db->affectedRows() > 0;
                if ($completed) {
                    $progress -= $threshold;
                }
            }

            $committed = $db->transComplete();
            if (! $committed && $depthBefore === 0) {
                $db->resetTransStatus();
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            if ($depthBefore === 0) {
                $db->resetTransStatus();
            }
            throw $e;
        }

        if (! $committed) {
            log_message('error', "[FactionProject] транзакция упала для character {$characterId}, faction {$factionId}");
            return $fail('Ошибка при внесении вклада. Попробуй ещё раз.');
        }

        return [
            'ok' => true, 'message' => '', 'completed' => $completed,
            'progress' => $progress, 'threshold' => $threshold, 'factionId' => $factionId, 'deposit' => $deposit,
        ];
    }

    // ── buff read (для хуков/UI) ─────────────────────────────────────────────

    /** Множитель времени крафта для члена фракции (1.0 если буффа нет / выключено / нет фракции). */
    public function craftTimeMultiplierFor(int $characterId): float
    {
        if (! $this->enabled()) {
            return 1.0;
        }
        $factionId = $this->factionIdFor($characterId);
        if ($factionId === 0) {
            return 1.0;
        }
        return $this->buffActiveTs($factionId) !== null ? $this->craftTimeMultiplier() : 1.0;
    }

    /** Timestamp окончания активного буффа фракции или null. */
    public function buffActiveTs(int $factionId): ?int
    {
        $row = (new FactionProjectModel())->where('faction_id', $factionId)->first();
        if (! is_array($row)) {
            return null;
        }
        $bu = $row['buff_until'] ?? null;
        if (! is_string($bu) || $bu === '') {
            return null;
        }
        $ts = strtotime($bu);
        if ($ts === false || time() >= $ts) {
            return null;
        }
        return $ts;
    }

    /**
     * telegram_id всех членов фракции (для уведомления о завершении цикла).
     *
     * @return list<int>
     */
    public function factionMemberTelegramIds(int $factionId): array
    {
        $db    = Database::connect();
        $query = $db->table('character_factions cf')
            ->select('tu.telegram_id')
            ->join('characters c', 'c.id = cf.character_id')
            ->join('telegram_users tu', 'tu.id = c.telegram_user_id')
            ->where('cf.faction_id', $factionId)
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];

        $ids = [];
        foreach ($rows as $r) {
            if (isset($r['telegram_id']) && is_numeric($r['telegram_id'])) {
                $ids[] = (int) $r['telegram_id'];
            }
        }
        return $ids;
    }

    private function goldOf(int $characterId): int
    {
        $query = Database::connect()->table('characters')->select('gold')->where('id', $characterId)->get();
        $row   = $query !== false ? $query->getRowArray() : null;
        return is_array($row) && is_numeric($row['gold'] ?? null) ? (int) $row['gold'] : 0;
    }

    private function progressOf(int $projId): int
    {
        $query = Database::connect()->table('faction_projects')->select('progress')->where('id', $projId)->get();
        $row   = $query !== false ? $query->getRowArray() : null;
        return is_array($row) && is_numeric($row['progress'] ?? null) ? (int) $row['progress'] : 0;
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }
}
