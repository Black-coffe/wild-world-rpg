<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Models\CharacterResourceModel;
use App\Services\Player\Progression\EarlyProgressionService;

/**
 * v0.51.105 (GatherTaskHandler decomp Step 2) — extract DB persistence
 * блок (character_resources insert/update + character stats bump) у
 * dedicated final class.
 *
 * persist() ortografує full sequence: resource quantity persist + stat gains.
 * Stat gains formula: per resource +0.0006 strength + 0.0001 agility/intellect
 * + 0.0005 health (capped @ 100).
 */
final class GatherResultPersister
{
    private EarlyProgressionService $early;

    public function __construct(
        private ?CharacterResourceModel $characterResourceModel = null,
        ?EarlyProgressionService $early = null
    ) {
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
        // ADR-138 (S3): XP за добычу новичку (level<cap). Dormant → gatherXpEarned()=0.0 = легаси.
        $this->early                  = $early ?? new EarlyProgressionService();
    }

    /**
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     * @param array<string, mixed>                                            $task         from character_tasks
     */
    public function persist(array $foundResources, array|\App\Entities\CharacterEntity $character, array $task): void
    {
        if (!empty($foundResources)) {
            // ADR-135 Ф2 «Трофейная подать»: zero-sum переток rate% хозяину ДО зачисления
            // добычи жертве (Σ сохраняется, жертва получает остаток). Killswitch tribute.enabled
            // внутри → dormant = identity (byte-equivalent). Stat-gain per-entry → не меняется.
            $foundResources = (new \App\Services\PVE\TributeService())->collectOnGather($character, $foundResources);
            $this->writeCharacterResources($foundResources, $character);
        }
        $this->bumpCharacterStats($character, $foundResources);
    }

    /**
     * v0.51.33 perf: 1 whereIn для существуючих rows замість N SELECT у loop'і.
     *
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     */
    private function writeCharacterResources(array $foundResources, array|\App\Entities\CharacterEntity $character): void
    {
        $resourceIds = array_values(array_unique(array_map(
            static fn ($r): int => (int) $r['resource_id'],
            $foundResources
        )));
        $existingRows = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->whereIn('id_resources', $resourceIds)
            ->findAll();
        $existingByResId = [];
        foreach ($existingRows as $row) {
            $existingByResId[(int) $row['id_resources']] = $row;
        }

        foreach ($foundResources as $res) {
            $amount = (int) $res['amount'];
            if ($amount < 1) {
                continue;
            }

            $existingResource = $existingByResId[(int) $res['resource_id']] ?? null;
            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $amount;
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters'     => $character['id'],
                    'id_resources'      => $res['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity'          => $amount,
                ]);
            }
        }
    }

    /**
     * Per-resource stat gain: +0.0006 str + 0.0001 agi/int + 0.0005 health (capped @ 100).
     * expGain: ADR-138 (S3) — loop-earned XP за ЗАВЕРШЕНИЕ добычи новичку (level<cap),
     * иначе 0.0 (dormant = легаси). Стат-гейны не множим (теряются на DECIMAL(7,2) всё равно).
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     * @param array<int, mixed>                                  $foundResources
     */
    private function bumpCharacterStats(array|\App\Entities\CharacterEntity $character, array $foundResources): void
    {
        $levelRaw    = $character['level'] ?? 1;
        $level       = is_numeric($levelRaw) ? (float) $levelRaw : 1.0;
        $expGain     = $this->early->gatherXpEarned($level);
        $curExp      = is_numeric($character['experience'] ?? null) ? (float) $character['experience'] : 0.0;
        $healthGain  = 0.0;
        $strength    = 0.0;
        $agility     = 0.0;
        $intellect   = 0.0;

        foreach ($foundResources as $res) {
            $strength   += 0.0006;
            $agility    += 0.0001;
            $intellect  += 0.0001;
            $healthGain += 0.0005;
        }

        // Fix 2026-07-13 (класс lost-update): гейны — атомарным relative-UPDATE от
        // СВЕЖИХ значений (CharacterStatsService); снапшот задачи добычи (минуты
        // давности) больше не затирает параллельные изменения (препарат, бой).
        // Cap health 100 — дефолтный в сервисе.
        $charIdRaw = $character['id'] ?? null;
        $charId    = is_numeric($charIdRaw) ? (int) $charIdRaw : 0;
        (new \App\Services\Player\CharacterStatsService())->adjust($charId, [
            'experience' => $expGain,
            'health'     => $healthGain,
            'strength'   => $strength,
            'agility'    => $agility,
            'intellect'  => $intellect,
        ]);
    }
}
