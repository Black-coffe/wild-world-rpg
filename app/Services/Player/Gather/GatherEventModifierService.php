<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Models\ActiveEventModel;
use App\Models\EventModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * F2.7c — батч-загрузка активных событий + применение модификаторов добычи.
 *
 * Решает duplicate-query проблему в GatherTaskHandler::handle +
 * calculateFoundResources: legacy делал ~12 SQL на одну добычу
 *   - isEventActive('LocustExodus')           = 2 SQL (event row + active_event)
 *   - eventModel->where('LocustExodus')       = 1 SQL (повторно)
 *   - + те же 3×3 для FishStock/ExoticFlowering/BerryBoom
 *   - + isDrynessEventActive() + drynessEvent = 3 SQL
 *
 * После: 2 batch SQL единожды (events.whereIn + active_events.whereIn).
 *
 * Дополнительно консолидирует логику применения модификаторов:
 *   - %-бонус за активное событие к ресурсу с конкретным name
 *     (FishStock→Рыба, ExoticFlowering→Цветы орхидей, BerryBoom→Ягоды)
 *   - %-штраф LocustExodus ко всем ресурсам
 *   - Dryness — отдельная penalty для type содержащего 'water'
 *
 * Контракт 1:1 с legacy GatherTaskHandler v0.7.0.
 */
final class GatherEventModifierService
{
    /** Фиксированный набор событий, релевантных добыче. */
    public const RELEVANT_EVENTS = [
        'LocustExodus',
        'FishStock',
        'ExoticFlowering',
        'BerryBoom',
        'Dryness',
        'NovayaEra', // E32 — глобальный праздничный буст добычи (зеркало LocustExodus, положительное).
    ];

    /**
     * Маппинг event_name_english → resource name (на русском, как в БД).
     * Используется в applyResourceModifiers для %-бонуса.
     */
    private const EVENT_RESOURCE_MAP = [
        'FishStock'       => 'Рыба',
        'ExoticFlowering' => 'Цветы орхидей',
        'BerryBoom'       => 'Ягоды',
    ];

    private EventModel $eventModel;
    private ActiveEventModel $activeEventModel;
    private GameSettingsService $settings;

    /** Кэш killswitch'а «Новой эры» на время жизни сервиса (одна добыча). */
    private ?bool $novayaEraEnabledCache = null;

    public function __construct(
        ?EventModel $eventModel = null,
        ?ActiveEventModel $activeEventModel = null,
        ?GameSettingsService $settings = null
    ) {
        $this->eventModel       = $eventModel       ?? new EventModel();
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
        $this->settings         = $settings         ?? new GameSettingsService();
    }

