<?php

namespace App\Services\Events;

use App\Models\ActiveEventModel;

/**
 * F7.4 — accumulator per-character deltas в active_events.effect_log JSON.
 *
 * Замість того щоб шлити tick-нотіфікацію на кожен dispatch, EventDispatcher
 * накопичує deltas сюди → EventCloseHandler читає при event end → формує
 * ОДНУ end-summary з агрегатом ("за подію Hurricane ти втратив сумарно 23 HP").
 *
 * Структура effect_log JSON:
 * ```json
 * {
 *     "486": {
 *         "health_delta": -23.5,
 *         "tired_delta": -10.0,
 *         "gold_delta": 0,
 *         "attribute_deltas": {"agility": 0.05},
 *         "resource_grants": [{"keyword": "berry", "amount": 4}],
 *         "applied_count": 5,
 *         "first_applied_at": "2026-05-05 14:00:00",
 *         "last_applied_at":  "2026-05-05 14:25:00",
 *         "magnitudes": [{"hp_loss_pct": 5.2}, ...]
 *     },
 *     "489": {...}
 * }
 * ```
 */
final class EffectAccumulator
{
    private ActiveEventModel $activeEventModel;

    public function __construct(?ActiveEventModel $activeEventModel = null)
    {
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
    }

    /**
     * Накопичити result в effect_log поточного active_event для гравця.
     */
    public function accumulate(int $activeEventId, int $charId, array $result): void
    {
        if (empty($result['applied'])) {
            return;
        }

        $row = $this->activeEventModel->find($activeEventId);
        if ($row === null) {
            return;
        }

        $log = $this->decodeLog($row['effect_log'] ?? null);
        $key = (string)$charId;
        $now = date('Y-m-d H:i:s');

        $existing = $log[$key] ?? null;

        if ($existing === null) {
            $log[$key] = [
                'health_delta'     => (float)($result['health_delta'] ?? 0),
                'tired_delta'      => (float)($result['tired_delta']  ?? 0),
                'gold_delta'       => (int)  ($result['gold_delta']   ?? 0),
                'attribute_deltas' => (array)($result['attribute_deltas'] ?? []),
                'resource_grants'  => $this->normalizeGrants($result),
                'applied_count'    => 1,
                'first_applied_at' => $now,
                'last_applied_at'  => $now,
            ];
        } else {
            $existing['health_delta']  = (float)$existing['health_delta'] + (float)($result['health_delta'] ?? 0);
            $existing['tired_delta']   = (float)$existing['tired_delta']  + (float)($result['tired_delta']  ?? 0);
            $existing['gold_delta']    = (int)  $existing['gold_delta']   + (int)  ($result['gold_delta']   ?? 0);
            $existing['attribute_deltas'] = $this->mergeAttributeDeltas(
                $existing['attribute_deltas'] ?? [],
                $result['attribute_deltas'] ?? []
            );
            $existing['resource_grants']  = array_merge(
                $existing['resource_grants'] ?? [],
                $this->normalizeGrants($result)
            );
            $existing['applied_count']    = (int)$existing['applied_count'] + 1;
            $existing['last_applied_at']  = $now;
            $log[$key] = $existing;
        }

        $this->activeEventModel->update($activeEventId, [
            'effect_log' => json_encode($log, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Прочитати акумульований лог для конкретного active_event.
     *
     * @return array<int, array<string, mixed>> map char_id → aggregated deltas
     */
    public function readForEvent(int $activeEventId): array
    {
        $row = $this->activeEventModel->find($activeEventId);
        if ($row === null) {
            return [];
        }

        $log = $this->decodeLog($row['effect_log'] ?? null);

        // Повертаємо з int-ключами для зручності caller'а
        $out = [];
        foreach ($log as $charKey => $agg) {
            $out[(int)$charKey] = $agg;
        }
        return $out;
    }

    /**
     * Перевірити, чи дано start-нотіфікацію конкретному гравцеві в цій події.
     * Використовується для one_shot_at_start логіки.
     */
    public function isUserNotified(int $activeEventId, int $charId): bool
    {
        $row = $this->activeEventModel->find($activeEventId);
        if ($row === null) {
            return false;
        }
        $list = $this->decodeLog($row['notified_users'] ?? null);
        return in_array($charId, array_map('intval', array_values((array)$list)), true);
    }

    /**
     * Позначити гравця як notified (start- або one_shot нотіфікація вже відправлена).
     */
    public function markUserNotified(int $activeEventId, int $charId): void
    {
        $row = $this->activeEventModel->find($activeEventId);
        if ($row === null) {
            return;
        }
        $list = (array)$this->decodeLog($row['notified_users'] ?? null);

        if (!in_array($charId, array_map('intval', $list), true)) {
            $list[] = $charId;
            $this->activeEventModel->update($activeEventId, [
                'notified_users' => json_encode($list, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * @return array<string|int, mixed>
     */
    private function decodeLog(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Витягнути resource_grant_intents з compute-result для збереження.
     * Тільки {keyword, amount} — biome_id не критичний для summary.
     *
     * @return list<array{keyword: ?string, amount: int}>
     */
    private function normalizeGrants(array $result): array
    {
        $intents = $result['resource_grant_intents'] ?? [];
        $out     = [];
        foreach ($intents as $i) {
            $out[] = [
                'keyword' => $i['keyword'] ?? null,
                'amount'  => (int)($i['amount'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Поелементне додавання attribute deltas (могло бути strength у двох tick'ах).
     *
     * @param array<string, float|int> $a
     * @param array<string, float|int> $b
     * @return array<string, float>
     */
    private function mergeAttributeDeltas(array $a, array $b): array
    {
        $out = [];
        foreach ([$a, $b] as $arr) {
            foreach ($arr as $attr => $val) {
                $out[$attr] = ($out[$attr] ?? 0.0) + (float)$val;
            }
        }
        return $out;
    }
}
