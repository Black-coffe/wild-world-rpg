<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\SettlementModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Player\TeleportUse\TeleportExecutor;

/**
 * ADR-101 Фаза 5 — быстрое перемещение между ОТКРЫТЫМИ поселениями («связность карты»).
 *
 * Реюз `teleport_beacons`-идеи: поселение = публичный маяк (фикс. золото-стоимость + кулдаун),
 * но без записи в teleport_beacons — назначения и есть сами поселения. Движение через общий
 * {@see TeleportExecutor} (single-write cell_number+biome_id+gold). Туман войны на месте
 * прибытия — {@see ExploredCellsModel::revealAround}.
 *
 * Решения владельца (2026-06-07): старт ТОЛЬКО из поселения (settlement-to-settlement, сеть
 * «портов» = Rust-like связность); назначения — все ОТКРЫТЫЕ поселения КРОМЕ руин (тип 'ruins'
 * — эндгейм-лут по кулдауну, телепорт обесценил бы путь). «Открыто» ≡ anchor_cell в
 * explored_cells персонажа (ADR-101: «до открытия — обычный биом, надо дойти»; реюз тумана,
 * без новой таблицы визитов).
 *
 * Dormant-first (паттерн поселений): killswitch `settlements.teleport.enabled` (default OFF).
 * Стоимость/кулдаун — live-tunable GameSettings (ADR-024). Кулдаун — cache-based (как
 * ObjectSignalService), без новой колонки characters → WipeManifest не трогаем.
 *
 * Caption-самодостаточность (media-off) обеспечивает action-слой; сервис — чистый домен,
 * Telegram не шлёт (тестируемо).
 */
final class SettlementTeleportService
{
    use GameSettingsReaderTrait;

    public const KEY_ENABLED          = 'settlements.teleport.enabled';
    public const KEY_COST             = 'settlements.teleport.cost';
    public const KEY_COOLDOWN_MINUTES = 'settlements.teleport.cooldown_minutes';

    public const DEFAULT_COST             = 500;
    public const DEFAULT_COOLDOWN_MINUTES = 60;

    /** Эти типы поселений НЕ являются назначением телепорта. */
    private const EXCLUDED_TYPES = ['ruins'];

    private const CACHE_PREFIX = 'settle_tp_last_';

    private SettlementModel $settlements;
    private ExploredCellsModel $explored;
    private CharacterModel $characters;
    private MapModel $map;
    private TeleportExecutor $executor;

    public function __construct(
        ?SettlementModel $settlements = null,
        ?ExploredCellsModel $explored = null,
        ?CharacterModel $characters = null,
        ?MapModel $map = null,
        ?TeleportExecutor $executor = null
    ) {
        $this->settlements = $settlements ?? new SettlementModel();
        $this->explored    = $explored ?? new ExploredCellsModel();
        $this->characters  = $characters ?? new CharacterModel();
        $this->map         = $map ?? new MapModel();
        $this->executor    = $executor ?? new TeleportExecutor($this->characters);
    }

    /** Включён ли быстрый телепорт между поселениями (killswitch, default OFF). */
    public function enabled(): bool
    {
        return $this->gsBool(self::KEY_ENABLED, false);
    }

    /** Стоимость одного перехода в золоте (live-tunable). */
    public function cost(): int
    {
        $c = $this->gsInt(self::KEY_COST, self::DEFAULT_COST);

        return $c >= 0 ? $c : 0;
    }

    /** Кулдаун между переходами в минутах (live-tunable). */
    public function cooldownMinutes(): int
    {
        $m = $this->gsInt(self::KEY_COOLDOWN_MINUTES, self::DEFAULT_COOLDOWN_MINUTES);

        return $m >= 0 ? $m : 0;
    }

