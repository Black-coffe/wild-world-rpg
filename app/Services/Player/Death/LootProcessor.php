<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\VehicleActivationService;

/**
 * F2.8b — обработка имущества при смерти: списание у проигравшего +
 * передача части победителю.
 *
 * Извлечено из DeathService::handlePlayerDeathAndReward (строки 64-99 +
 * compute/apply/transfer методы legacy v0.5.0). Логика 1:1, обвязка та же:
 *
 *   compute   — чистые расчёты (тестируются unit-тестами)
 *   apply     — DB writes (списание у проигравшего)
 *   transfer  — DB writes (передача победителю)
 *
 * Models инжектируются через конструктор для testability.
 */
class LootProcessor
{
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private CraftedItemsModel $craftedItemsModel;
    private VehicleActivationService $vehicleActivationService;

    public function __construct(
        ?CharacterResourceModel $characterResourceModel = null,
        ?CraftedItemsLogModel $craftedItemsLogModel = null,
        ?CraftedItemsModel $craftedItemsModel = null,
        ?VehicleActivationService $vehicleActivationService = null
    ) {
        $this->characterResourceModel   = $characterResourceModel   ?? new CharacterResourceModel();
        $this->craftedItemsLogModel     = $craftedItemsLogModel     ?? new CraftedItemsLogModel();
        $this->craftedItemsModel        = $craftedItemsModel        ?? new CraftedItemsModel();
        $this->vehicleActivationService = $vehicleActivationService ?? new VehicleActivationService();
    }

    // ---- compute (pure) ----

    /**
     * @param list<array{id:int,id_resources:int,quantity:int|string}> $loserResources
     * @return list<array{charResId:int,resourceId:int,lossAmount:int}>
     */
    public function computeResourceLoss(array $loserResources, float $deathPenalty): array
    {
        $lost = [];
        foreach ($loserResources as $res) {
            $oldQty = (int) $res['quantity'];
            if ($oldQty <= 0) {
                continue;
            }
            $lossAmount = (int) floor($oldQty * $deathPenalty);
            if ($lossAmount > 0) {
                $lost[] = [
                    'charResId'  => (int) $res['id'],
                    'resourceId' => (int) $res['id_resources'],
                    'lossAmount' => $lossAmount,
                ];
            }
        }
        return $lost;
    }

    /**
     * V24 (ADR-056): строки с `insured=1` пропускаются — селективный полис
     * защищает дорогие предметы (robots/workbench/transport/drones). Killswitch
     * `craft_insurance.enabled` обрабатывается выше (caller не передаёт
     * insured-флаг если выключено).
     *
     * ADR-172 ($fractionalChance): голый `floor()` съедал весь штраф на предметах,
     * которых у игрока по одной штуке. Дрон, робот, верстак и транспорт лежат в
     * `crafted_items_log` строками с `quantity=1`, а floor(1 × 0.03) и даже
     * floor(1 × 0.50) — ноль. Игра обещала «−3% имущества», крафт при этом не
     * терялся вообще, а купленный полис защищал от риска, которого не было
     * (прод на 2026-08-19: 18 оплаченных полисов, ни одной строки с qty ≥ 34).
     * При включённом флаге целая часть списывается как раньше, а дробный остаток
     * разыгрывается броском: qty=1 при −3% → 3% шанс потерять предмет целиком.
     * Матожидание потерь при этом ровно то, которое игре и обещано.
     *
     * transport-09 (ADR-174, поправка владельца «разбивается, но не пропадает») —
     * `type='transport'` пропускается здесь же, до броска монетки и до insured-фильтра:
     * машина смертью не изымается вообще, `quantity` строки не трогается. Обнуление
     * износа активной машины делает отдельно `breakActiveVehicleOnDeath()`.
     *
     * @param list<array{id:int,crafted_item_id:int,quantity:int|string,insured?:int|string,type?:string}> $loserCraftedItems
     * @param bool                  $fractionalChance Разыгрывать дробный остаток (GameSettings
     *                                                `combat.death.craft_fractional_loss`).
     * @param (callable():float)|null $roll           Источник случайности [0,1) — для тестов.
     * @return list<array{logId:int,craftedItemId:int,lossAmount:int}>
     */
    public function computeCraftLoss(
        array $loserCraftedItems,
        float $deathPenalty,
        bool $fractionalChance = false,
        ?callable $roll = null
    ): array {
        $lost = [];
        foreach ($loserCraftedItems as $item) {
            if (($item['type'] ?? '') === 'transport') {
                continue;
            }
            $insured = (int) ($item['insured'] ?? 0);
            if ($insured === 1) {
                continue;
            }
            $oldQty = (int) $item['quantity'];
            if ($oldQty <= 0) {
                continue;
            }
            $exact      = (float) $oldQty * $deathPenalty;
            $lossAmount = (int) floor($exact);

            if ($fractionalChance) {
                $fraction = $exact - (float) $lossAmount;
                if ($fraction > 0.0 && $this->rollUnit($roll) < $fraction) {
                    $lossAmount++;
                }
                // Штраф не может забрать больше, чем лежит в строке: при penalty >= 1.0
                // округление вверх иначе списало бы qty+1 и ушло в минус.
                $lossAmount = min($lossAmount, $oldQty);
            }

            if ($lossAmount > 0) {
                $lost[] = [
                    'logId'         => (int) $item['id'],
                    'craftedItemId' => (int) $item['crafted_item_id'],
                    'lossAmount'    => $lossAmount,
                ];
            }
        }
        return $lost;
    }

