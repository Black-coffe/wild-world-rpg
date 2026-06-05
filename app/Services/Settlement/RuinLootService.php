<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\CharacterRuinLootModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-101 Фаза 4 — повторяемый лут охраняемых руин (type=ruins).
 *
 * Гейт: killswitch `settlements.ruins.enabled` + кулдаун `settlements.ruins.loot_cooldown_hours`
 * (per character × ruin, таблица character_ruin_loot). Лут читается из settlements.loot_config
 * (JSON {"resources":{name_en:maxAmount}}), величина × `settlements.ruins.loot_amount_mult`.
 * Выдача — ResourceModel::addOrIncreaseResource (тот же путь, что StrategicLootHandler).
 *
 * Стража (опасность) — ОТДЕЛЬНЫЙ слой (AutoPveHandler по hostile-спавнам), лут ею НЕ гейтится:
 * «повторяемый, но дорогой (cooldown/риск)» — риск = страж бьёт, пока ты в руинах.
 */
final class RuinLootService
{
    private GameSettingsService $settings;
    private CharacterRuinLootModel $log;
    private ResourceModel $resources;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?CharacterRuinLootModel $log = null,
        ?ResourceModel $resources = null
    ) {
        $this->settings  = $settings ?? new GameSettingsService();
        $this->log       = $log ?? new CharacterRuinLootModel();
        $this->resources = $resources ?? new ResourceModel();
    }

    public function enabled(): bool
    {
        $v = $this->settings->get('settlements.ruins.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function cooldownHours(): int
    {
        $v = $this->settings->get('settlements.ruins.loot_cooldown_hours', 12);

        return is_numeric($v) ? max(0, (int) $v) : 12;
    }

    public function amountMult(): float
    {
        $v = $this->settings->get('settlements.ruins.loot_amount_mult', 1.0);

        return is_numeric($v) && (float) $v > 0 ? (float) $v : 1.0;
    }

    /**
     * loot_config JSON → карта name_en => maxAmount (только положительные целые).
     *
     * @return array<string, int>
     */
    public static function parseLootConfig(?string $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }
        $dec = json_decode($json, true);
        if (! is_array($dec) || ! isset($dec['resources']) || ! is_array($dec['resources'])) {
            return [];
        }
        $out = [];
        foreach ($dec['resources'] as $name => $max) {
            if (is_string($name) && $name !== '' && is_numeric($max) && (int) $max > 0) {
                $out[$name] = (int) $max;
            }
        }

        return $out;
    }

    /** Максимум выдачи ресурса после множителя (≥1). */
    public function maxForResource(int $baseMax): int
    {
        return max(1, (int) floor($baseMax * $this->amountMult()));
    }

    /** Остаток кулдауна (сек) для пары (char, ruin); 0 = готово к луту. */
    public function remainingCooldownSeconds(int $characterId, int $settlementId, ?int $nowTs = null): int
    {
        $last = $this->log->lastLootedAt($characterId, $settlementId);
        if ($last === null) {
            return 0;
        }
        $lastTs = strtotime($last);
        if ($lastTs === false) {
            return 0;
        }
        $nowTs ??= time();
        $readyTs = $lastTs + $this->cooldownHours() * 3600;

        return max(0, $readyTs - $nowTs);
    }

    /**
     * Попытка лута руины. Не гейтит стражей (ambient-опасность отдельно).
     *
     * @param array<int|string, mixed> $settlement строка settlements (нужны id, loot_config)
     * @return array{ok: bool, reason?: string, remaining?: int, awarded?: array<string,int>}
     */
    public function loot(int $characterId, array $settlement): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        $sid = is_numeric($settlement['id'] ?? null) ? (int) $settlement['id'] : 0;
        if ($sid <= 0) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $remaining = $this->remainingCooldownSeconds($characterId, $sid);
        if ($remaining > 0) {
            return ['ok' => false, 'reason' => 'cooldown', 'remaining' => $remaining];
        }

        $loot = self::parseLootConfig(is_string($settlement['loot_config'] ?? null) ? $settlement['loot_config'] : null);
        if ($loot === []) {
            return ['ok' => false, 'reason' => 'empty'];
        }

        $awarded = [];
        foreach ($loot as $name => $baseMax) {
            $max    = $this->maxForResource($baseMax);
            $amount = random_int(1, $max);
            if ($this->resources->addOrIncreaseResource($characterId, $name, $amount) !== false) {
                $awarded[$name] = $amount;
            }
        }

        $this->log->touch($characterId, $sid, date('Y-m-d H:i:s'));

        return ['ok' => true, 'awarded' => $awarded];
    }
}
