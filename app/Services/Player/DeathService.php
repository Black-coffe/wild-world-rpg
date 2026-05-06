<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Player\Death\DeathPenaltyCalculator;
use App\Services\Player\Death\InsuranceCalculator;
use App\Services\Player\Death\LootProcessor;
use App\Services\Player\Death\PlayerRespawner;

/**
 * F2.8 + F2.8b — thin orchestrator поверх 4 сервисов.
 *
 * До декомпозиции: 415 LOC god-класса с 4 моделями и 12 методами.
 * После: ~110 LOC orchestrator + 4 testable сервиса:
 *   - InsuranceCalculator        (стоимость страховки, чистая)
 *   - DeathPenaltyCalculator     (выбор % потерь, чистая)
 *   - LootProcessor              (compute + apply + transfer ресурсов/крафта/золота)
 *   - PlayerRespawner            (выбор клетки respawn'а)
 *
 * Точка входа `handlePlayerDeathAndReward()` сохранила 1:1 контракт
 * (входы/выходы) — caller'ы (DeathRouletteHandler, AttackPlayerAction,
 * прочие PvP-flows) не нуждаются в правках.
 */
class DeathService
{
    private CharacterModel         $characterModel;
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;

    private InsuranceCalculator    $insuranceCalculator;
    private DeathPenaltyCalculator $penaltyCalculator;
    private LootProcessor          $lootProcessor;
    private PlayerRespawner        $respawner;

    public function __construct(
        ?InsuranceCalculator $insuranceCalculator = null,
        ?DeathPenaltyCalculator $penaltyCalculator = null,
        ?LootProcessor $lootProcessor = null,
        ?PlayerRespawner $respawner = null
    ) {
        $this->characterModel         = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();

        $this->insuranceCalculator = $insuranceCalculator ?? new InsuranceCalculator();
        $this->penaltyCalculator   = $penaltyCalculator   ?? new DeathPenaltyCalculator();
        $this->lootProcessor       = $lootProcessor       ?? new LootProcessor();
        $this->respawner           = $respawner           ?? new PlayerRespawner();
    }

    /**
     * Обработать смерть персонажа: страховка → штраф → списание/передача
     * имущества → respawn.
     *
     * @return array{
     *   hasBase:bool, penalty:float,
     *   transferredResources:list<array{resourceId:int,amount:int}>,
     *   transferredCraftItems:list<array{craftedItemId:int,amount:int}>,
     *   transferredGold:int,
     *   success:bool
     * }
     */
    public function handlePlayerDeathAndReward(int $loserId, ?int $winnerId = null): array
    {
        $loserRow = $this->characterModel->find($loserId);
        if (!$loserRow) {
            return ['success' => false];
        }

        // 1) Insurance check (если страховка активна и хватает золота — штраф 0%).
        $insuranceCovered = $this->tryUseInsurance($loserId, $loserRow);

        // 2) Penalty rate.
        $hasBase = $insuranceCovered ? false : $this->respawner->hasActiveBase($loserId);
        $deathPenalty = $this->penaltyCalculator->decide($insuranceCovered, $hasBase);

        // 3) Сбор имущества проигравшего.
        $loserResources    = $this->characterResourceModel->where('id_characters', $loserId)->findAll();
        $loserGold         = (int) ($loserRow['gold'] ?? 0);
        $loserCraftedItems = $this->craftedItemsLogModel->where('character_id', $loserId)->findAll();

        // 4) Расчёт потерь.
        $lostResources    = $this->lootProcessor->computeResourceLoss($loserResources, $deathPenalty);
        $lostGold         = (int) floor($loserGold * $deathPenalty);
        $lostCraftedItems = $this->lootProcessor->computeCraftLoss($loserCraftedItems, $deathPenalty);

        // 5) Списание у проигравшего.
        $this->lootProcessor->applyLosses($loserId, $lostResources, $lostGold);
        $this->lootProcessor->applyCraftLosses($loserId, $lostCraftedItems);

        // 6) Передача части победителю (factor 0.5 без базы / 1.0 с базой).
        $transferredResources = [];
        $transferredCraft     = [];
        $transferredGold      = 0;
        if ($winnerId !== null) {
            $factor = $hasBase ? 1.0 : 0.5;
            $transferredResources = $this->lootProcessor->transferResourcesToWinner($winnerId, $lostResources, $factor);
            $transferredCraft     = $this->lootProcessor->transferCraftToWinner($winnerId, $lostCraftedItems, $factor);
            $transferGold         = (int) floor($lostGold * $factor);
            if ($transferGold > 0) {
                $this->lootProcessor->transferGoldToWinner($winnerId, $transferGold);
                $transferredGold = $transferGold;
            }
        }

        // 7) Respawn.
        $this->respawner->respawn($loserId);

        return [
            'hasBase'               => $hasBase,
            'penalty'               => $deathPenalty,
            'transferredResources'  => $transferredResources,
            'transferredCraftItems' => $transferredCraft,
            'transferredGold'       => $transferredGold,
            'success'               => true,
        ];
    }

    /**
     * @return bool true если страховка списалась успешно (штраф 0%).
     */
    private function tryUseInsurance(int $loserId, array|\App\Entities\CharacterEntity $loserRow): bool
    {
        if ((int) ($loserRow['insurance'] ?? 0) !== 1) {
            return false;
        }

        $totalResourceRows = $this->characterResourceModel
            ->where('id_characters', $loserId)
            ->countAllResults();
        $cost = $this->insuranceCalculator->calculate($loserRow, $totalResourceRows);

        if ((int) $loserRow['gold'] >= $cost) {
            $this->characterModel->update($loserId, [
                'gold'      => (int) $loserRow['gold'] - $cost,
                'insurance' => 0,
            ]);
            return true;
        }

        // Недоступная страховка — сгорает без эффекта.
        $this->characterModel->update($loserId, ['insurance' => 0]);
        return false;
    }
}
