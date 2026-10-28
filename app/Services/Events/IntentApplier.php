<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Player\CharacterStatsService;

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
        private CharacterStatsService $statsService = new CharacterStatsService(),
    ) {
    }

    /**
     * Застосувати ввесь EffectResult intent-array до конкретного character'а.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character Поточний row с characters
     * @param array<string, mixed> $result    EffectResult с effect.compute()
     * @return array<string, mixed>|\App\Entities\CharacterEntity Оновлений character (перечитаний с DB после writes)
     */
    public function apply(array|\App\Entities\CharacterEntity $character, array $result): array|\App\Entities\CharacterEntity
    {
        $charId = (int)$character['id'];

        // ============================================================
        // 1. Health / Tired / Gold / Attribute deltas
        //    s2-conditional-writes-06 (F16): дельтой через CharacterStatsService::adjust()
        //    (FOR UPDATE от свежих значений), не абсолютом со снимка $character —
        //    два события от одного снимка складываются, золото зажато ≥ 0 дефолтом
        //    сервиса; здоровье/усталость/атрибуты — пол 0.01, как было.
        // ============================================================
        $deltas = [];
        $bounds = [];

        $healthDelta = $result['health_delta'] ?? 0;
        if (is_numeric($healthDelta) && (float)$healthDelta != 0.0) {
            $deltas['health'] = (float)$healthDelta;
            $bounds['health'] = ['min' => 0.01];
        }

        $tiredDelta = $result['tired_delta'] ?? 0;
        if (is_numeric($tiredDelta) && (float)$tiredDelta != 0.0) {
            $deltas['tired'] = (float)$tiredDelta;
            $bounds['tired'] = ['min' => 0.01];
        }

        $goldDelta = $result['gold_delta'] ?? 0;
        if (is_numeric($goldDelta) && (int)$goldDelta !== 0) {
            $deltas['gold'] = (int)$goldDelta;
        }

        $attributeDeltas = $result['attribute_deltas'] ?? [];
        foreach (is_array($attributeDeltas) ? $attributeDeltas : [] as $attr => $delta) {
            if (!is_string($attr) || !is_numeric($delta) || !isset($character[$attr])) {
                continue;
            }
            $deltas[$attr] = (float)$delta;
            $bounds[$attr] = ['min' => 0.01];
        }

        if ($deltas !== []) {
            $this->statsService->adjust($charId, $deltas, $bounds);
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

        if ($amount <= 0) {
            return;
        }

        $resourceIdRaw = $resource['id'];
        $resourceId    = is_numeric($resourceIdRaw) ? (int)$resourceIdRaw : 0;
        if ($resourceId <= 0) {
            return;
        }

        // F16: относительная запись — строку только находим (какую из дублей),
        // количество не читаем: `quantity = quantity + ?` по id; строки нет — вставка.
        $existing = $this->charResourceModel
            ->where('id_characters', $charId)
            ->where('id_resources', $resourceId)
            ->first();

        $existingId = is_array($existing) && is_numeric($existing['id'] ?? null) ? (int)$existing['id'] : 0;
        $writer     = new ConditionalWriteService();
        if ($existingId > 0
            && $writer->increment('character_resources', ['id' => $existingId], 'quantity', $amount) === WriteOutcome::Applied
        ) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $outcome = $writer->insertUnique('character_resources', [
            'id_characters' => $charId,
            'id_resources'  => $resourceId,
            'quantity'      => $amount,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        if ($outcome === WriteOutcome::Refused) {
            // Строку вставил соседний запрос между first() и вставкой — начислить в неё.
            $writer->increment('character_resources', ['id_characters' => $charId, 'id_resources' => $resourceId], 'quantity', $amount);
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

        $db    = \Config\Database::connect();
        $table = $db->prefixTable('character_resources');

        foreach ($resources as $r) {
            if (!is_array($r) || !is_numeric($r['quantity'] ?? null) || !is_numeric($r['id'] ?? null)) {
                continue;
            }
            $oldQty = (int)$r['quantity'];
            if ($oldQty <= 1) {
                continue;
            }
            // Величина потери — как была (ceil % от прочитанного, не ниже остатка 1);
            // запись — относительное списание, зажатое в SQL: устаревшее чтение
            // не уводит остаток ниже 0 и не перетирает чужую запись в ту же строку.
            $loss = min((int)ceil($oldQty * ($percent / 100.0)), $oldQty - 1);
            if ($loss <= 0) {
                continue;
            }

            $db->query(
                "UPDATE {$table} SET quantity = quantity - LEAST(quantity, ?), updated_at = ? WHERE id = ?",
                [$loss, date('Y-m-d H:i:s'), (int)$r['id']]
            );
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
