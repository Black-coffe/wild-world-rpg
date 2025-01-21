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
use CodeIgniter\Controller;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

class ExplorationTaskHandler extends Controller
{
    private $telegram;
    private $playerDetectionService;

    public function __construct()
    {
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }

        // Сервис PvP (обнаружения игроков)
        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle(array $task)
    {
        // 1) Завершаем задачу
        $characterTaskModel = new CharacterTaskModel();
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Достаём персонажа
        $characterModel = new CharacterModel();
        $character      = $characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', 'Персонаж не найден при завершении ExplorationTask.');
            return false;
        }

        // 3) Небольшое улучшение характеристик
        $characterModel->update($character['id'], [
            'experience' => $character['experience'] + 0.02,
            'strength'   => $character['strength']   + 0.03,
            'agility'    => $character['agility']    + 0.01,
            'intellect'  => $character['intellect']  + 0.02,
        ]);

        // 4) Узнаём, сколько минут планировал (chosen_minutes) и сколько реально прошло
        $taskSettings   = json_decode($task['task_settings'] ?? '{}', true);
        $plannedMinutes = $taskSettings['chosen_minutes'] ?? 10; // по умолчанию 10

        $startTime = new \DateTime($task['start_time']);
        $endTime   = new \DateTime($task['end_time']);
        $diff      = $startTime->diff($endTime);
        $actualSpent= ($diff->days * 1440) + ($diff->h * 60) + $diff->i;

        // Если разница <= 2 мин — берём planned, иначе actual
        $delta = abs($actualSpent - $plannedMinutes);
        $finalMinutes = ($delta <= 2) ? $plannedMinutes : $actualSpent;

        // 5) Считаем сырое количество ячеек (1 мин = 1 ячейка)
        $rawCells = $finalMinutes;

        // 6) Фейд: каждые 10 мин => -1%
        $blocks = intdiv($finalMinutes, 10);
        $fadePercent = $blocks; // 1% за каждый блок
        if ($fadePercent > 50) {
            $fadePercent = 50; // ограничим, к примеру, 50%
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
            return false;
        }
        $chatId = $chatRow['telegram_id'];

        $playerCell = $mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$playerCell) {
            log_message('error',"Ячейка персонажа cell_number={$character['cell_number']} не найдена.");
            return false;
        }

        $startX = $playerCell['coordinate_x'];
        $startY = $playerCell['coordinate_y'];

        // 8) Обход кольцами, пока не израсходуем cellsAfterFade
        $limit  = $cellsAfterFade;
        $radius = 0;
        $newCells= [];
        $exploredCellsModel= new ExploredCellsModel();
        $biomeModel = new BiomeModel();

        while ($limit>0 && $radius<2000) {
            $radius++;
            $ringCells = $this->getCellsOnRing($startX, $startY, $radius, $mapModel, $biomeModel);
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

        // 13) Отправляем
        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        return true;
    }

    /**
     * Получаем все клетки, лежащие на кольце radius вокруг (startX,startY).
     * Похоже на логику "квадрат" - "рамка".
     */
    private function getCellsOnRing(
        int $startX,
        int $startY,
        int $r,
        MapModel $mapModel,
        BiomeModel $biomeModel
    ): array {
        if ($r<1) {
            return [];
        }
        $cells = [];
        $minC=1;
        $maxC=1000;

        // Горизонтали
        for($dx=-$r; $dx<=$r; $dx++){
            $x = $startX + $dx;
            $yTop = $startY + $r;
            $yBot = $startY - $r;

            if($x>=$minC && $x<=$maxC && $yTop>=$minC && $yTop<=$maxC){
                $row = $mapModel->where('coordinate_x',$x)->where('coordinate_y',$yTop)->first();
                if($row){
                    $cells[] = [
                        'cell_number'=>$row['cell_number'],
                        'biome_id'=>$row['biome_id'],
                        'biome'=>$this->getBiomeName($row['biome_id'],$biomeModel),
                    ];
                }
            }
            if($x>=$minC && $x<=$maxC && $yBot>=$minC && $yBot<=$maxC){
                $row = $mapModel->where('coordinate_x',$x)->where('coordinate_y',$yBot)->first();
                if($row){
                    $cells[] = [
                        'cell_number'=>$row['cell_number'],
                        'biome_id'=>$row['biome_id'],
                        'biome'=>$this->getBiomeName($row['biome_id'],$biomeModel),
                    ];
                }
            }
        }
        // Вертикали (исключая углы, которые уже добавили)
        for($dy=-$r+1; $dy<=$r-1; $dy++){
            $y = $startY + $dy;
            $xLeft  = $startX - $r;
            $xRight = $startX + $r;

            if($xLeft>=$minC && $xLeft<=$maxC && $y>=$minC && $y<=$maxC){
                $row = $mapModel->where('coordinate_x',$xLeft)->where('coordinate_y',$y)->first();
                if($row){
                    $cells[]=[
                        'cell_number'=>$row['cell_number'],
                        'biome_id'=>$row['biome_id'],
                        'biome'=>$this->getBiomeName($row['biome_id'],$biomeModel),
                    ];
                }
            }
            if($xRight>=$minC && $xRight<=$maxC && $y>=$minC && $y<=$maxC){
                $row = $mapModel->where('coordinate_x',$xRight)->where('coordinate_y',$y)->first();
                if($row){
                    $cells[]=[
                        'cell_number'=>$row['cell_number'],
                        'biome_id'=>$row['biome_id'],
                        'biome'=>$this->getBiomeName($row['biome_id'],$biomeModel),
                    ];
                }
            }
        }
        return $cells;
    }

    private function getBiomeName(int $biomeId, BiomeModel $biomeModel): string
    {
        $row = $biomeModel->find($biomeId);
        return $row ? $row['name'] : '???';
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
