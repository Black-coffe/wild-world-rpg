<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use Config\GameBalance;

/**
 * F2.3b Step 4 — DB-записи реварда / смерти / истощения после PvP боя.
 *
 * Извлечено из AttackPlayerAction v0.13.0:
 *   - giveWinnerBonus           (~38 LOC, exp boost + 3× attr chance)
 *   - processDeathAndRespawn    (-5% XP, -0.5% stats + respawn)
 *   - processMutualExhaustion   (health=10, tired=10 для обоих + respawn)
 *   - moveExhaustedPlayer       (DB write одного истощённого)
 *   - findRespawnCell           (claimed → explored random → cell 1)
 *   - makeLoserDiffText         (DB read after + format)
 *   - makeWinnerDiffText        (DB read after + format)
 *
 * DI: CharacterModel + ClaimedCellModel + ExploredCellsModel + GameBalance.
 *
 * RNG NOTE: giveWinnerBonus делает 3× mt_rand для attr bonus chance.
 * findRespawnCell использует array_rand для explored cells.
 * Эти вызовы происходят ПОСЛЕ simulateFight, не влияют на fixture-fence,
 * но порядок per-call preserved 1:1 с legacy.
 *
 * IMPORTANT: findRespawnCell здесь не объединяется с PlayerRespawner
 * (F2.8b) — у них РАЗНЫЕ правила:
 *   - PlayerRespawner.respawn:   claimed.status=active → biome ∈ [1,2,3,6] AND y∈[900..999]
 *   - PvpRewardOrchestrator:     claimed (любой) → random explored
 * Это потенциальный bug, чинить отдельным ticket'ом. Step 4 preserve as-is.
 */
final class PvpRewardOrchestrator
{
    private CharacterModel $characterModel;
    private ClaimedCellModel $claimedCellModel;
    private ExploredCellsModel $exploredCellsModel;
    private GameBalance $balance;

    public function __construct(
        ?CharacterModel $characterModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?ExploredCellsModel $exploredCellsModel = null,
        ?GameBalance $balance = null
    ) {
        $this->characterModel     = $characterModel     ?? new CharacterModel();
        $this->claimedCellModel   = $claimedCellModel   ?? new ClaimedCellModel();
        $this->exploredCellsModel = $exploredCellsModel ?? new ExploredCellsModel();
        $this->balance            = $balance            ?? config('GameBalance');
    }

    /**
     * Награда победителю: +5% XP базово (+ до +10% если враг сильнее) +
     * по 20% шанс на каждый из трёх статов (+0.1%).
     *
     * RNG order: 3× mt_rand(0, 100) для strength/agility/intellect.
     */
    public function giveWinnerBonus(int $winnerId): void
    {
        $winner = $this->characterModel->find($winnerId);
        if (!$winner) {
            return;
        }

        $loser = $this->characterModel
            ->where('cell_number', $winner['cell_number'])
            ->where('id !=', $winner['id'])
            ->first();

        $levelDiff = 0;
        if ($loser) {
            $levelDiff = $loser['level'] - $winner['level'];
        }

        $expBonus = $this->balance->winnerExpBaseBonus;
        if ($levelDiff > 0) {
            $expBonus += min($levelDiff, 100) / 100 * $this->balance->winnerExpMaxAdditive;
        }

        $winner['experience'] = (float) $winner['experience'] * (1 + $expBonus);

        if (mt_rand(0, 100) < $this->balance->winnerAttrBonusChance) {
            $winner['strength']  = (float) $winner['strength']  * (1 + $this->balance->winnerAttrBonusFactor);
        }
        if (mt_rand(0, 100) < $this->balance->winnerAttrBonusChance) {
            $winner['agility']   = (float) $winner['agility']   * (1 + $this->balance->winnerAttrBonusFactor);
        }
        if (mt_rand(0, 100) < $this->balance->winnerAttrBonusChance) {
            $winner['intellect'] = (float) $winner['intellect'] * (1 + $this->balance->winnerAttrBonusFactor);
        }

        // F1.3 strict_types: explicit float cast — characters table возвращает
        // strings из MySQL, без cast TypeError на round().
        $winner['experience'] = round((float)$winner['experience'], 2);
        $winner['strength']   = round((float)$winner['strength'],   2);
        $winner['agility']    = round((float)$winner['agility'],    2);
        $winner['intellect']  = round((float)$winner['intellect'],  2);

        $this->characterModel->update($winnerId, $winner);
    }

    /**
     * Смерть проигравшего: -5% XP, -0.5% статов, восстановление health/tired.
     *
     * BUG FIX (v0.14.1): убран дублирующий respawn вызов.
     *
     * Legacy v0.14.0 в этом методе вызывал findRespawnCell + UPDATE cell_number.
     * Но AttackPlayerAction.handle() ПЕРЕД этим методом вызывает
     * DeathService.handlePlayerDeathAndReward, который внутри уже выполнил
     * PlayerRespawner.respawn (с biome-aware фильтром). Второй UPDATE
     * cell_number в PvpRewardOrchestrator переписывал корректный выбор
     * PlayerRespawner на explored_cells choice.
     *
     * После fix: cell_number сохранён как установил PlayerRespawner.respawn.
     * Этот метод теперь отвечает только за статы / XP / HP / tired.
     */
    public function processDeathAndRespawn(array|\App\Entities\CharacterEntity $loser): void
    {
        $before = $this->characterModel->find($loser['id']);
        if (!$before) {
            return;
        }

        $loserOldExp = $before['experience'];
        $loserOldStr = $before['strength'];
        $loserOldAgi = $before['agility'];
        $loserOldInt = $before['intellect'];

        $upd = [
            'experience' => max(0, $loserOldExp * (1 - $this->balance->deathExpLossPercent)),
            'strength'   => max($loser['strength'],  $loserOldStr * (1 - $this->balance->deathStatLossPercent)),
            'agility'    => max($loser['agility'],   $loserOldAgi * (1 - $this->balance->deathStatLossPercent)),
            'intellect'  => max($loser['intellect'], $loserOldInt * (1 - $this->balance->deathStatLossPercent)),
            'health'     => 0,
        ];
        // F1.3 strict_types: explicit float cast (см. winnerHandle выше).
        $upd['experience'] = round((float)$upd['experience'], 2);
        $upd['strength']   = round((float)$upd['strength'],   2);
        $upd['agility']    = round((float)$upd['agility'],    2);
        $upd['intellect']  = round((float)$upd['intellect'],  2);

        $this->characterModel->update($loser['id'], $upd);

        // Восстанавливаем health/tired (cell_number уже выставлен PlayerRespawner
        // в DeathService → handlePlayerDeathAndReward → respawner.respawn).
        $this->characterModel->update($loser['id'], [
            'health' => round((float)($loser['max_health'] ?? 100), 2),
            'tired'  => round((float)($loser['max_tired']  ?? 100), 2),
        ]);
    }

