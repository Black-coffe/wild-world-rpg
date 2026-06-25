<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Entities\CharacterEntity;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\MapModel;
use App\Models\WorldObjectModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Request;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — cold-open «нарратив радио-крючок + bait у спавна»
 * (спина-слайс 3).
 *
 * Genuine gap (audit S4): первый-ход ПЕЙОФФ уже жив (lucky-find/starter-kit), но НАПРАВЛЕННОГО
 * крючка «там, в той стороне, что-то есть → иди возьми» нет. `ObjectSignalService` (ADR-098)
 * march-only + таргетит эндгейм-объекты (L12-20), L1-новичку недостижим. Слайс кладёт
 * детерминированно ДОСТИЖИМУЮ приманку (bait) рядом со спавном (reactive — по фактической клетке,
 * т.к. спавн = случайная клетка Y≥900), отмечает её 🎯 на текстовой карте и даёт Роби-нарратив
 * «ловлю сигнал». Новичок идёт к 🎯 (это попутно закрывает живой OnbStep Move «пройди 3 клетки»)
 * → на клетке авто-забирает находку.
 *
 * Хранилище — реюз `biome_world_object_map` (status active→cleared) + один seed-anchor
 * `world_objects` row `OnbSignalCache` (FK). Награда читается из GameSettings (как lucky-find).
 * Дискавери НЕ через ObjectDiscoveryService (тот march-only) — собственный arrival-check
 * {@see tryReachBait()} в одиночном ходе (зеркало хука lucky-find).
 *
 * Гейты: суб-killswitch `onboarding.cold_open_v2.signal_hook` (default OFF → все 3 хука no-op =
 * byte-identical) + level ≤ `onboarding.contextual_hints.max_level` (реюз, default 6). ОТДЕЛЬНЫЙ
 * от мастер-флага 1a и прочих суб-флагов спины — свой Tier-3 + активация.
 *
 * MEDIA-OFF (ADR-020): и радио-нарратив, и сообщение находки — текстовые, весь смысл в тексте.
 *
 * Overridable seam'ы (тесты без БД на CI): enabled / maxLevel / gold / resourceId / resourceQty /
 * distance / anchorObjectId / findLandCell / insertBait / activeBaitAt / clearBait / grantGold /
 * grantResource / resourceTitle / send.
 *
 * @see [[ADR-139-Cold-open-polar-star]]
 */
class ColdOpenSignalService
{
    use GameSettingsReaderTrait;

    /** world_objects.name_en seed-anchor приманки (фильтр маркера + arrival-check). */
    public const BAIT_NAME_EN = 'OnbSignalCache';

    /**
     * Кандидаты направлений приманки (dx, dy, ru-label, эмодзи-стрелка). Юг исключён —
     * спавн у южной кромки (Y≥900), юг = край/угол. Север = «к миру», тематично для сигнала.
     *
     * @var list<array{0:int,1:int,2:string,3:string}>
     */
    private const DIRECTION_CANDIDATES = [
        [0, -1, 'к северу', '⬆️'],
        [1, -1, 'к северо-востоку', '↗️'],
        [-1, -1, 'к северо-западу', '↖️'],
        [1, 0, 'к востоку', '➡️'],
        [-1, 0, 'к западу', '⬅️'],
    ];

    /** Допустимые биомы для клетки приманки (та же суша, что спавн; 4 = вода исключена). */
    private const ALLOWED_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    public function __construct(
        private readonly ?BiomeWorldObjectMapModel $bwom = null,
        private readonly ?WorldObjectModel $worldObjects = null,
        private readonly ?MapModel $map = null,
        private readonly ?CharacterModel $characters = null,
        private readonly ?CharacterResourceModel $resources = null,
    ) {
    }

    // ── Хук 1: размещение приманки у спавна (StartCommand, ветка нового чара) ──

    /**
     * Разместить приманку рядом со спавном нового чара и вернуть текст радио-нарратива Роби
     * (или null, если выключено / не нашлось валидной клетки / нет anchor-объекта).
     *
     * @param array<int|string, mixed> $spawnCell map-row спавна (coordinate_x/y, id, biome_id)
     */
    public function placeBaitForNewChar(int $charId, array $spawnCell): ?string
    {
        if ($charId <= 0 || ! $this->enabled()) {
            return null;
        }

        $anchorId = $this->anchorObjectId();
        if ($anchorId <= 0) {
            return null;
        }

        $spawnX = self::intVal($spawnCell, 'coordinate_x');
        $spawnY = self::intVal($spawnCell, 'coordinate_y');
        $dist   = $this->distance();

        foreach (self::DIRECTION_CANDIDATES as [$dx, $dy, $label, $arrow]) {
            $tx = $spawnX + $dx * $dist;
            $ty = $spawnY + $dy * $dist;
            if ($tx < 0 || $tx > 999 || $ty < 0 || $ty > 999) {
                continue;
            }
            $landCell = $this->findLandCell($tx, $ty);
            if ($landCell === null) {
                continue;
            }

            $this->insertBait($anchorId, $landCell);

            return $this->buildSignalNarrative($label, $arrow);
        }

        return null;
    }

