<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;

/**
 * F7.3 — застосовує EffectResult intents до DB.
 *
 * Effect-класи — чисті компуторы, повертають intent-array. IntentApplier
 * перетворює це в реальні DB-записи (CharacterModel update, character_resources
 * insert/update, character_tasks end_time +N minutes, тощо).
 *
 * Використовується з EventDispatcher::tickAllActive() для кожного character'а
 * після того як effect.compute() повернув applied=true.
 *
 * Усі моделі ін'єктовані через конструктор — легко мочити в тестах.
 */
final class IntentApplier
{
    public function __construct(
        private CharacterModel $charModel,
        private CharacterResourceModel $charResourceModel,
        private CharacterTaskModel $charTaskModel,
        private ResourceModel $resourceModel,
        private TaskModel $taskModel,
    ) {
    }

    /**
     * Застосувати ввесь EffectResult intent-array до конкретного character'а.
     *
     * @param array<string, mixed> $character Поточний row з characters
     * @param array<string, mixed> $result    EffectResult з effect.compute()
     * @return array<string, mixed> Оновлений character (перечитаний з DB після writes)
     */
    public function apply(array $character, array $result): array
    {
        $charId = (int)$character['id'];

        // ============================================================
        // 1. Health / Tired / Gold / Attribute deltas
        // ============================================================
        $update = [];

        if (($result['health_delta'] ?? 0) !== 0) {
            $newHealth         = max(0.01, (float)$character['health'] + (float)$result['health_delta']);
            $update['health']  = $newHealth;
        }

        if (($result['tired_delta'] ?? 0) !== 0) {
            $newTired         = max(0.01, (float)$character['tired'] + (float)$result['tired_delta']);
            $update['tired']  = $newTired;
        }

        if (($result['gold_delta'] ?? 0) !== 0) {
            $update['gold'] = (int)$character['gold'] + (int)$result['gold_delta'];
        }

        foreach (($result['attribute_deltas'] ?? []) as $attr => $delta) {
            if (!isset($character[$attr])) {
                continue;
            }
            $newVal         = max(0.01, (float)$character[$attr] + (float)$delta);
            $update[$attr]  = $newVal;
        }

        if (!empty($update)) {
            $this->charModel->update($charId, $update);
        }

        // ============================================================
        // 2. Resource grant intents (RareResource, DungeonOpening)
        // ============================================================
        foreach (($result['resource_grant_intents'] ?? []) as $intent) {
            $this->applyResourceGrant($charId, $intent);
        }

        // ============================================================
        // 3. Resource loss percent (MeteorRain)
        // ============================================================
        if (($result['resource_loss_percent'] ?? 0) > 0) {
            $this->applyResourceLossPercent($charId, (float)$result['resource_loss_percent']);
        }

        // ============================================================
        // 4. Task extension intent (MirageOasis, PolarNight)
        // ============================================================
        if (!empty($result['task_extension_intent'])) {
            $this->applyTaskExtension($charId, $result['task_extension_intent']);
        }

        // ============================================================
        // 5. Reveal cells intent (MountainEcho)
        //    NB: dispatcher вирішує чи робити та як шле — тут не апалай.
        //    Залишається в result як hint для notification.
        // ============================================================

        // ============================================================
        // 6. Gather modifier (Dryness, Locust)
        //    Не апалай — це state, читається GatherTaskHandler через
        //    GatherEventModifierService (F2.7c вже існує).
        //    Залишається в active_events як state.
        // ============================================================

        // Перечитати оновлений character (для caller'а — наприклад end-summary)
        return $this->charModel->find($charId) ?? $character;
    }

    /**
     * Знайти resource по keyword/biome/rarity та додати в character_resources.
     *
     * @param array{keyword: ?string, biome_id: ?int, rarity: ?int, amount: int} $intent
     */
    private function applyResourceGrant(int $charId, array $intent): void
    {
        $query = $this->resourceModel;

        if (!empty($intent['keyword'])) {
            $query = $query->where('keyword', $intent['keyword']);
        }
        if (!empty($intent['biome_id'])) {
            $query = $query->where('biome_id', $intent['biome_id']);
        }
        if (isset($intent['rarity'])) {
            $query = $query->where('rarity', $intent['rarity']);
        }

        $candidates = $query->findAll();
        if (empty($candidates)) {
            return;
        }

        $resource = $candidates[array_rand($candidates)];
        $amount   = (int)($intent['amount'] ?? 1);

        $existing = $this->charResourceModel
            ->where('id_characters', $charId)
            ->where('id_resources', $resource['id'])
            ->first();

        if ($existing) {
            $this->charResourceModel->update($existing['id'], [
                'quantity' => (int)$existing['quantity'] + $amount,
            ]);
        } else {
            $this->charResourceModel->insert([
                'id_characters' => $charId,
                'id_resources'  => $resource['id'],
                'quantity'      => $amount,
            ]);
        }
    }

    /**
     * MeteorRain — % втрата у всіх ресурсів character'а.
     */
    private function applyResourceLossPercent(int $charId, float $percent): void
    {
        $resources = $this->charResourceModel
            ->where('id_characters', $charId)
            ->findAll();

        foreach ($resources as $r) {
            $oldQty = (int)$r['quantity'];
            if ($oldQty <= 1) {
                continue;
            }
            $loss   = (int)ceil($oldQty * ($percent / 100.0));
            $newQty = max(1, $oldQty - $loss);

            $this->charResourceModel->update($r['id'], ['quantity' => $newQty]);
        }
    }

    /**
     * MirageOasis / PolarNight — подовжити end_time активних задач.
     *
     * @param array{filter: list<string>, extra_minutes: int} $intent
     */
    private function applyTaskExtension(int $charId, array $intent): void
    {
        $filter = $intent['filter'] ?? ['Gather', 'ExploreTheArea'];
        $extra  = (int)($intent['extra_minutes'] ?? 0);
        if ($extra <= 0) {
            return;
        }

        // Знайти task_ids по name
        $taskIds = array_column(
            $this->taskModel->whereIn('name', $filter)->findAll(),
            'id'
        );
        if (empty($taskIds)) {
            return;
        }

        // Знайти активні character_tasks
        $activeTasks = $this->charTaskModel
            ->where('character_id', $charId)
            ->whereIn('task_id', $taskIds)
            ->where('status', 'in_work')
            ->findAll();

        foreach ($activeTasks as $task) {
            $newEndTime = date('Y-m-d H:i:s', strtotime($task['end_time'] . " +{$extra} minutes"));
            $this->charTaskModel->update($task['id'], ['end_time' => $newEndTime]);
        }
    }
}
