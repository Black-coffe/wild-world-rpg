<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CraftedItemsLogModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * transport-03 (ADR-174, план `docs/specs/transport-system/plan.md` → Contracts) —
 * владелец жизненного цикла `characters.active_vehicle_log_id`: указателя на активную
 * строку `crafted_items_log`. Инвариант «активна максимум одна машина» держится
 * СТРУКТУРНО (одно поле — одно значение), не отдельным флагом на строке лога
 * (в отличие от `characters_weapons.equipped`, который держит инвариант кодом).
 *
 * 🔴 Чтение владения — ТОЛЬКО `WHERE id = ? AND character_id = ?` (анти-IDOR +
 * грабля `first()` без `orderBy` при дублях). Висячий указатель (строка лога удалена)
 * лечится чтением — самолечение в NULL, без FK/каскада (Non-goal story).
 */
final class VehicleActivationService
{
    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Разрешает активный транспорт персонажа. Промах (пустой указатель или строка
     * лога исчезла) — самолечение указателя в NULL, `null`, без исключения.
     *
     * `charges` — фактический остаток, зажатый `min(dur, base)`, БЕЗ пола потребляемых
     * предметов (`CraftedItemsLogModel::effectiveCharges()` держит пол `max(1, ...)` —
     * корректно для «последней дозы» медикамента, но не для транспорта: полностью
     * изношенная машина обязана репортовать буквальный `0` (бонус исчезает, поход
     * не блокируется), поэтому клампится тем же `min(dur, base)`, но локально.
     *
     * @return array{log_id:int, key:string, charges:int}|null
     */
    public function resolveActive(int $characterId): ?array
    {
        $pointer = $this->pointer($characterId);
        if ($pointer === null) {
            return null;
        }

        $row = $this->fetchOwnedRow($characterId, $pointer);
        if ($row === null) {
            // Строка лога исчезла (удалена/списана) — висячий указатель лечится чтением.
            $this->setPointer($characterId, null);

            return null;
        }

        $rawId  = $row['id'] ?? null;
        $rawKey = $row['name_eng'] ?? null;

        return [
            'log_id'  => is_numeric($rawId) ? (int) $rawId : 0,
            'key'     => is_string($rawKey) ? $rawKey : '',
            'charges' => $this->clampCharges($row['durability_count'] ?? null, $row['template_durability'] ?? null),
        ];
    }

    /**
     * Активирует строку `$logId` как транспорт персонажа. Чужая/несуществующая строка
     * → `false`, БЕЗ записи (анти-IDOR). Повторный вызов другой своей строкой —
     * переставляет указатель (структурный инвариант «максимум одна»).
     */
    public function activate(int $characterId, int $logId): bool
    {
        $owns = $this->fetchOwnedRow($characterId, $logId) !== null;
        if (! $owns) {
            return false;
        }

        $this->setPointer($characterId, $logId);

        return true;
    }

    /**
     * Снимает активный транспорт. Работает из любого состояния, в том числе когда
     * указатель уже `NULL`.
     */
    public function deactivate(int $characterId): void
    {
        $this->setPointer($characterId, null);
    }

    /**
     * Списывает износ с АКТИВНОЙ строки (по `id`, не по `crafted_item_id`) за `$cells`
     * пройденных клеток. Текущий остаток читается через
     * `CraftedItemsLogModel::effectiveCharges()` (зажим `min(dur, base)`, защита от
     * исторического мусора в `durability_count`), затем списывается арифметикой —
     * результат МОЖЕТ дойти до буквального 0 (в отличие от чтения, у списания нет
     * пола в 1: пустой бак — валидное состояние транспорта).
     *
     * На нуле зарядов поход НЕ блокируется — метод просто возвращает 0, вызывающий
     * код (story 04) читает это как «бонуса больше нет».
     *
     * @return int остаток зарядов ПОСЛЕ списания (>= 0)
     */
    public function spendCharges(int $characterId, int $cells, int $wearPerCell): int
    {
        $pointer = $this->pointer($characterId);
        if ($pointer === null) {
            return 0;
        }

        $row = $this->fetchOwnedRow($characterId, $pointer);
        if ($row === null) {
            $this->setPointer($characterId, null);

            return 0;
        }

        $current = CraftedItemsLogModel::effectiveCharges(
            $row['durability_count'] ?? null,
            $row['template_durability'] ?? null
        );

        $spend     = max(0, $cells) * max(0, $wearPerCell);
        $remainder = max(0, $current - $spend);

        $this->db->table('crafted_items_log')
            ->where('id', $pointer)
            ->where('character_id', $characterId)
            ->update(['durability_count' => $remainder]);

        return $remainder;
    }

    /**
     * Смерть (ADR-174, поправка владельца «разбивается, но не пропадает»): износ
     * активной строки в 0, указатель в NULL. Строка `crafted_items_log` НЕ удаляется
     * и `quantity` не меняется — решение владельца остаётся у него, просто без заряда.
     */
    public function breakActive(int $characterId): void
    {
        $pointer = $this->pointer($characterId);
        if ($pointer === null) {
            return;
        }

        $this->db->table('crafted_items_log')
            ->where('id', $pointer)
            ->where('character_id', $characterId)
            ->update(['durability_count' => 0]);

        $this->setPointer($characterId, null);
    }

    private function pointer(int $characterId): ?int
    {
        $result = $this->db->table('characters')
            ->select('active_vehicle_log_id')
            ->where('id', $characterId)
            ->get();

        $row   = $result === false ? null : $result->getRowArray();
        $value = is_array($row) ? ($row['active_vehicle_log_id'] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function setPointer(int $characterId, ?int $logId): void
    {
        $this->db->table('characters')
            ->where('id', $characterId)
            ->update(['active_vehicle_log_id' => $logId]);
    }

    /**
     * Строка `crafted_items_log`, принадлежащая ИМЕННО этому персонажу — единственный
     * разрешённый способ чтения владения (`WHERE id = ? AND character_id = ?`).
     *
     * @return array<string,mixed>|null
     */
    private function fetchOwnedRow(int $characterId, int $logId): ?array
    {
        $result = $this->db->table('crafted_items_log AS l')
            ->select('l.id, l.durability_count, ci.durability_count AS template_durability, ci.name_eng')
            ->join('crafted_items AS ci', 'ci.id = l.crafted_item_id', 'left')
            ->where('l.id', $logId)
            ->where('l.character_id', $characterId)
            ->get();

        $row = $result === false ? null : $result->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Зажим `min(dur, base)` для чтения текущего остатка, БЕЗ пола потребляемых
     * предметов — `0` для транспорта означает «изношен», а не «последняя доза».
     */
    private function clampCharges(mixed $stored, mixed $templateDurability): int
    {
        $base   = CraftedItemsLogModel::baseCharges($templateDurability);
        $value  = is_numeric($stored) ? (int) $stored : $base;

        return max(0, min($value, $base));
    }
}