    // ── Хук 2: 🎯-маркер активных приманок в видимой области (TextMapService) ──

    /**
     * Клетки с активной приманкой в видимой 12×12 области → ["{x}_{y}" => true]. Пусто, если
     * выключено / ветеран (level > cap) — карта рендерится byte-identical.
     *
     * @return array<string, true>
     */
    public function markerCellsInViewport(int $level, int $xMin, int $xMax, int $yMin, int $yMax): array
    {
        if (! $this->enabled() || $level > $this->maxLevel()) {
            return [];
        }

        return $this->baitMarkerCells($xMin, $xMax, $yMin, $yMax);
    }

    // ── Хук 3: забрать приманку на её клетке (MoveCharacterToDirectionAction) ──

    /**
     * Если на клетке (post-move) активная приманка — выдать награду, послать сообщение находки,
     * пометить cleared. Возвращает true при выдаче (иначе no-op).
     *
     * @param array<string, mixed>|CharacterEntity $character
     * @param array<int|string, mixed>             $cell map-row клетки, где сейчас чар (id)
     */
    public function tryReachBait(array|CharacterEntity $character, array $cell, int $chatId): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        if (self::intField($character, 'level') > $this->maxLevel()) {
            return false;
        }
        $charId = self::intField($character, 'id');
        $cellId = self::intVal($cell, 'id');
        if ($charId <= 0 || $cellId <= 0) {
            return false;
        }

        $baitMapRowId = $this->activeBaitAt($cellId);
        if ($baitMapRowId <= 0) {
            return false;
        }

        $gold = $this->gold();
        $resId = $this->resourceId();
        $resQty = $this->resourceQty();

        if ($gold > 0) {
            $this->grantGold($charId, $gold);
        }
        if ($resId > 0 && $resQty > 0) {
            $this->grantResource($charId, $resId, $resQty);
        }

        $this->clearBait($baitMapRowId);
        $this->send([
            'chat_id'                  => $chatId,
            'text'                     => $this->buildFoundText($gold, $resId, $resQty),
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]);