    /**
     * Один batch — загружает все relevant события и проверяет каждое
     * на наличие активной записи в active_events.
     *
     * Возвращает map [name_english => ['active'=>bool, 'effect_value'=>float]].
     * Несуществующие или неактивные события возвращаются с active=false.
     */
    public function loadActiveEvents(array $names = self::RELEVANT_EVENTS): array
    {
        if (empty($names)) {
            return [];
        }

        $events = $this->eventModel
            ->whereIn('name_english', $names)
            ->findAll();
        if (empty($events)) {
            return $this->makeAllInactive($names);
        }

        $eventIds   = [];
        $rowsByName = [];
        foreach ($events as $row) {
            $eventIds[] = (int) $row['event_id'];
            $rowsByName[$row['name_english']] = $row;
        }

        $activeRows = $this->activeEventModel
            ->whereIn('event_id', $eventIds)
            ->where('status', 'active')
            ->findAll();
        $activeIds = [];
        foreach ($activeRows as $row) {
            $activeIds[(int) $row['event_id']] = true;
        }

        $result = [];
        foreach ($names as $name) {
            if (!isset($rowsByName[$name])) {
                $result[$name] = ['active' => false, 'effect_value' => 0.0];
                continue;
            }
            $eventId = (int) $rowsByName[$name]['event_id'];
            $result[$name] = [
                'active'       => isset($activeIds[$eventId]),
                'effect_value' => (float) ($rowsByName[$name]['effect_value'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Применяет per-resource %-модификаторы к amount.
     *   - bonus event (FishStock/ExoticFlowering/BerryBoom): +effect_value%
     *     если resourceName совпадает с EVENT_RESOURCE_MAP.
     *   - LocustExodus penalty: -effect_value% ко всем ресурсам.
     *
     * НЕ применяет Dryness (там per-type логика — отдельный метод).
     */
    public function applyResourceModifiers(float $amount, string $resourceName, array $loadedEvents): float
    {
        foreach (self::EVENT_RESOURCE_MAP as $eventName => $targetResource) {
            if ($resourceName !== $targetResource) {
                continue;
            }
            $event = $loadedEvents[$eventName] ?? null;
            if ($event === null || !$event['active']) {
                continue;
            }
            $amount *= (1 + $event['effect_value'] / 100.0);
        }

        $locust = $loadedEvents['LocustExodus'] ?? null;
        if ($locust !== null && $locust['active']) {
            $amount *= (1 - $locust['effect_value'] / 100.0);
        }

        // E32 «Новая эра» — глобальный праздничный буст: +effect_value% ко ВСЕМ ресурсам,
        // пока активно одноимённое событие. Зеркало LocustExodus (глобальный множитель по
        // всему выхлопу), но положительное. Читается в момент добычи (не tick-эффект) →
        // нет one_shot-компаундинга (урок ADR-141). Killswitch events.novaya_era.enabled —
        // аварийный офф; ИСТИННЫЙ гейт активации — наличие active_events-строки события
        // (без неё loadedEvents['NovayaEra']['active']=false, ветка не срабатывает, byte-identical).
        $novaya = $loadedEvents['NovayaEra'] ?? null;
        if ($novaya !== null && $novaya['active'] && $this->isNovayaEraEnabled()) {
            $amount *= (1 + $novaya['effect_value'] / 100.0);
        }

        return $amount;
    }

    /**
     * E32 — killswitch буста «Новой эры» (аварийный офф без деплоя). Кэшируется на
     * время жизни сервиса (одна добыча вызывает applyResourceModifiers на каждый ресурс).
     * Дефолт true: настоящий гейт активации — active_events-строка, флаг лишь глушит буст
     * при уже активном событии.
     */
    private function isNovayaEraEnabled(): bool
    {
        if ($this->novayaEraEnabledCache !== null) {
            return $this->novayaEraEnabledCache;
        }

        $v = $this->settings->get('events.novaya_era.enabled', true);
        if (is_bool($v)) {
            return $this->novayaEraEnabledCache = $v;
        }
        if (is_numeric($v)) {
            return $this->novayaEraEnabledCache = ((int) $v === 1);
        }

        return $this->novayaEraEnabledCache = in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Засуха: -effect_value% ко всем ресурсам где type содержит 'water'.
     * Минимум 1 unit гарантирован (не уходим в 0).
     *
     * @param list<array{resource_id:int,amount:int,type:string}> $foundResources
     * @return list<array{resource_id:int,amount:int,type:string}>
     */
    public function applyDrynessPenalty(array $foundResources, array $loadedEvents): array
    {
        $dryness = $loadedEvents['Dryness'] ?? null;
        if ($dryness === null || !$dryness['active']) {
            return $foundResources;
        }
        $penalty = 1 - ($dryness['effect_value'] / 100.0);

        foreach ($foundResources as &$res) {
            if (isset($res['type']) && str_contains($res['type'], 'water')) {
                $res['amount'] = (int) round($res['amount'] * $penalty);
                if ($res['amount'] < 1) {
                    $res['amount'] = 1;
                }
            }
        }
        unset($res);
        return $foundResources;
    }

    private function makeAllInactive(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $result[$name] = ['active' => false, 'effect_value' => 0.0];
        }
        return $result;
    }
}
