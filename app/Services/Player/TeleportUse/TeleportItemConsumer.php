<?php

namespace App\Services\Player\TeleportUse;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTime;

/**
 * v0.51.66 (TeleportUseAction decomp Step 4) — extract durability/quantity
 * decrement logic для Backpack + Portable у dedicated consumer.
 *
 * Хоча обидва items share decrement pattern, exact semantics різна:
 *  - Backpack: 60-min cooldown via custom_setting JSON (lastUsedAt).
 *    На durability=0: якщо quantity>1 → qty--, durability=0, custom=null.
 *  - Portable: refresh durability на quantity decrement.
 *    На durability=1 (next would be 0): якщо qty>1 → qty--, durability=full reset.
 *    Якщо qty=1 → delete row.
 *
 * exploit-fix-08 (F7, EA-duplication-02) — обидва методи списують заряд
 * умовним записом по снімку (compare-and-swap): `WHERE id = ? AND quantity = ?
 * AND durability_count = ?`, точно ті значення, з яких рахували гілку. Якщо
 * рядок встиг змінитись (другий тап по тому ж снімку валідатора) — 0 affected
 * rows, метод сигналізує відмову, і `TeleportUseAction` НЕ телепортує. Готового
 * методу `ConditionalWriteService` під двоколонковий CAS з довільним новим
 * значенням немає (`decrementIfAtLeast` перевіряє «не менше», а не «точно те
 * саме» — для гонки з тим самим застарілим снімком цього не досить), тому
 * умовний `UPDATE`/`DELETE` + `affectedRows()` тут той самий примітив, зібраний
 * інлайн, тим самим `BaseConnection`, яким проєкт вже інжектить з'єднання
 * (`VehicleActivationService`, `ClosestCellFinder`, `WipeService`).
 */
class TeleportItemConsumer
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
     * Backpack consume: CAS по снімку (`$backpackLog`), decrement durability,
     * оновити lastUsedAt у custom_setting, або списати quantity якщо
     * durability вичерпана.
     *
     * @param array<string,mixed> $backpackLog crafted_items_log row (снимок, на котором считали)
     * @param array<string,mixed> $customData decoded JSON (will be updated with lastUsedAt)
     * @return int|null newDurability при подтверждённом списании; null — отказ (снимок устарел)
     */
    public function consumeBackpack(array $backpackLog, array $customData): ?int
    {
        $id                 = (int) $backpackLog['id'];
        $quantitySnapshot   = (int) $backpackLog['quantity'];
        $durabilitySnapshot = (int) $backpackLog['durability_count'];
        $newDurability      = $durabilitySnapshot - 1;
        $table              = $this->db->prefixTable('crafted_items_log');

        $customData['lastUsedAt'] = (new DateTime())->format('Y-m-d H:i:s');

        if ($newDurability <= 0) {
            if ($quantitySnapshot > 1) {
                $this->db->query(
                    "UPDATE {$table} SET quantity = ?, durability_count = 0, custom_setting = NULL "
                    . 'WHERE id = ? AND quantity = ? AND durability_count = ?',
                    [$quantitySnapshot - 1, $id, $quantitySnapshot, $durabilitySnapshot]
                );
            } else {
                // Останній екземпляр + durability=0 → видалити
                $this->db->query(
                    'DELETE FROM ' . $table . ' WHERE id = ? AND quantity = ? AND durability_count = ?',
                    [$id, $quantitySnapshot, $durabilitySnapshot]
                );
            }
        } else {
            $this->db->query(
                'UPDATE ' . $table . ' SET durability_count = ?, custom_setting = ? '
                . 'WHERE id = ? AND quantity = ? AND durability_count = ?',
                [$newDurability, json_encode($customData), $id, $quantitySnapshot, $durabilitySnapshot]
            );
        }

        return $this->db->affectedRows() > 0 ? $newDurability : null;
    }

    /**
     * Portable consume: CAS по снімку (`$portableLog`). На durability=1
     * (next would be 0): якщо qty>1 → qty--, durability refreshed до full
     * (item template default); якщо qty=1 → delete row.
     *
     * @param array<string,mixed> $portableItem crafted_items reference (для template durability)
     * @param array<string,mixed> $portableLog  crafted_items_log row (снимок, на котором считали)
     * @return bool true — заряд подтверждённо списан; false — отказ (снимок устарел / строки уже нет)
     */
    public function consumePortable(array $portableItem, array $portableLog): bool
    {
        $id                 = (int) $portableLog['id'];
        $quantitySnapshot   = (int) $portableLog['quantity'];
        $durabilitySnapshot = (int) $portableLog['durability_count'];
        $table              = $this->db->prefixTable('crafted_items_log');

        if ($durabilitySnapshot > 1) {
            $this->db->query(
                'UPDATE ' . $table . ' SET durability_count = ? '
                . 'WHERE id = ? AND quantity = ? AND durability_count = ?',
                [$durabilitySnapshot - 1, $id, $quantitySnapshot, $durabilitySnapshot]
            );

            return $this->db->affectedRows() > 0;
        }

        if ($quantitySnapshot > 1) {
            // Quantity decrement + refresh durability до full.
            // Источник числа — рецепт (GameSettings), тот же, из которого заряды выдаёт
            // CraftCompletionPortableTeleportHandler: иначе админская правка зарядов
            // расходилась бы с шаблоном `crafted_items` (две правды на одну величину).
            $fullCharges = (new \App\Services\Player\Craft\PortableTeleportRecipe())->charges();

            $this->db->query(
                'UPDATE ' . $table . ' SET quantity = ?, durability_count = ? '
                . 'WHERE id = ? AND quantity = ? AND durability_count = ?',
                [$quantitySnapshot - 1, $fullCharges, $id, $quantitySnapshot, $durabilitySnapshot]
            );

            return $this->db->affectedRows() > 0;
        }

        // Останній екземпляр + durability=1 → видалити (повністю використаний)
        $this->db->query(
            'DELETE FROM ' . $table . ' WHERE id = ? AND quantity = ? AND durability_count = ?',
            [$id, $quantitySnapshot, $durabilitySnapshot]
        );

        return $this->db->affectedRows() > 0;
    }
}
