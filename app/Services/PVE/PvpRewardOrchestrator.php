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

        // RNG решается ДО lock'а — порядок 3× mt_rand сохранён 1:1 с legacy
        // (seed-fence в PvpRewardOrchestratorTest опирается на этот порядок).
        $factor   = (float) $this->balance->winnerAttrBonusFactor;
        $boostStr = mt_rand(0, 100) < $this->balance->winnerAttrBonusChance;
        $boostAgi = mt_rand(0, 100) < $this->balance->winnerAttrBonusChance;
        $boostInt = mt_rand(0, 100) < $this->balance->winnerAttrBonusChance;

        // ADR-151 (класс lost-update): мультипликаторы применяются к СВЕЖИМ
        // статам под row-lock, пишутся ТОЛЬКО стат-поля. Раньше
        // update($winnerId, $winner) писал ВСЮ строку снапшота → затирал
        // параллельный regen health/tired и cell_number.
        (new \App\Services\Player\CharacterStatsService())->mutate(
            $winnerId,
            ['experience', 'strength', 'agility', 'intellect'],
            static function (array $s) use ($expBonus, $factor, $boostStr, $boostAgi, $boostInt): array {
                $out = ['experience' => round($s['experience'] * (1 + $expBonus), 2)];
                if ($boostStr) {
                    $out['strength'] = round($s['strength'] * (1 + $factor), 2);
                }
                if ($boostAgi) {
                    $out['agility'] = round($s['agility'] * (1 + $factor), 2);
                }
                if ($boostInt) {
                    $out['intellect'] = round($s['intellect'] * (1 + $factor), 2);
                }

                return $out;
            }
        );
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
        $expLoss  = (float) $this->balance->deathExpLossPercent;
        $statLoss = (float) $this->balance->deathStatLossPercent;
        $floorStr = (float) $loser['strength'];
        $floorAgi = (float) $loser['agility'];
        $floorInt = (float) $loser['intellect'];

        // ADR-151 (класс lost-update): мультипликативные потери считаются от
        // СВЕЖИХ статов под row-lock, а не от снапшота find(). Пишутся только
        // стат-поля. `max($floor, …)` сохраняет прежний пол потерь (battle-снапшот
        // $loser). null-возврат mutate = персонаж не найден (прежний ранний return).
        $res = (new \App\Services\Player\CharacterStatsService())->mutate(
            (int) $loser['id'],
            ['experience', 'strength', 'agility', 'intellect'],
            static function (array $s) use ($expLoss, $statLoss, $floorStr, $floorAgi, $floorInt): array {
                return [
                    'experience' => round(max(0.0, $s['experience'] * (1 - $expLoss)), 2),
                    'strength'   => round(max($floorStr, $s['strength'] * (1 - $statLoss)), 2),
                    'agility'    => round(max($floorAgi, $s['agility'] * (1 - $statLoss)), 2),
                    'intellect'  => round(max($floorInt, $s['intellect'] * (1 - $statLoss)), 2),
                ];
            }
        );
        if ($res === null) {
            return;
        }

        // Respawn восстанавливает health/tired — absolute-by-design (полное
        // восстановление; cell_number уже выставлен PlayerRespawner в
        // DeathService → handlePlayerDeathAndReward → respawner.respawn).
        // UPDATE трогает только health/tired → не клоббит exp/статы выше.
        $this->characterModel->update((int) $loser['id'], [
            'health' => round((float) ($loser['max_health'] ?? 100), 2),
            'tired'  => round((float) ($loser['max_tired']  ?? 100), 2),
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
