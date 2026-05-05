<?php

namespace App\Services\Events;

use App\Models\ActiveEventModel;
use App\Models\CharacterModel;
use App\Models\EventModel;
use Config\WorldEvents;
use Throwable;

/**
 * F7.4/F7.5 — обробка завершення подіи: читаесть накопичений effect_log
 * та делегуесть end-summary в NotificationPolicy (F7.5).
 *
 * Вызовається с EventActivationHandler::updateExpiredEventsStatus()
 * перед тим як event row перейде в status='completed'.
 */
final class EventCloseHandler
{
    private WorldEvents $cfg;
    private ActiveEventModel $activeEventModel;
    private EventModel $eventModel;
    private CharacterModel $charModel;
    private EffectAccumulator $accumulator;
    private NotificationPolicy $policy;

    public function __construct(
        ?WorldEvents $cfg = null,
        ?ActiveEventModel $activeEventModel = null,
        ?EventModel $eventModel = null,
        ?CharacterModel $charModel = null,
        ?EffectAccumulator $accumulator = null,
        ?NotificationPolicy $policy = null,
    ) {
        $this->cfg              = $cfg              ?? config('WorldEvents');
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
        $this->eventModel       = $eventModel       ?? new EventModel();
        $this->charModel        = $charModel        ?? new CharacterModel();
        $this->accumulator      = $accumulator      ?? new EffectAccumulator($this->activeEventModel);
        $this->policy           = $policy           ?? new NotificationPolicy(
            $this->cfg,
            $this->charModel,
        );
    }

    /**
     * Закрыти подію: прочитать effect_log → отправитьи summary каждому
     * игрокв → повернути stats.
     *
     * @return array{summaries_sent: int, summaries_skipped: int, errors: int}
     */
    public function closeEvent(array $activeEvent): array
    {
        $stats = ['summaries_sent' => 0, 'summaries_skipped' => 0, 'errors' => 0];

        $eventRow = $this->eventModel->find((int)$activeEvent['event_id']);
        if ($eventRow === null) {
            return $stats;
        }

        $config = $this->cfg->get($eventRow['name_english']);
        if ($config === null) {
            return $stats;
        }

        $accumulated = $this->accumulator->readForEvent((int)$activeEvent['id']);
        if (empty($accumulated)) {
            return $stats;
        }

        foreach ($accumulated as $charId => $agg) {
            try {
                if ($this->isEmpty($agg)) {
                    $stats['summaries_skipped']++;
                    continue;
                }

                $char = $this->charModel->find($charId);
                if ($char === null) {
                    $stats['summaries_skipped']++;
                    continue;
                }

                if ($this->policy->sendEnd($char, $eventRow, $config, $agg)) {
                    $stats['summaries_sent']++;
                } else {
                    $stats['summaries_skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                log_message('error', "[EventCloseHandler] char_id={$charId}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Если агрегат «порожній» (нічого корисного для summary).
     */
    private function isEmpty(array $agg): bool
    {
        $hasDeltas = ($agg['health_delta'] ?? 0) !== 0
            || ($agg['tired_delta'] ?? 0) !== 0
            || ($agg['gold_delta']  ?? 0) !== 0
            || !empty($agg['attribute_deltas'])
            || !empty($agg['resource_grants']);
        return !$hasDeltas;
    }
}