    /**
     * Бросок [0,1). Вынесен отдельно, чтобы тест подменял источник случайности
     * и проверял обе ветки детерминированно.
     *
     * @param (callable():float)|null $roll
     */
    private function rollUnit(?callable $roll): float
    {
        if ($roll !== null) {
            return $roll();
        }

        return mt_rand(0, mt_getrandmax() - 1) / (float) mt_getrandmax();
    }

    // ---- apply (DB writes у проигравшего) ----

    /**
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}> $lostResources
     */
    public function applyLosses(int $loserId, array $lostResources, int $lostGold): void
    {
        foreach ($lostResources as $lr) {
            $this->characterResourceModel->decreaseQtyById(
                $lr['charResId'],
                $lr['lossAmount']
            );
        }

        if ($lostGold > 0) {
            // Fix 2026-07-13 (класс lost-update): атомарное относительное списание
            // от СВЕЖЕГО золота, floor 0 — дефолтный (CharacterStatsService).
            (new \App\Services\Player\CharacterStatsService())
                ->adjust($loserId, ['gold' => -$lostGold]);
        }
    }

    /**
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}> $lostCraftedItems
     */
    public function applyCraftLosses(int $loserId, array $lostCraftedItems): void
    {
        foreach ($lostCraftedItems as $lc) {
            $row = $this->craftedItemsLogModel->find($lc['logId']);
            if (!$row) {
                continue;
            }
            $newQty = (int) $row['quantity'] - $lc['lossAmount'];
            if ($newQty <= 0) {
                $this->craftedItemsLogModel->delete($lc['logId']);
            } else {
                $this->craftedItemsLogModel->update($lc['logId'], ['quantity' => $newQty]);
            }
        }
    }

    /**
     * transport-09 (ADR-174, поправка владельца) — смерть НЕ изымает активную машину:
     * зовёт готовый `VehicleActivationService::breakActive()` (владелец метода — story 03,
     * этот вызов его не меняет), который обнуляет износ активной строки и снимает
     * указатель `characters.active_vehicle_log_id`. Строка `crafted_items_log` остаётся —
     * её сохраняет исключение `type='transport'` в `computeCraftLoss()` выше.
     *
     * Не вызывается автоматически из `applyCraftLosses()`: тот метод переиспользуют
     * интеграционные тесты на схеме без `characters.active_vehicle_log_id`
     * (`tests/database/LootProcessorTest.php`, вне Files этой story). Вызывающая
     * сторона (оркестратор смерти) зовёт этот метод отдельным шагом.
     *
     * @return string|null сообщение для игрока; `null` — если активной машины не было
     */
    public function breakActiveVehicleOnDeath(int $characterId): ?string
    {
        $active = $this->vehicleActivationService->resolveActive($characterId);
        if ($active === null) {
            return null;
        }

        $this->vehicleActivationService->breakActive($characterId);

        return '🚚 Твоя машина разбита и потеряла весь заряд в бою. Она осталась у тебя — '
            . 'почини её в «🔨 Крафтовые ресурсы» → ремонт, чтобы снова сесть за руль.';
    }

    // ---- transfer (DB writes — победителю) ----

    /**
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}> $lostResources
     * @return list<array{resourceId:int,amount:int}>
     */
    public function transferResourcesToWinner(int $winnerId, array $lostResources, float $factor): array
    {
        $transferred = [];
        foreach ($lostResources as $lr) {
            $amtToWinner = (int) floor($lr['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            $this->characterResourceModel->increaseResources(
                $winnerId,
                $lr['resourceId'],
                $amtToWinner
            );
            $transferred[] = [
                'resourceId' => $lr['resourceId'],
                'amount'     => $amtToWinner,
            ];
        }
        return $transferred;
    }

    /**
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}> $lostCraftedItems
     * @return list<array{craftedItemId:int,amount:int}>
     */
    public function transferCraftToWinner(int $winnerId, array $lostCraftedItems, float $factor): array
    {
        $transferred = [];
        foreach ($lostCraftedItems as $lc) {
            $amtToWinner = (int) floor($lc['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            $this->increaseCraftForWinner($winnerId, $lc['craftedItemId'], $amtToWinner);
            $transferred[] = [
                'craftedItemId' => $lc['craftedItemId'],
                'amount'        => $amtToWinner,
            ];
        }
        return $transferred;
    }

    public function transferGoldToWinner(int $winnerId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        // Fix 2026-07-13 (класс lost-update): атомарное относительное начисление
        // от СВЕЖЕГО золота (CharacterStatsService).
        (new \App\Services\Player\CharacterStatsService())
            ->adjust($winnerId, ['gold' => $amount]);
    }

    private function increaseCraftForWinner(int $winnerId, int $craftedItemId, int $amount): void
    {
        $existing = $this->craftedItemsLogModel
            ->where('character_id', $winnerId)
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        if ($existing) {
            $newQty = (int) $existing['quantity'] + $amount;
            $this->craftedItemsLogModel->update($existing['id'], ['quantity' => $newQty]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'      => $winnerId,
                'crafted_item_id'   => $craftedItemId,
                'task_id'           => null,
                'type'              => 'loot',
                'direction_craft'   => 'pvp_loot',
                'crafting_location' => 'battlefield',
                // Прочность — из шаблона (как крафт/покупка/PvE-награда). Ноль означал,
                // что трофей приходит уже сломанным: инструмент уничтожался при первом
                // же использовании, у медикамента сгорала одна доза.
                'durability_count'  => $this->templateDurability($craftedItemId),
                'quantity'          => $amount,
            ]);
        }
    }

    /** Базовая прочность предмета из `crafted_items`; 1 — безопасный минимум. */
    private function templateDurability(int $craftedItemId): int
    {
        $row = $this->craftedItemsModel->find($craftedItemId);
        $raw = is_array($row) ? ($row['durability_count'] ?? null) : null;

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 1;
    }
}
