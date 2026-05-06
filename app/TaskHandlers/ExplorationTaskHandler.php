<?php

namespace App\TaskHandlers;

use App\Models\CharacterTaskModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\WorldObjectModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerDetectionService;
use Config\GameBalance;

/**
 * v0.51.22 (F2.9 batch-4): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Telegram lazy-init, Request::sendMessage → safeSendMessage.
 * `handle(array $task)` → `handle(array $task = []): void`.
 *
 * v0.51.27 (C/F6 expansion + perf):
 * - explorationXpBonus / explorationStatBonus / explorationPlannedTolerance /
 *   explorationFadePercentPer10Min / explorationFadePercentMax → GameBalance.
 * - getCellsOnRing N+1 fix: bounding-box query + biome map preload.
 */
class ExplorationTaskHandler extends BaseTaskHandler
{
    private PlayerDetectionService $playerDetectionService;
    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
        // Сервис PvP (обнаружения игроков)
        $this->playerDetectionService = new PlayerDetectionService();
    }

    /**
     * @param array<string,mixed> $task — запис з character_tasks
     */
    public function handle(array $task = []): void
    {
        // 1) Завершаем задачу
        $characterTaskModel = new CharacterTaskModel();
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Достаём персонажа
        $characterModel = new CharacterModel();
        $character      = $characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', 'Персонаж не найден при завершении ExplorationTask.');
            return;
        }

        // 3) Небольшое улучшение характеристик (v0.51.27: GameBalance wire-in)
        $xpBonus   = $this->cfg->explorationXpBonus;
        $statBonus = $this->cfg->explorationStatBonus;
        $characterModel->update($character['id'], [
            'experience' => $character['experience'] + $xpBonus,
            'strength'   => $character['strength']   + $statBonus,
            'agility'    => $character['agility']    + $statBonus,
            'intellect'  => $character['intellect']  + $statBonus,
        ]);

        // 4) Узнаём, сколько минут планировал (chosen_minutes) и сколько реально прошло
        $taskSettings   = json_decode($task['task_settings'] ?? '{}', true);
        $plannedMinutes = $taskSettings['chosen_minutes'] ?? 10; // по умолчанию 10

        $startTime = new \DateTime($task['start_time']);
        $endTime   = new \DateTime($task['end_time']);
        $diff      = $startTime->diff($endTime);
        $actualSpent= ($diff->days * 1440) + ($diff->h * 60) + $diff->i;

        // Tolerance — якщо |delta| ≤ N — беремо planned, інакше actual.
        $delta = abs($actualSpent - $plannedMinutes);
        $finalMinutes = ($delta <= $this->cfg->explorationPlannedTolerance) ? $plannedMinutes : $actualSpent;

        // 5) Считаем сырое количество ячеек (1 мин = 1 ячейка)
        $rawCells = $finalMinutes;

        // 6) Фейд: каждые 10 мин => -fadePercentPer10Min%
        $blocks = intdiv($finalMinutes, 10);
        $fadePercent = $blocks * $this->cfg->explorationFadePercentPer10Min;
        if ($fadePercent > $this->cfg->explorationFadePercentMax) {
            $fadePercent = $this->cfg->explorationFadePercentMax;
        }
        // Итог с учётом фейда
        $cellsAfterFade = (int) round($rawCells * (1.0 - $fadePercent / 100.0));
        if ($cellsAfterFade < 0) {
            $cellsAfterFade = 0;
        }

        // 7) Получаем координаты персонажа
        $mapModel    = new MapModel();
        $teleUserModel= new TelegramUserModel();
        $chatRow     = $teleUserModel->find($task['telegram_user_id']);
        if (!$chatRow) {
            log_message('error', 'Не найден Telegram user для результата.');
            return;
        }
        $chatId = $chatRow['telegram_id'];

        $playerCell = $mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$playerCell) {
            log_message('error',"Ячейка персонажа cell_number={$character['cell_number']} не найдена.");
            return;
        }

        $startX = $playerCell['coordinate_x'];
        $startY = $playerCell['coordinate_y'];

        // 8) Обход кольцами, пока не израсходуем cellsAfterFade
        $limit  = $cellsAfterFade;
        $radius = 0;
        $newCells= [];
        $exploredCellsModel= new ExploredCellsModel();
        $biomeModel = new BiomeModel();

        // v0.51.27 perf: pre-load усі biomes (~9 rows у DB) як id→row map.
        // Раніше: per-cell biomeModel->find($id) усередині getCellsOnRing × 4 sides.
        $allBiomes = $biomeModel->findAll();
        $biomesById = [];
        foreach ($allBiomes as $b) {
            $biomesById[(int) $b['id']] = $b;
        }

        while ($limit>0 && $radius<2000) {
            $radius++;
            $ringCells = $this->getCellsOnRing($startX, $startY, $radius, $mapModel, $biomesById);
            foreach($ringCells as $cell){
                if ($limit<=0) {
                    break;
                }
                // Смотрим, не изучена ли
                $found = $exploredCellsModel
                    ->where('character_id',$character['id'])
                    ->where('map_cell_id',$cell['cell_number'])
                    ->countAllResults();
                // Всё равно -1 к лимиту
                $limit--;

                if ($found==0) {
                    // Новая ячейка
                    $newCells[] = $cell;
                    // Запись в explored_cells
                    $exploredCellsModel->insert([
                        'character_id'     => $character['id'],
                        'telegram_user_id' => $task['telegram_user_id'],
                        'map_cell_id'      => $cell['cell_number'],
                        'biome_id'         => $cell['biome_id'],
                        'character_level'  => $character['level'],
                    ]);
                }
            }
        }

        // 9) Обнаружение объектов (только в текущей ячейке игрока, либо во всех newCells?)
        $biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $worldObjectModel         = new WorldObjectModel();
        $discoveryService         = new \App\Services\World\ObjectDiscoveryService(
            $biomeWorldObjectMapModel,
            $worldObjectModel
        );
        // допустим, только позицию игрока
        $discoveryService->discoverObjectsAtPlayerPosition($character);

        // 10) PvP
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        // 11) Формируем текст
        $text = $this->formatExplorationResult($rawCells, $fadePercent, $cellsAfterFade, $newCells);

        // 12) Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '🚜 Переехать',         'callback_data' => 'move'],
                ],
            ]
        ];

        // 13) Отправляем (lazy через BaseTaskHandler)
        $this->safeSendMessage($chatId, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Получаем все клетки, лежащие на кольце radius вокруг (startX,startY).
     * Похоже на логику "квадрат" - "рамка".
     */
    /**
     * Збирає всі клітинки на периметрі квадрату radius `r` навколо `(startX, startY)`.
     *
     * v0.51.27 perf: bounding-box query + biome map preload — раніше 4r per-cell
     * SQL queries × biomeModel->find per-cell. Тепер: 1 SQL bounded query на ring,
     * filter perimeter у PHP, biome name через $biomesById map (preloaded).
     *
     * @param array<int, array<string, mixed>> $biomesById id → biome row map (preloaded)
     * @return array<int, array<string, mixed>>
     */
    private function getCellsOnRing(
        int $startX,
        int $startY,
        int $r,
        MapModel $mapModel,
        array $biomesById
    ): array {
        if ($r < 1) {
            return [];
        }
        $minC = 1;
        $maxC = 1000;

        $xMin = max($minC, $startX - $r);
        $xMax = min($maxC, $startX + $r);
        $yMin = max($minC, $startY - $r);
        $yMax = min($maxC, $startY + $r);

        if ($xMin > $xMax || $yMin > $yMax) {
            return [];
        }

        // 1 SQL: усі клітинки у bounding box (xMin..xMax × yMin..yMax).
        $rows = $mapModel
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        // Filter perimeter у PHP — клітинка на ring, якщо max(|dx|, |dy|) == r.
        $cells = [];
        foreach ($rows as $row) {
            $dx = abs((int) $row['coordinate_x'] - $startX);
            $dy = abs((int) $row['coordinate_y'] - $startY);
            if (max($dx, $dy) !== $r) {
                continue;
            }
            $bId   = (int) $row['biome_id'];
            $biome = $biomesById[$bId] ?? null;
            $cells[] = [
                'cell_number' => $row['cell_number'],
                'biome_id'    => $bId,
                'biome'       => $biome ? $biome['name'] : '???',
            ];
        }
        return $cells;
    }

    /**
     * Формируем финальный текст. Показываем,
     * - сколько было сырого (rawCells)
     * - сколько процентов фейда
     * - итоговое (cellsAfterFade)
     * - сколько реально (count($newCells))
     */
    private function formatExplorationResult(int $rawCells, int $fadePercent, int $cellsAfterFade, array $newCells): string
    {
        $openedCount = count($newCells);
        $msg = "*Исследование завершено!* 🔍\n\n"
            . "По плану: `{$rawCells}` ячеек (1 мин = 1 яч.)\n"
            . "Уменьшение на *{$fadePercent}%* за время (каждые 10 мин -1%).\n"
            . "Итоговый лимит: *{$cellsAfterFade}*.\n\n"
            . "Фактически добавлено новых: *{$openedCount}* (остальные оказались изучены).\n\n";

        if ($openedCount>0) {
            // Подсчёт биомов
            $biomeCounts=[];
            foreach($newCells as $c){
                $bName = $c['biome'] ?? '???';
                if(!isset($biomeCounts[$bName])) {
                    $biomeCounts[$bName] = 0;
                }
                $biomeCounts[$bName]++;
            }
            $msg .= "Среди них биомы:\n";
            foreach($biomeCounts as $bName=>$cnt){
                $msg .= "• *{$bName}*: {$cnt}\n";
            }
        } else {
            $msg .= "_Похоже, все ячейки вокруг уже были знакомы._\n";
        }

        $msg .= "\n🗺️ Удачи в дальнейших приключениях!";
        return $msg;
    }
}
