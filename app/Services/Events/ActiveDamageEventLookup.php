<?php

declare(strict_types=1);

namespace App\Services\Events;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Config\WorldEvents;

/**
 * ADR-200 (спека sudden-death-1125, S2) — какие мировые события, бьющие по здоровью, идут сейчас в биоме.
 *
 * Источник — `active_events` (status `active`, `end_time` не прошёл) × `events.biome_ids` × вид эффекта из
 * {@see WorldEvents}. Событие с пустыми / NULL `biome_ids` глобальное — покрывает каждый биом (как в
 * `EventDispatcher::findAffectedPlayers()`). По здоровью бьёт `damage_health`, кроме цели `tired`
 * (Песчаная буря) и кроме Лихорадки в биоме, где её правило — только выносливость, а также `task_extend` с
 * побочным `side_effects.health_loss_range` (Миражи).
 *
 * Читает `GatherAction` (отказ начать добычу при низком здоровье в биоме события).
 */
class ActiveDamageEventLookup
{
    private WorldEvents $cfg;

    /** @var BaseConnection<object, object>|null */
    private ?BaseConnection $db;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?WorldEvents $cfg = null, ?BaseConnection $db = null)
    {
        $cfg ??= config('WorldEvents');
        $this->cfg = $cfg instanceof WorldEvents ? $cfg : new WorldEvents();
        $this->db  = $db;
    }

    /**
     * Активные события с уроном здоровью, покрывающие биом. Один раз на событие.
     *
     * @return list<array{event_id: int, name: string, name_english: string}>
     */
    public function forBiome(int $biomeId, ?string $biomeType = null): array
    {
        $db       = $this->db ?? Database::connect();
        $active   = $db->prefixTable('active_events');
        $events   = $db->prefixTable('events');
        $result   = $db->query(
            "SELECT e.event_id, e.name, e.name_english, e.biome_ids, e.handler_key
               FROM {$active} ae
               JOIN {$events} e ON e.event_id = ae.event_id
              WHERE ae.status = 'active' AND ae.end_time >= ?
              ORDER BY ae.id",
            [date('Y-m-d H:i:s')]
        );
        $rows = $result instanceof BaseResult ? $result->getResultArray() : [];

        $out = [];
        foreach ($rows as $row) {
            $eventId = is_numeric($row['event_id'] ?? null) ? (int) $row['event_id'] : 0;
            $nameEn  = is_string($row['name_english'] ?? null) ? $row['name_english'] : '';
            if ($eventId < 1 || isset($out[$eventId]) || ! self::coversBiome($row['biome_ids'] ?? null, $biomeId)) {
                continue;
            }
            $handlerKey = is_string($row['handler_key'] ?? null) ? $row['handler_key'] : null;
            if (! self::harmsHealth($this->cfg->get($nameEn), $handlerKey, $biomeType)) {
                continue;
            }
            $out[$eventId] = [
                'event_id'     => $eventId,
                'name'         => is_string($row['name'] ?? null) ? $row['name'] : $nameEn,
                'name_english' => $nameEn,
            ];
        }

        return array_values($out);
    }

    /**
     * Покрывает ли событие биом: пустые / NULL / битые `biome_ids` — глобальное событие.
     */
    public static function coversBiome(mixed $biomeIdsJson, int $biomeId): bool
    {
        if (! is_string($biomeIdsJson) || $biomeIdsJson === '') {
            return true;
        }
        $decoded = json_decode($biomeIdsJson, true);
        if (! is_array($decoded) || $decoded === []) {
            return true;
        }
        foreach ($decoded as $id) {
            if (is_numeric($id) && (int) $id === $biomeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Бьёт ли событие по здоровью в биоме этого типа.
     *
     * @param array<string, mixed>|null $config     строка {@see WorldEvents} события
     * @param string|null               $handlerKey `events.handler_key` (перекрывает `effect_kind` конфига)
     */
    public static function harmsHealth(?array $config, ?string $handlerKey, ?string $biomeType): bool
    {
        if ($config === null) {
            return false;
        }
        $kind   = $handlerKey !== null && $handlerKey !== '' ? $handlerKey : ($config['effect_kind'] ?? null);
        $params = is_array($config['effect_params'] ?? null) ? $config['effect_params'] : [];
        if ($kind !== 'damage_health') {
            // Побочный урон здоровью у событий другого рода (Миражи: task_extend + side_effects.health_loss_range),
            // как в EventMessageFormatter::healthHitRange() — ревью раунд 1, ask 3.
            $side = is_array($params['side_effects'] ?? null) ? $params['side_effects'] : [];

            return $kind === 'task_extend' && array_key_exists('health_loss_range', $side);
        }
        $target = $params['damage_target'] ?? 'health';

        if ($target === 'tired') {
            return false;
        }
        if ($target === 'biome_type_specific') {
            $spec  = is_array($params['biome_type_specific'] ?? null) ? $params['biome_type_specific'] : [];
            $rules = $spec[$biomeType ?? 'default'] ?? $spec['default'] ?? [];

            return is_array($rules) && array_key_exists('health', $rules);
        }

        return true;
    }
}
