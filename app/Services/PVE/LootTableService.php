<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharacterResourceModel;
use App\Repositories\CI4CharacterRepository;
use App\Services\Inventory\WeaponGrantService;
use Config\Database;

/**
 * ADR-107 — data-driven лут-таблицы (оживление мёртвой npcs.loot_table_id).
 *
 * При победе игрока над NPC, у которого есть loot_table_id со строками в
 * loot_table_items, прокатывает каждую строку (drop_chance% → qty min..max) и
 * выдаёт трофеи: ресурс / золото / оружие. Возвращает человекочитаемые строки
 * для сообщения боя (MEDIA-OFF: весь смысл в тексте).
 *
 * Killswitch `combat.loot_tables.enabled` (default false → no-op). Контент дропа —
 * в loot_table_items (как рецепты/NPC), не в GameSettings. Defensive per-строка:
 * ошибка одной выдачи не валит остальные и не роняет бой (caller тоже в try/catch).
 *
 * Первый потребитель — боссы ADR-106 (таблицы 701/702/703: rarity-1 ресурсы,
 * золото, шанс легендарного оружия). Рейдерские 501/502/601 пусты → no-op.
 *
 * Overridable seam'ы (тесты): isEnabled / rollPercent / rollQty.
 */
class LootTableService
{
    /**
     * Прокатить лут-таблицу и выдать трофеи персонажу.
     *
     * @return list<string> строки выданного («Сердце Дракона ×2», «⚔️ Коса Жатвы», «💰 +9000 золота»)
     */
    public function rollAndGrant(int $lootTableId, int $characterId): array
    {
        if ($lootTableId <= 0 || $characterId <= 0 || ! $this->isEnabled()) {
            return [];
        }

        $rows = Database::connect()->table('loot_table_items')
            ->where('loot_table_id', $lootTableId)
            ->orderBy('sort_order', 'ASC')
            ->get();
        $items = $rows instanceof \CodeIgniter\Database\ResultInterface ? $rows->getResultArray() : [];

        $lines = [];
        foreach ($items as $item) {
            try {
                $chanceRaw = $item['drop_chance'] ?? 100;
                $chance    = is_numeric($chanceRaw) ? (int) $chanceRaw : 100;
                if (! self::shouldDrop($this->rollPercent(), $chance)) {
                    continue;
                }

                $minRaw = $item['min_qty'] ?? 1;
                $maxRaw = $item['max_qty'] ?? 1;
                $qty    = $this->rollQty(
                    is_numeric($minRaw) ? (int) $minRaw : 1,
                    is_numeric($maxRaw) ? (int) $maxRaw : 1
                );
                if ($qty <= 0) {
                    continue;
                }

                $idRaw  = $item['item_id'] ?? null;
                $itemId = is_numeric($idRaw) ? (int) $idRaw : 0;
                $type   = is_string($item['item_type'] ?? null) ? $item['item_type'] : '';

                $line = match ($type) {
                    'resource' => $this->grantResource($characterId, $itemId, $qty),
                    'gold'     => $this->grantGold($characterId, $qty),
                    'weapon'   => $this->grantWeapon($characterId, $itemId),
                    default    => null,
                };
                if ($line !== null) {
                    $lines[] = $line;
                }
            } catch (\Throwable $e) {
                log_message('warning', 'LootTable: выдача строки лута не удалась (остальные продолжаются): ' . $e->getMessage());
            }
        }

        return $lines;
    }

    /** Выпадает ли строка: roll ∈ [1,100] ≤ chance. Чистый предикат (unit). */
    public static function shouldDrop(int $roll, int $chance): bool
    {
        if ($chance <= 0) {
            return false;
        }

        return $chance >= 100 || $roll <= $chance;
    }

    // ── Выдачи по типам ──────────────────────────────────────────────────────

    /**
     * Безопасно достать одну строку из builder'а.
     *
     * @return array<string,mixed>|null
     */
    private function rowOrNull(\CodeIgniter\Database\BaseBuilder $builder): ?array
    {
        $q = $builder->get();
        if (! $q instanceof \CodeIgniter\Database\ResultInterface) {
            return null;
        }
        $row = $q->getRowArray();

        return is_array($row) ? $row : null;
    }

    /** @return string|null строка трофея или null (ресурс не найден) */
    protected function grantResource(int $characterId, int $resourceId, int $qty): ?string
    {
        if ($resourceId <= 0) {
            return null;
        }
        $res = $this->rowOrNull(Database::connect()->table('resources')->where('id', $resourceId));
        if ($res === null) {
            return null;
        }
        (new CharacterResourceModel())->addOrIncreaseResource($characterId, $resourceId, $qty);
        $name = is_scalar($res['name'] ?? null) ? (string) $res['name'] : ('#' . $resourceId);

        return "{$name} ×{$qty}";
    }

    protected function grantGold(int $characterId, int $amount): string
    {
        (new CI4CharacterRepository())->adjustGold($characterId, (float) $amount);

        return "💰 +{$amount} золота";
    }

    /**
     * Оружие: upsert characters_weapons (quantity+1, полная прочность из определения).
     * Сама выдача — общий {@see WeaponGrantService} (H6.1: им же выдаёт стратегический лут).
     */
    protected function grantWeapon(int $characterId, int $weaponId): ?string
    {
        $weapon = (new WeaponGrantService())->grantById($characterId, $weaponId, 1);
        if ($weapon === null) {
            return null;
        }

        return '⚔️ ' . $weapon['name'] . ($weapon['rarity'] !== '' ? " ({$weapon['rarity']})" : '');
    }

    // ── Seam'ы (overridable в тестах) ────────────────────────────────────────

    /** Killswitch combat.loot_tables.enabled (default false → dormant). */
    protected function isEnabled(): bool
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('combat.loot_tables.enabled', false);

        return (bool) $v;
    }

    protected function rollPercent(): int
    {
        return mt_rand(1, 100);
    }

    protected function rollQty(int $min, int $max): int
    {
        if ($max < $min) {
            $max = $min;
        }

        return mt_rand(max(0, $min), max(0, $max));
    }
}
