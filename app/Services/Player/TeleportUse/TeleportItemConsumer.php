<?php

namespace App\Services\Player\TeleportUse;

use App\Models\CraftedItemsLogModel;
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
 * Behavior preservation: 1:1 з legacy.
 */
class TeleportItemConsumer
{
    private CraftedItemsLogModel $craftedItemLogModel;

    public function __construct(?CraftedItemsLogModel $craftedItemLogModel = null)
    {
        $this->craftedItemLogModel = $craftedItemLogModel ?? new CraftedItemsLogModel();
    }

    /**
     * Backpack consume: decrement durability, оновити lastUsedAt у custom_setting,
     * або списати quantity якщо durability вичерпана.
     *
     * @param array<string,mixed> $backpackLog crafted_items_log row
     * @param array<string,mixed> $customData decoded JSON (will be updated with lastUsedAt)
     * @return int newDurability — для UX message
     */
    public function consumeBackpack(array $backpackLog, array $customData): int
    {
        $customData['lastUsedAt'] = (new DateTime())->format('Y-m-d H:i:s');
        $newDurability = (int) $backpackLog['durability_count'] - 1;

        if ($newDurability <= 0) {
            if ((int) $backpackLog['quantity'] > 1) {
                $this->craftedItemLogModel->update($backpackLog['id'], [
                    'quantity'         => (int) $backpackLog['quantity'] - 1,
                    'durability_count' => 0,
                    'custom_setting'   => null,
                ]);
            } else {
                // Останній екземпляр + durability=0 → видалити
                $this->craftedItemLogModel->delete($backpackLog['id']);
            }
        } else {
            $this->craftedItemLogModel->update($backpackLog['id'], [
                'durability_count' => $newDurability,
                'custom_setting'   => json_encode($customData),
            ]);
        }

        return $newDurability;
    }

    /**
     * Portable consume: decrement durability. На durability=1 (next would be 0):
     * якщо qty>1 → qty--, durability refreshed до full (item template default);
     * якщо qty=1 → delete row.
     *
     * @param array<string,mixed> $portableItem crafted_items reference (для template durability)
     * @param array<string,mixed> $portableLog  crafted_items_log row
     */
    public function consumePortable(array $portableItem, array $portableLog): void
    {
        if ((int) $portableLog['durability_count'] > 1) {
            $this->craftedItemLogModel->update($portableLog['id'], [
                'durability_count' => (int) $portableLog['durability_count'] - 1,
            ]);
            return;
        }

        if ((int) $portableLog['quantity'] > 1) {
            // Quantity decrement + refresh durability до full.
            // Источник числа — рецепт (GameSettings), тот же, из которого заряды выдаёт
            // CraftCompletionPortableTeleportHandler: иначе админская правка зарядов
            // расходилась бы с шаблоном `crafted_items` (две правды на одну величину).
            $this->craftedItemLogModel->update($portableLog['id'], [
                'quantity'         => (int) $portableLog['quantity'] - 1,
                'durability_count' => (new \App\Services\Player\Craft\PortableTeleportRecipe())->charges(),
            ]);
        } else {
            // Останній екземпляр + durability=1 → видалити (повністю використаний)
            $this->craftedItemLogModel->delete($portableLog['id']);
        }
    }
}