        return true;
    }

    // ── Overridable seam'ы (настройки) ───────────────────────────────────────

    protected function enabled(): bool
    {
        return $this->gsBool('onboarding.cold_open_v2.signal_hook', false);
    }

    protected function maxLevel(): int
    {
        return $this->gsInt('onboarding.contextual_hints.max_level', 6);
    }

    protected function gold(): int
    {
        return max(0, $this->gsInt('onboarding.cold_open_v2.bait_gold', 200));
    }

    protected function resourceId(): int
    {
        return max(0, $this->gsInt('onboarding.cold_open_v2.bait_resource_id', 2));
    }

    protected function resourceQty(): int
    {
        return max(0, $this->gsInt('onboarding.cold_open_v2.bait_resource_qty', 5));
    }

    protected function distance(): int
    {
        return max(1, $this->gsInt('onboarding.cold_open_v2.bait_distance', 3));
    }

    // ── Overridable seam'ы (БД) ──────────────────────────────────────────────

    /** id seed-anchor `OnbSignalCache` в world_objects (0, если не засеян). */
    protected function anchorObjectId(): int
    {
        $row = $this->worldObjectModel()->where('name_en', self::BAIT_NAME_EN)->first();

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    /**
     * Найти map-row суши по координатам (allowed biome), или null.
     *
     * @return array<int|string, mixed>|null
     */
    protected function findLandCell(int $x, int $y): ?array
    {
        $row = $this->mapModel()
            ->where('coordinate_x', $x)
            ->where('coordinate_y', $y)
            ->whereIn('biome_id', self::ALLOWED_BIOMES)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Вставить строку приманки в biome_world_object_map (active, single_use).
     *
     * @param array<int|string, mixed> $cell map-row клетки-цели (id, biome_id)
     */
    protected function insertBait(int $anchorId, array $cell): void
    {
        $this->bwomModel()->insert([
            'biome_id'        => self::intVal($cell, 'biome_id'),
            'world_object_id' => $anchorId,
            'map_id'          => self::intVal($cell, 'id'),
            'status'          => 'active',
            'object_type'     => 'single_use',
        ]);
    }

    /**
     * Клетки активных приманок в bounds (join map для coordinate_x/y) → ["{x}_{y}" => true].
     *
     * @return array<string, true>
     */
    protected function baitMarkerCells(int $xMin, int $xMax, int $yMin, int $yMax): array
    {
        $rows = $this->bwomModel()
            ->select('m.coordinate_x AS x, m.coordinate_y AS y')
            ->join('world_objects wo', 'wo.id = biome_world_object_map.world_object_id')
            ->join('map m', 'm.id = biome_world_object_map.map_id')
            ->where('wo.name_en', self::BAIT_NAME_EN)
            ->where('biome_world_object_map.status', 'active')
            ->where('m.coordinate_x >=', $xMin)
            ->where('m.coordinate_x <=', $xMax)
            ->where('m.coordinate_y >=', $yMin)
            ->where('m.coordinate_y <=', $yMax)
            ->findAll();

        $cells = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $x = self::intVal($row, 'x');
            $y = self::intVal($row, 'y');
            $cells["{$x}_{$y}"] = true;
        }

        return $cells;
    }

    /** id строки biome_world_object_map активной приманки на клетке (map_id), или 0. */
    protected function activeBaitAt(int $cellId): int
    {
        $row = $this->bwomModel()
            ->select('biome_world_object_map.id')
            ->join('world_objects wo', 'wo.id = biome_world_object_map.world_object_id')
            ->where('wo.name_en', self::BAIT_NAME_EN)
            ->where('biome_world_object_map.map_id', $cellId)
            ->where('biome_world_object_map.status', 'active')
            ->first();

        return is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    protected function clearBait(int $baitMapRowId): void
    {
        $this->bwomModel()->updateStatus($baitMapRowId, 'cleared');
    }

    protected function grantGold(int $charId, int $gold): void
    {
        ($this->characters ?? new CharacterModel())->increaseGold($charId, (float) $gold);
    }

    protected function grantResource(int $charId, int $resourceId, int $amount): void
    {
        ($this->resources ?? new CharacterResourceModel())->addOrIncreaseResource($charId, $resourceId, $amount);
    }

    /**
     * Имя ресурса по id (для текста находки). Известные стартовые ресурсы — по карте (без БД-запроса
     * на рендере находки); прочие id → нейтральное «припасы». Совпадает с набором StarterKitService.
     */
    protected function resourceTitle(int $resourceId): string
    {
        $titles = [17 => 'Вода', 2 => 'Древесина', 8 => 'Кора деревьев', 19 => 'Галька'];

        return $titles[$resourceId] ?? 'припасы';
    }

    /**
     * Overridable seam отправки (тесты подменяют — без Telegram на CI).
     *
     * @param array<string, mixed> $payload
     */
    protected function send(array $payload): void
    {
        Request::sendMessage($payload);
    }

    // ── Текст (media-off, markdown-safe) ─────────────────────────────────────

    public function buildSignalNarrative(string $directionLabel, string $arrow): string
    {
        return "📡 *Сигнал в эфире.*\n\n"
            . "Роби: «Ловлю слабый сигнал {$directionLabel} {$arrow} отсюда — пара клеток, не больше. "
            . "Отметил источник на твоей карте: ищи метку 🎯. Дойдёшь — глянем, что там. "
            . "Может, кто-то припрятал и не вернулся.»\n\n"
            . "_Открой «🗺 Карту» и двигайся к метке 🎯._";
    }

    public function buildFoundText(int $gold, int $resourceId, int $resourceQty): string
    {
        $lines = '';
        if ($gold > 0) {
            $lines .= "• 🪙 Монеты ×{$gold}\n";
        }
        if ($resourceId > 0 && $resourceQty > 0) {
            $lines .= '• 📦 ' . $this->resourceTitle($resourceId) . " ×{$resourceQty}\n";
        }

        return "📡 *Источник найден!*\n\n"
            . "Сигнал привёл тебя к тайнику — кто-то спрятал и не вернулся.\n\n"
            . "Ты забрал:\n"
            . $lines . "\n"
            . "Пустошь полна таких сигналов — следи за эфиром.";
    }

    // ── Резолверы зависимостей / хелперы ─────────────────────────────────────

    private function bwomModel(): BiomeWorldObjectMapModel
    {
        return $this->bwom ?? new BiomeWorldObjectMapModel();
    }

    private function worldObjectModel(): WorldObjectModel
    {
        return $this->worldObjects ?? new WorldObjectModel();
    }

    private function mapModel(): MapModel
    {
        return $this->map ?? new MapModel();
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private static function intVal(array $row, string $key): int
    {
        $raw = $row[$key] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * @param array<string, mixed>|CharacterEntity $character
     */
    private static function intField(array|CharacterEntity $character, string $field): int
    {
        $raw = $character[$field] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
