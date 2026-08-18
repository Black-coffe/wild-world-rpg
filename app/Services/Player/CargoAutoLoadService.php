<?php

declare(strict_types=1);

namespace App\Services\Player;

use Config\Database;

/**
 * Что карго-дрон возьмёт сам, если отправить его «автовывозом».
 *
 * Просьба игрока (Анжела, 18.08.2026): «карго-дрон должен иметь возможность
 * автоматического перемещения ресурса из рюкзака на склад, начиная с высшей редкости и
 * по убыванию, кроме аптечки, еды и воды».
 *
 * Правило отбора:
 *  1. сортировка по редкости ↓, при равной — по цене ↓ (сначала самое ценное);
 *  2. 🔴 НЕ трогаем еду, воду и семена — это расходники выживания и посевной материал,
 *     увезённые «сами собой» они превращают помощь в подставу (у игрока внезапно
 *     нечего есть, пить и сеять). Именно этот список назвала игрок, семена добавлены
 *     по той же логике;
 *  3. набираем, пока влезает в грузоподъёмность: от каждого вида берём столько, сколько
 *     помещается в остаток, и переходим к следующему.
 *
 * Чистый расчёт: сервис ничего не пишет в БД — только читает инвентарь и говорит, что
 * взять. Списание и доставку делает вызывающий, в своей транзакции.
 */
final class CargoAutoLoadService
{
    /**
     * Типы ресурсов (`resources.type` — список через запятую), которые автовывоз не берёт.
     */
    private const SKIP_TYPES = ['food', 'water', 'seed'];

    /**
     * Подобрать груз под лимит веса.
     *
     * @return array{
     *     items: list<array{resource_id:int, name:string, qty:int, weight:float, rarity:int}>,
     *     total_kg: float,
     *     total_units: int,
     *     skipped_kinds: int
     * }
     */
    public function plan(int $characterId, float $payloadKg): array
    {
        $empty = ['items' => [], 'total_kg' => 0.0, 'total_units' => 0, 'skipped_kinds' => 0];

        if ($characterId <= 0 || $payloadKg <= 0) {
            return $empty;
        }

        $query = Database::connect()->query(
            'SELECT cr.id_resources AS resource_id, cr.quantity, r.name, r.weight, r.rarity, r.type, r.price
             FROM character_resources cr
             INNER JOIN resources r ON r.id = cr.id_resources
             WHERE cr.id_characters = ? AND cr.quantity > 0
             ORDER BY r.rarity DESC, r.price DESC',
            [$characterId]
        );

        if (! $query instanceof \CodeIgniter\Database\BaseResult) {
            return $empty;
        }

        $items       = [];
        $remaining   = $payloadKg;
        $totalUnits  = 0;
        $totalKg     = 0.0;
        $skippedKinds = 0;

        foreach ($query->getResultArray() as $row) {
            $resId  = is_numeric($row['resource_id'] ?? null) ? (int) $row['resource_id'] : 0;
            $qty    = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
            $weight = is_numeric($row['weight'] ?? null) ? (float) $row['weight'] : 0.0;
            $rarity = is_numeric($row['rarity'] ?? null) ? (int) $row['rarity'] : 0;
            $name   = is_string($row['name'] ?? null) ? $row['name'] : '';
            $type   = is_string($row['type'] ?? null) ? $row['type'] : '';

            if ($resId <= 0 || $qty <= 0 || $weight <= 0.0 || $name === '') {
                continue;
            }

            if ($this->isSkipped($type)) {
                $skippedKinds++;
                continue;
            }

            $fits = (int) floor($remaining / $weight);
            if ($fits < 1) {
                continue; // не влезает даже одна единица — пробуем следующий вид
            }

            $take = min($qty, $fits);

            $items[]    = ['resource_id' => $resId, 'name' => $name, 'qty' => $take, 'weight' => $weight, 'rarity' => $rarity];
            $takenKg    = $take * $weight;
            $remaining -= $takenKg;
            $totalKg   += $takenKg;
            $totalUnits += $take;

            if ($remaining <= 0.0) {
                break;
            }
        }

        return [
            'items'         => $items,
            'total_kg'      => round($totalKg, 1),
            'total_units'   => $totalUnits,
            'skipped_kinds' => $skippedKinds,
        ];
    }

    /**
     * `resources.type` — список через запятую («crafting,food»), поэтому сравниваем
     * посегментно: подстрочный поиск поймал бы «seed» внутри чужого слова.
     */
    public function isSkipped(string $type): bool
    {
        foreach (explode(',', strtolower($type)) as $segment) {
            if (in_array(trim($segment), self::SKIP_TYPES, true)) {
                return true;
            }
        }

        return false;
    }
}
