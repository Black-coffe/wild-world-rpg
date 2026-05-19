<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Attributes\HandlerKey;
use App\Services\Events\EventEffectInterface;
use App\Services\GameSettings\GameSettingsService;

/**
 * F7.2 — видача rare ресурсу. Використовується DungeonOpening (RevealingHiddenCameras),
 * BerryBoom, FishStock, ExoticFlowering.
 *
 * Pure-strategy: повертає `resource_grant_intents` (intent), а dispatcher F7.3
 * робить SQL-resolution: знаходить ResourceModel rows за keyword/biome_id/rarity
 * і додає в character_resources.
 *
 * Підтримувані params:
 *   - resource_keyword   : string  — фільтр resources.keyword
 *   - rarity_filter      : ?int    — null або 1
 *   - amount_range       : [min, max]
 *   - chance_per_tick    : float
 *   - requires_state     : 'gather' | null *   - biome_type_filter  : 'cave' | null — додатковий фільтр для DungeonOpening
 */
#[HandlerKey(
    key: 'rare_resource_grant',
    displayName: 'Редкий ресурс',
    description: 'Выдаёт редкий ресурс по keyword/rarity/biome_type (BerryBoom, FishStock, ExoticFlowering, RevealingHiddenCameras).',
    inputSchema: [
        ['name' => 'resource_keyword', 'type' => 'string'],
        ['name' => 'rarity_filter', 'type' => 'int', 'min' => 1, 'max' => 5],
        ['name' => 'amount_range', 'type' => 'int_range', 'min' => 1, 'max' => 100],
        ['name' => 'chance_per_tick', 'type' => 'float', 'min' => 0.0, 'max' => 1.0],
        ['name' => 'requires_state', 'type' => 'enum', 'values' => ['gather', 'explore']],
        ['name' => 'biome_type_filter', 'type' => 'string'],
    ],
)]
final class RareResourceGrantEffect implements EventEffectInterface
{
    /** @var (callable(string,mixed):mixed)|null */
    private $tunableReader;

    /**
     * @param (callable(string,mixed):mixed)|null $tunableReader  Tests injection point;
     *   у проді null → lazy `new GameSettingsService()->get($key, $default)`.
     *   GameSettingsService — final, тому extend не варіант; callable cleaner.
     */
    public function __construct(?callable $tunableReader = null)
    {
        $this->tunableReader = $tunableReader;
    }

    public function compute(array|\App\Entities\CharacterEntity $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params    = $eventConfig['effect_params'] ?? [];
        $eventName = isset($activeEvent['name_english']) && is_string($activeEvent['name_english'])
            ? $activeEvent['name_english']
            : null;

        // S10: live-tunable через GameSettings (key prefix `rare_event.<name_english>.`).
        // Fallback cascade: GameSettings → effect_params (Config\WorldEvents) → hardcoded default.
        $defaultChance = (float)($params['chance_per_tick'] ?? 0.10);
        $chance        = $this->tunable("rare_event.{$eventName}.chance_per_tick", $defaultChance, $eventName);

        if (mt_rand(1, 10000) / 10000.0 > $chance) {
            return EffectResultFactory::skipped("Не випало (chance={$chance})");
        }

        // Фільтр: requires_state
        $reqState = $params['requires_state'] ?? null;
        if ($reqState === 'gather' && empty($context['is_gathering'])) {
            return EffectResultFactory::skipped('Не gathering');
        }

        // Фільтр: biome_type_filter (наприклад, 'cave' для DungeonOpening)
        $biomeFilter = $params['biome_type_filter'] ?? null;
        if ($biomeFilter !== null) {
            $playerBiomeType = $context['biome']['biome_type'] ?? null;
            if ($playerBiomeType !== $biomeFilter) {
                return EffectResultFactory::skipped("Не той biome_type ({$playerBiomeType} != {$biomeFilter})");
            }
        }

        // Готуємо intent — amount_range також live-tunable через GameSettings.
        $amountRange   = $params['amount_range'] ?? [1, 3];
        $defaultMin    = (int)$amountRange[0];
        $defaultMax    = (int)$amountRange[1];
        $amountMin     = (int)$this->tunable("rare_event.{$eventName}.amount_min", $defaultMin, $eventName);
        $amountMax     = (int)$this->tunable("rare_event.{$eventName}.amount_max", $defaultMax, $eventName);
        if ($amountMax < $amountMin) {
            $amountMax = $amountMin;
        }
        $amount = mt_rand($amountMin, $amountMax);

        $intent = [
            'keyword'  => $params['resource_keyword'] ?? null,
            'biome_id' => (int)($character['biome_id'] ?? 0) ?: null,
            'rarity'   => $params['rarity_filter'] ?? null,
            'amount'   => $amount,
        ];

        return EffectResultFactory::make([
            'applied'                => true,
            'resource_grant_intents' => [$intent],
            'log_summary'            => "+{$amount} {$intent['keyword']}",
            'magnitude'              => [
                'effect_kind'    => 'rare_resource_grant',
                'rare_item_drop' => true,
                'keyword'        => $intent['keyword'],
                'amount'         => $amount,
            ],
        ]);
    }

    /**
     * Прочитати tunable значення з GameSettings; fallback на $default коли:
     *  - $eventName == null (no event_key — старий call-site, наприклад unit-тест без enrichedActive)
     *  - GameSettingsService не змонтовано (DI без сервіс-граф'у)
     *  - запис у `game_settings` відсутній
     */
    private function tunable(string $key, float|int $default, ?string $eventName): float|int
    {
        if ($eventName === null) {
            return $default;
        }
        $reader = $this->tunableReader ?? static fn (string $k, mixed $d): mixed
            => (new GameSettingsService())->get($k, $d);
        $val    = $reader($key, $default);

        return is_numeric($val) ? ($val + 0) : $default;
    }
}
