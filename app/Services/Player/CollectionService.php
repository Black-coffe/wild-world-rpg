<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterResourceModel;
use App\Services\GameSettings\GameSettingsService;
use Config\Database;
use Throwable;

/**
 * E19 (ADR-119, гейт L50) — коллекции + музей базы.
 *
 * Долгая горизонтальная цель собирательства: игрок СДАЁТ редкие предметы (реликвии/север/легендарные
 * находки) в слоты коллекции — предмет списывается навсегда (item-sink, эконом-инвариант ADR-113).
 * За полный сет — косметический титул-награда (реюз {@see TitleService}, source_type='collection').
 *
 * Зеркало {@see AchievementService}/{@see TitleService} (Database-билдер, killswitch, rarity-%).
 * Killswitch `collections.enabled` (default false → dormant). Гейт `collections.min_level` (default 50).
 * Класс НЕ final, методы overridable (seam для тестов: deduct/awardTitle/charLevel).
 */
class CollectionService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch всей системы. default false (dormant на ship). */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('collections.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Уровень доступа к музею (гейт L50). */
    public function minLevel(): int
    {
        $v = $this->settings->get('collections.min_level', 50);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 50;
    }

    /**
     * Включённые коллекции по sort_order.
     *
     * @return list<array<int|string,mixed>>
     */
    public function definitions(): array
    {
        $db   = Database::connect();
        $rows = $db->table('collections')->where('enabled', 1)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get();
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
     * Слоты коллекции по sort_order.
     *
     * @return list<array<int|string,mixed>>
     */
    public function slotsFor(int $collectionId): array
    {
        $db   = Database::connect();
        $rows = $db->table('collection_slots')->where('collection_id', $collectionId)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get();
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
     * Одна коллекция по id (или null), если включена.
     *
     * @return array<int|string,mixed>|null
     */
    public function collectionById(int $collectionId): ?array
    {
        $db  = Database::connect();
        $r   = $db->table('collections')->where('id', $collectionId)->where('enabled', 1)->get();
        $row = $r === false ? null : $r->getRowArray();
        return is_array($row) ? $row : null;
    }

    /**
     * ID слотов, заполненных персонажем (по всем коллекциям).
     *
     * @return list<int>
     */
    public function filledSlotIds(int $characterId): array
    {
        $db   = Database::connect();
        $rows = $db->table('character_collection_slots')->select('collection_slot_id')->where('character_id', $characterId)->get();
        if ($rows === false) {
            return [];
        }
        $ids = [];
        foreach ($rows->getResultArray() as $row) {
            $raw = $row['collection_slot_id'] ?? null;
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    /** Сколько единиц ресурса (по имени) есть у персонажа. */
    public function heldQty(int $characterId, string $resourceName): int
    {
        $row = (new CharacterResourceModel())->getResourceForCraft($resourceName, $characterId);
        $raw = is_array($row) ? ($row['charResQty'] ?? 0) : 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** Коллекция завершена персонажем (все слоты заполнены, слотов > 0). */
    public function isComplete(int $characterId, int $collectionId): bool
    {
        $total = count($this->slotsFor($collectionId));
        if ($total === 0) {
            return false;
        }
        $filled = $this->filledSlotIds($characterId);
        foreach ($this->slotsFor($collectionId) as $slot) {
            $sid = is_numeric($slot['id'] ?? null) ? (int) $slot['id'] : 0;
            if (! in_array($sid, $filled, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Сдать слот: списать required_qty ресурса и заполнить слот навсегда.
     * Возвращает структуру результата (reason: disabled|no_slot|level|already|insufficient|'').
     *
     * @return array{ok:bool, reason:string, slot?:array<int|string,mixed>, collectionCompleted?:bool, titleAwarded?:?string}
     */
    public function donate(int $characterId, int $slotId): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        $db = Database::connect();

        // Слот + его коллекция (join).
        $q = $db->query(
            'SELECT s.id AS slot_id, s.collection_id, s.resource_name, s.required_qty, '
            . 'c.collection_key, c.name AS collection_name, c.min_level, c.reward_title_key '
            . 'FROM collection_slots s JOIN collections c ON c.id = s.collection_id '
            . 'WHERE s.id = ? AND c.enabled = 1',
            [$slotId]
        );
        $slot = is_object($q) && method_exists($q, 'getRowArray') ? $q->getRowArray() : null;
        if (! is_array($slot)) {
            return ['ok' => false, 'reason' => 'no_slot'];
        }

        $minLevel = is_numeric($slot['min_level'] ?? null) ? (int) $slot['min_level'] : $this->minLevel();
        if ($this->characterLevel($characterId) < $minLevel) {
            return ['ok' => false, 'reason' => 'level', 'slot' => $slot];
        }

        // Уже заполнен?
        $exists = $db->table('character_collection_slots')
            ->where('character_id', $characterId)->where('collection_slot_id', $slotId)->countAllResults();
        if ($exists > 0) {
            return ['ok' => false, 'reason' => 'already', 'slot' => $slot];
        }

        $resourceName = is_string($slot['resource_name'] ?? null) ? $slot['resource_name'] : '';
        $qty          = is_numeric($slot['required_qty'] ?? null) ? max(1, (int) $slot['required_qty']) : 1;

        // Атомарное списание (false при нехватке).
        if (! $this->deductResource($resourceName, $characterId, $qty)) {
            return ['ok' => false, 'reason' => 'insufficient', 'slot' => $slot];
        }

        try {
            $db->table('character_collection_slots')->insert([
                'character_id'       => $characterId,
                'collection_slot_id' => $slotId,
                'donated_at'         => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Гонка: UNIQUE поймал — ресурс уже списан, считаем слот заполненным.
        }

        $collectionId = is_numeric($slot['collection_id'] ?? null) ? (int) $slot['collection_id'] : 0;
        $completed    = $collectionId > 0 && $this->isComplete($characterId, $collectionId);
        $titleAwarded = null;
        $rewardKey    = is_string($slot['reward_title_key'] ?? null) ? $slot['reward_title_key'] : '';
        if ($completed && $rewardKey !== '') {
            $titleAwarded = $this->awardRewardTitle($characterId, $rewardKey);
        }

        return [
            'ok'                  => true,
            'reason'              => '',
            'slot'                => $slot,
            'collectionCompleted' => $completed,
            'titleAwarded'        => $titleAwarded,
        ];
    }

    /**
     * Карта collection_id → % персонажей, завершивших коллекцию (редкость, паттерн ADR-100).
     *
     * @return array<int,float>
     */
    public function rarityPercentMap(): array
    {
        $db    = Database::connect();
        $totQ  = $db->table('characters')->countAllResults();
        $total = is_int($totQ) ? $totQ : 0;

        $map = [];
        foreach ($this->definitions() as $c) {
            $cid       = is_numeric($c['id'] ?? null) ? (int) $c['id'] : 0;
            $slotTotal = $cid > 0 ? count($this->slotsFor($cid)) : 0;
            if ($cid <= 0 || $slotTotal === 0 || $total === 0) {
                if ($cid > 0) {
                    $map[$cid] = 0.0;
                }
                continue;
            }
            // Сколько персонажей заполнили ВСЕ слоты этой коллекции.
            $q = $db->query(
                'SELECT COUNT(*) AS c FROM ('
                . 'SELECT ccs.character_id FROM character_collection_slots ccs '
                . 'JOIN collection_slots cs ON cs.id = ccs.collection_slot_id '
                . 'WHERE cs.collection_id = ? GROUP BY ccs.character_id HAVING COUNT(*) >= ?'
                . ') t',
                [$cid, $slotTotal]
            );
            $row       = is_object($q) && method_exists($q, 'getRowArray') ? $q->getRowArray() : null;
            $completed = is_array($row) && is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
            $map[$cid] = round(100.0 * $completed / $total, 1);
        }
        return $map;
    }

    // === Overridable seams (тесты подменяют без БД/моделей) ===

    /** Текущий уровень персонажа. */
    protected function characterLevel(int $characterId): int
    {
        $r   = Database::connect()->table('characters')->select('level')->where('id', $characterId)->get();
        $row = $r === false ? null : $r->getRowArray();
        $raw = is_array($row) ? ($row['level'] ?? 0) : 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** Атомарное списание ресурса по имени (false при нехватке). */
    protected function deductResource(string $resourceName, int $characterId, int $qty): bool
    {
        return (new CharacterResourceModel())->deductResourceByName($resourceName, $characterId, $qty);
    }

    /** Выдать титул-награду по title_key; вернуть имя титула, если он у персонажа теперь есть. */
    protected function awardRewardTitle(int $characterId, string $titleKey): ?string
    {
        $db  = Database::connect();
        $r   = $db->table('titles')->select('id, name')->where('title_key', $titleKey)->where('enabled', 1)->get();
        $row = $r === false ? null : $r->getRowArray();
        if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
            return null;
        }
        $titleId = (int) $row['id'];
        (new TitleService())->award($characterId, $titleId);
        return is_string($row['name'] ?? null) ? $row['name'] : null;
    }
}