    /**
     * Оба бойца истощились — health/tired = 10 + respawn для каждого.
     */
    public function processMutualExhaustion(array $pA, array $pB): void
    {
        $this->moveExhaustedPlayer((int) $pA['id']);
        $this->moveExhaustedPlayer((int) $pB['id']);
    }

    private function moveExhaustedPlayer(int $charId): void
    {
        $this->characterModel->update($charId, [
            'health' => 10,
            'tired'  => 10,
        ]);
        $respawnCell = $this->findRespawnCell($charId);
        $this->characterModel->update($charId, [
            'cell_number' => $respawnCell,
        ]);
    }

    /**
     * Respawn cell:
     *   1) claimed_cells (любой статус) → map_cell_id базы
     *   2) random из explored_cells персонажа
     *   3) fallback на cell_number = 1
     *
     * NOTE: Не путать с PlayerRespawner.respawn (F2.8b) — там status=active
     * фильтр и biome-aware random. Здесь preserve legacy behavior 1:1.
     */
    /**
     * PvP exhaustion respawn — claimed (ANY status) → explored → fallback 1.
     *
     * ⚠️ **Intentional legacy bug preserved**: НЕ фільтрує по `status='active'`.
     * Гравець на inactive/pending базі може respawn'итися туди. Тест
     * `PvpRewardOrchestratorTest::testFindRespawnReturnsClaimedCellIgnoringStatus`
     * locks-in цю поведінку як "preserve as-is" для backward compat.
     * Якщо колись виправлятимемо — оновлювати разом з тестом + перевіряти PvP UX.
     *
     * Це 1 з 3 різних respawn implementations у repo:
     * - {@see PvpRewardOrchestrator::findRespawnCell} (this) — PvP exhaustion (claimed any status)
     * - {@see \App\Services\Player\Death\PlayerRespawner::respawn} — general death (biome whitelist)
     * - {@see \App\TaskHandlers\DeathRouletteHandler::findRespawnCell} — death roulette (claimed active)
     *
     * Semantically intentional divergence (3 типи смерті — різні fallback стратегії).
     */
    public function findRespawnCell(int $charId): int
    {
        $claimed = $this->claimedCellModel->where('character_id', $charId)->first();
        if ($claimed) {
            return (int) $claimed['map_cell_id'];
        }
        $explored = $this->exploredCellsModel->where('character_id', $charId)->findAll();
        if (!empty($explored)) {
            $rnd = $explored[array_rand($explored)];
            return (int) $rnd['map_cell_id'];
        }
        return 1;
    }

    /**
     * Текст потерь для проигравшего (DB read для after-state).
     */
    public function makeLoserDiffText(array|\App\Entities\CharacterEntity $loserBefore): string
    {
        $loserAfter = $this->characterModel->find($loserBefore['id']);
        if (!$loserAfter) {
            return "";
        }

        $lostExp = max(0, round($loserBefore['experience'] - $loserAfter['experience'], 2));
        $lostStr = max(0, round($loserBefore['strength']   - $loserAfter['strength'],   2));
        $lostAgi = max(0, round($loserBefore['agility']    - $loserAfter['agility'],    2));
        $lostInt = max(0, round($loserBefore['intellect']  - $loserAfter['intellect'],  2));

        return "\n<b>Потери:</b> \n"
            . "• Опыт: -{$lostExp}\n"
            . "• Сила: -{$lostStr}\n"
            . "• Ловкость: -{$lostAgi}\n"
            . "• Интеллект: -{$lostInt}\n"
            . "😰 <b>Горький привкус поражения...</b>";
    }

    /**
     * Текст награды для победителя (DB read для after-state).
     */
    public function makeWinnerDiffText(array|\App\Entities\CharacterEntity $winnerBefore): string
    {
        $winnerAfter = $this->characterModel->find($winnerBefore['id']);
        if (!$winnerAfter) {
            return "";
        }

        $gainExp = round($winnerAfter['experience'] - $winnerBefore['experience'], 2);
        $gainStr = round($winnerAfter['strength']   - $winnerBefore['strength'],   2);
        $gainAgi = round($winnerAfter['agility']    - $winnerBefore['agility'],    2);
        $gainInt = round($winnerAfter['intellect']  - $winnerBefore['intellect'],  2);

        return "\n<b>Награда за победу:</b>\n"
            . "• Опыт: +{$gainExp}\n"
            . "• Сила: +{$gainStr}\n"
            . "• Ловкость: +{$gainAgi}\n"
            . "• Интеллект: +{$gainInt}\n"
            . "🔥 <b>Вкус триумфа вдохновляет!</b>";
    }
}
