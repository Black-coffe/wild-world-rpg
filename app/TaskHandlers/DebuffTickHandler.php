<?php

declare(strict_types=1);

namespace App\TaskHandlers;

use App\Models\CharacterDebuffModel;
use App\Services\Player\CharacterStatsService;
use App\Services\Player\DebuffService;
use Config\Debuffs;

/**
 * Тик состояний, которые не лечатся едой: отравление отнимает здоровье, истёкшие
 * состояния закрываются.
 *
 * Запускается кроном каждую минуту (`Config\Tasks`), но реальная работа — только для
 * строк, у которых с прошлого удара прошло `debuff.poison.tick_minutes`.
 *
 * 🔴 Отравление НЕ убивает: здоровье опускается максимум до 1. Смерть в игре — своя
 * механика со своими последствиями (потеря части добычи, респавн), и заводить второй,
 * необъявленный путь к ней через фоновый тик нельзя: игрок не поймёт, за что наказан.
 * Яд делает игрока хрупким, добивает — мир.
 *
 * Killswitch `debuff.enabled` (через {@see DebuffService::enabled}) — при OFF handler
 * не делает ничего.
 */
class DebuffTickHandler
{
    private DebuffService $service;
    private CharacterDebuffModel $model;
    private CharacterStatsService $stats;

    public function __construct(
        ?DebuffService $service = null,
        ?CharacterDebuffModel $model = null,
        ?CharacterStatsService $stats = null
    ) {
        $this->service = $service ?? new DebuffService();
        $this->model   = $model   ?? new CharacterDebuffModel();
        $this->stats   = $stats   ?? new CharacterStatsService();
    }

    /**
     * @return array{expired:int, ticked:int, damage:int}
     */
    public function handle(): array
    {
        if (! $this->service->enabled()) {
            return ['expired' => 0, 'ticked' => 0, 'damage' => 0];
        }

        $expired = $this->service->expireDue();

        $tickMinutes = $this->service->poisonTickMinutes();
        $threshold   = date('Y-m-d H:i:s', time() - $tickMinutes * 60);

        $rows = $this->model
            ->where('debuff_key', Debuffs::POISON)
            ->where('cured_at', null)
            ->where('expired_at', null)
            ->groupStart()
                ->where('last_tick_at', null)
                ->orWhere('last_tick_at <=', $threshold)
            ->groupEnd()
            ->findAll();

        $ticked = 0;
        $damage = 0;

        foreach ($rows as $raw) {
            $row = is_array($raw) ? $raw : (array) $raw;

            $id       = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $charId   = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            $severity = is_numeric($row['severity'] ?? null) ? (int) $row['severity'] : 1;

            if ($id <= 0 || $charId <= 0) {
                continue;
            }

            $hit = $this->service->poisonDamagePerTick($severity);

            // Читаем текущее здоровье внутри той же атомарной операции: bounds не дают
            // уйти ниже 1 (яд не убивает — см. шапку класса).
            $result = $this->stats->adjust($charId, ['health' => -$hit], ['health' => ['min' => 1.0]]);

            $this->service->markTicked($id);
            $ticked++;

            if ($result !== null && isset($result['before']['health'], $result['after']['health'])) {
                $damage += (int) max(0, (float) $result['before']['health'] - (float) $result['after']['health']);
            }
        }

        return ['expired' => $expired, 'ticked' => $ticked, 'damage' => $damage];
    }
}