    /**
     * Открытые поселения-назначения для персонажа: активные (enabled=1), НЕ руины, НЕ текущее,
     * у которых anchor_cell раскрыта туманом войны (explored_cells). Чистая выборка для UI.
     *
     * @return list<array<int|string,mixed>>
     */
    public function openDestinations(int $characterId, int $excludeSettlementId): array
    {
        $candidates = [];
        $anchors    = [];
        foreach ($this->settlements->allActive() as $s) {
            $sid  = is_numeric($s['id'] ?? null) ? (int) $s['id'] : 0;
            $type = is_string($s['type'] ?? null) ? $s['type'] : '';
            $anch = is_numeric($s['anchor_cell'] ?? null) ? (int) $s['anchor_cell'] : 0;
            if ($sid === $excludeSettlementId || $anch <= 0 || in_array($type, self::EXCLUDED_TYPES, true)) {
                continue;
            }
            $candidates[$anch] = $s;
            $anchors[]         = $anch;
        }
        if ($anchors === []) {
            return [];
        }

        $openAnchors = $this->exploredAnchors($characterId, $anchors);

        $out = [];
        foreach ($candidates as $anch => $s) {
            if (isset($openAnchors[$anch])) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * true если конкретное поселение открыто персонажу (для валидации перехода).
     *
     * @param array<int|string,mixed> $settlement
     */
    public function isOpenForCharacter(int $characterId, array $settlement): bool
    {
        $anch = $this->intOf($settlement['anchor_cell'] ?? null);
        $type = is_string($settlement['type'] ?? null) ? $settlement['type'] : '';
        if ($anch <= 0 || in_array($type, self::EXCLUDED_TYPES, true)) {
            return false;
        }

        return isset($this->exploredAnchors($characterId, [$anch])[$anch]);
    }

    /** Остаток кулдауна в секундах (0 — можно телепортироваться). */
    public function cooldownRemainingSeconds(int $characterId): int
    {
        $last = cache(self::CACHE_PREFIX . $characterId);
        if (! is_numeric($last)) {
            return 0;
        }
        $elapsed   = time() - (int) $last;
        $remaining = ($this->cooldownMinutes() * 60) - $elapsed;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Выполнить переход в поселение по id. Полная валидация (killswitch, открыто/не руины,
     * кулдаун, золото), затем атомарный move (TeleportExecutor) + туман + взвод кулдауна.
     *
     * @return array{success:bool, message:string, settlement?:array<int|string,mixed>, cost?:int}
     */
    public function teleportTo(int $characterId, int $settlementId, int $currentSettlementId): array
    {
        if (! $this->enabled()) {
            return ['success' => false, 'message' => 'Быстрое перемещение сейчас недоступно.'];
        }

        $dest = $this->rowArray($this->settlements->find($settlementId));
        if ($dest === null || $this->intOf($dest['enabled'] ?? null) !== 1) {
            return ['success' => false, 'message' => 'Это поселение недоступно для перехода.'];
        }
        if ($this->intOf($dest['id'] ?? null) === $currentSettlementId) {
            return ['success' => false, 'message' => 'Ты уже здесь.'];
        }
        if (! $this->isOpenForCharacter($characterId, $dest)) {
            return ['success' => false, 'message' => 'Это место ещё не открыто — сначала дойди до него.'];
        }

        $remaining = $this->cooldownRemainingSeconds($characterId);
        if ($remaining > 0) {
            $min = (int) ceil($remaining / 60);
            return ['success' => false, 'message' => "Переход на кулдауне — подожди ещё ~{$min} мин."];
        }

        $charRaw = $this->characters->find($characterId);
        $char    = $this->toArray($charRaw);
        if ($char === null) {
            return ['success' => false, 'message' => 'Персонаж не найден.'];
        }
        $gold = $this->intOf($char['gold'] ?? null);
        $cost = $this->cost();
        if ($gold < $cost) {
            return ['success' => false, 'message' => "Недостаточно золота: нужно {$cost} 💰, а есть {$gold}."];
        }

        $anchor = $this->intOf($dest['anchor_cell'] ?? null);
        $mapRow = $this->rowArray($this->map->find($anchor));
        if ($mapRow === null) {
            return ['success' => false, 'message' => 'Не найдена клетка назначения.'];
        }
        $biomeId = $this->intOf($mapRow['biome_id'] ?? null);

        // Атомарный перенос (cell_number + biome_id + списание золота одним апдейтом).
        $additional = $cost > 0 ? ['gold' => $gold - $cost] : [];
        $this->executor->teleport(
            $characterId,
            ['map_cell_id' => $anchor],
            ['biome_id' => $biomeId],
            $additional
        );

        // Туман войны на месте прибытия (как при шаге/марше/телепорте).
        $tgId = $this->intOf($char['telegram_user_id'] ?? null);
        $dx   = $this->nullableIntOf($dest['coordinate_x'] ?? null);
        $dy   = $this->nullableIntOf($dest['coordinate_y'] ?? null);
        if ($tgId > 0 && $dx !== null && $dy !== null) {
            $level = $this->nullableIntOf($char['level'] ?? null);
            $this->explored->revealAround($characterId, $tgId, $dx, $dy, $level);
        }

        $this->markCooldown($characterId);

        return ['success' => true, 'message' => 'ok', 'settlement' => $dest, 'cost' => $cost];
    }

    /**
     * Нормализация строки персонажа к массиву (CharacterModel возвращает CharacterEntity).
     *
     * @return array<int|string,mixed>|null
     */
    private function toArray(mixed $char): ?array
    {
        if ($char instanceof \App\Entities\CharacterEntity) {
            return $char->toArray();
        }

        return is_array($char) ? $char : null;
    }

    /**
     * Нормализация строки модели (Model::find union) к массиву или null.
     *
     * @return array<int|string,mixed>|null
     */
    private function rowArray(mixed $row): ?array
    {
        return is_array($row) ? $row : null;
    }

    /** Безопасное приведение mixed→int (0 если не число). */
    private function intOf(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    /** Безопасное приведение mixed→?int (null если не число). */
    private function nullableIntOf(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /** Взвести кулдаун перехода (cache, TTL = кулдаун). */
    private function markCooldown(int $characterId): void
    {
        $ttl = $this->cooldownMinutes() * 60;
        if ($ttl > 0) {
            cache()->save(self::CACHE_PREFIX . $characterId, time(), $ttl);
        }
    }

    /**
     * Подмножество якорей, раскрытых туманом войны для персонажа.
     *
     * @param list<int> $anchors
     * @return array<int,true>
     */
    private function exploredAnchors(int $characterId, array $anchors): array
    {
        if ($anchors === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($anchors), '?'));
        $query        = $this->explored->db->query(
            "SELECT DISTINCT map_cell_id FROM explored_cells WHERE character_id = ? AND map_cell_id IN ({$placeholders})",
            array_merge([$characterId], $anchors)
        );
        $open = [];
        if ($query instanceof \CodeIgniter\Database\BaseResult) {
            foreach ($query->getResultArray() as $r) {
                $open[(int) $r['map_cell_id']] = true;
            }
        }

        return $open;
    }
}
