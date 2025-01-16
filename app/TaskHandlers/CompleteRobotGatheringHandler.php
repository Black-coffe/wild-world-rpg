<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterTaskModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\BuildingModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Models\TaskModel;
use App\Models\BiomeModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Хендлер задачи робота-добытчика (GatheringResourcesRobot),
 * где число ячеек = уровень мастерской (уровень=1 => 1 ячейка, уровень=2 => 2 ячейки ...).
 *
 * Мы используем BFS (или любой поиск), чтобы получить ровно workshopLevel ячеек:
 *   1) База.
 *   2) + (workshopLevel -1) ближайших соседних.
 * После чего "глобально" считаем добычу по биому, и распределяем пропорционально
 * (biomeCellCount / totalCells).
 *
 * Уровень влияет только на minRarity, не на сами количества.
 * Для редкостей≥4 линейный рост по времени, для ≤3 — затухание.
 * Базовые цифры (2ч) в массиве, ±20% рандом.
 */
class CompleteRobotGatheringHandler extends Controller
{
    private const FALLBACK_WORKSHOP_ID = 9;
    private const GATHERING_TASK_NAME  = 'GatheringResourcesRobot';
    private const RANDOM_PERCENT       = 20; // ±20%

    protected $characterModel;
    protected $characterBuildingModel;
    protected $characterTaskModel;
    protected $characterResourceModel;
    protected $claimedCellModel;
    protected $buildingModel;
    protected $mapModel;
    protected $resourceModel;
    protected $telegramUserModel;
    protected $taskModel;
    protected $biomeModel;

    private $telegram;
    private $workshopBuildingId;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel         = new CharacterModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->mapModel               = new MapModel();
        $this->resourceModel          = new ResourceModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->taskModel              = new TaskModel();
        $this->biomeModel             = new BiomeModel();

        // Ищем RoboticsWorkshop
        $ws = $this->buildingModel->where('name_en', 'RoboticsWorkshop')->first();
        $this->workshopBuildingId = $ws ? (int)$ws['id'] : self::FALLBACK_WORKSHOP_ID;

        // Telegram init
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', "[CompleteRobotGatheringHandler] Telegram init error: " . $e->getMessage());
        }
    }

    public function handle(array $task)
    {
        // 1) Ставим статус completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем персонажа
        $character = $this->characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', '[CompleteRobotGatheringHandler] Character not found. task_id=' . $task['id']);
            return false;
        }

        // 2.1) Telegram-user
        $chatRow = $this->telegramUserModel->find($task['telegram_user_id']);
        if (!$chatRow) {
            log_message('error', '[CompleteRobotGatheringHandler] Telegram user not found. user_id=' . $task['telegram_user_id']);
            return false;
        }
        $chatId = $chatRow['telegram_id'];

        // 3) База
        $baseRow = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->where('status', 'active')
            ->first();
        if (!$baseRow) {
            $this->sendNoBaseMessage($chatId);
            return true;
        }

        // 4) Узнаём ячейку базы
        $mapRec = $this->mapModel->find($baseRow['map_cell_id']);
        if (!$mapRec) {
            $this->sendNoBaseMessage($chatId);
            return true;
        }
        $baseCellNumber = (int)$mapRec['cell_number'];

        // 5) Мастерская => level => minRarity
        $workshop = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $this->workshopBuildingId)
            ->where('map_cell_id', $baseRow['map_cell_id'])
            ->first();
        if (!$workshop) {
            $this->sendNoWorkshopMessage($chatId);
            return true;
        }
        $workshopLevel = (int)$workshop['level'];

        // 6) Сколько часов прошло
        $startTime = strtotime($task['start_time']);
        $endTime   = strtotime($task['end_time']);
        $now       = time();
        $actualEnd = min($endTime, $now);
        $hoursSpent= max(0, ($actualEnd - $startTime)/3600);

        // 7) Собираем ровно workshopLevel ячеек через BFS
        //    (Если уровень=10 => 10 ячеек: база + 9 соседей)
        //    Если карта маленькая и не хватает ячеек, вернём меньше.
        $desiredCellsCount = max(1, $workshopLevel);
        $uniqueCells = $this->getLimitedCells($baseCellNumber, $desiredCellsCount);

        // Если нет ни одной ячейки
        if (empty($uniqueCells)) {
            $msg = "⚙ *Робот-добытчик завершил работу*, но не удалось собрать ни одной ячейки?";
            Request::sendMessage([
                'chat_id'=>$chatId,
                'text'=>$msg,
                'parse_mode'=>'Markdown'
            ]);
            return true;
        }

        // Подсчитаем кол-во ячеек для каждого биома + totalCells
        $biomeCellCounts=[];
        $totalCells=0;
        foreach ($uniqueCells as $cellNum) {
            $row = $this->mapModel->where('cell_number', $cellNum)->first();
            if (!$row) continue;
            $bId = (int)$row['biome_id'];
            if (!isset($biomeCellCounts[$bId])) {
                $biomeCellCounts[$bId]=0;
            }
            $biomeCellCounts[$bId]++;
            $totalCells++;
        }

        if ($totalCells<=0) {
            $msg="⚙ *Робот-добытчик завершил работу*, но нет доступных ячеек?";
            Request::sendMessage([
                'chat_id'=>$chatId,
                'text'=>$msg,
                'parse_mode'=>'Markdown'
            ]);
            return true;
        }

        // 8) Считаем глобально на биом, потом умножаем на (countBiome / totalCells)
        $biomeGroupedResources=[];
        $minRarity = max(1, 10-($workshopLevel-1));

        foreach ($biomeCellCounts as $bId=>$bCount) {
            // Достаём ресурсы
            $available = $this->resourceModel
                ->where('rarity >=', $minRarity)
                ->where('rarity <=', 10)
                ->like('biome_id', (string)$bId, 'both')
                ->findAll();

            // Глобальный расчёт
            $resMap = $this->calculateBiomeResources($available, $hoursSpent);
            // Умножаем на долю
            $ratio = $bCount / $totalCells;
            foreach ($resMap as $rId=>$val) {
                $val *= $ratio;
                $resMap[$rId] = (int) round($val);
            }
            $biomeGroupedResources[$bId]=$resMap;
        }

        // Проверим пустоту
        $allZero=true;
        foreach ($biomeGroupedResources as $rMap) {
            foreach ($rMap as $val) {
                if ($val>0) {
                    $allZero=false;
                    break 2;
                }
            }
        }

        if ($allZero) {
            $msg="⚙ *Робот-добытчик завершил работу*, но ничего не собрано.";
        } else {
            // Сохранение
            foreach ($biomeGroupedResources as $bId=>$resMap) {
                foreach ($resMap as $rId=>$amount) {
                    if ($amount<=0) continue;
                    $existing = $this->characterResourceModel
                        ->where('id_characters', $character['id'])
                        ->where('id_resources', $rId)
                        ->first();
                    if ($existing) {
                        $this->characterResourceModel->update($existing['id'],[
                            'quantity'=>$existing['quantity'] + $amount
                        ]);
                    } else {
                        $this->characterResourceModel->insert([
                            'id_characters'=>$character['id'],
                            'id_resources'=>$rId,
                            'id_telegram_users'=>$task['telegram_user_id'],
                            'quantity'=>$amount
                        ]);
                    }
                }
            }
            $msg = $this->formatGatheringResultMessage($biomeGroupedResources, $hoursSpent, $workshopLevel, $biomeCellCounts);
        }

        // Отправляем
        $keyboard=[
            'inline_keyboard'=>[
                [
                    ['text'=>'👤 Персонаж','callback_data'=>'character'],
                    ['text'=>'🎒 Инвентарь','callback_data'=>'inventory'],
                ],
                [
                    ['text'=>'🏠 База','callback_data'=>'Base']
                ]
            ]
        ];

        $imagePath= base_url('uploads/telegram/craft/standard/robot_gatherer.jpg');
        try {
            Request::sendPhoto([
                'chat_id'=>$chatId,
                'photo'=> Request::encodeFile($imagePath),
                'caption'=>$msg,
                'parse_mode'=>'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error',"[CompleteRobotGatheringHandler] sendPhoto failed:".$e->getMessage());
            Request::sendMessage([
                'chat_id'=>$chatId,
                'text'=>$msg,
                'parse_mode'=>'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        }

        return true;
    }

    /**
     * BFS-функция, которая возвращает ровно $limit ячеек (или меньше, если не хватает).
     * Начинаем с baseCellNumber, ищем соседей через getNeighboringCells,
     * пока не наберём $limit.
     */
    private function getLimitedCells(int $baseCellNumber, int $limit): array
    {
        // Начинаем с базы
        $result = [];
        $queue  = [];
        $visited= [];

        $result[]   = $baseCellNumber;
        $queue[]    = $baseCellNumber;
        $visited[$baseCellNumber] = true;

        // Пока в очереди есть ячейки, и кол-во в result < limit
        while (!empty($queue) && count($result) < $limit) {
            $current = array_shift($queue);

            // Соседи
            $neighbors = $this->mapModel->getNeighboringCells($current);
            // getNeighboringCells должен вернуть массив
            //   [ ['cell_number'=>..., 'coordinate_x'=>..., 'coordinate_y'=>..., 'biome_id'=>...], ... ]

            foreach ($neighbors as $n) {
                $nbrCellNum = $n['cell_number'];
                if (!isset($visited[$nbrCellNum])) {
                    $visited[$nbrCellNum] = true;
                    // Добавляем
                    $result[] = $nbrCellNum;
                    $queue[]  = $nbrCellNum;

                    if (count($result)>= $limit) {
                        break 2; // выходим
                    }
                }
            }
        }

        return $result;
    }

    private function sendNoBaseMessage(int $chatId): void
    {
        $msg = "⚙ *Робот-добытчик вернулся...*\n"
            ."Но базы тут нет, всё потеряно!";
        Request::sendMessage([
            'chat_id'=>$chatId,
            'text'=>$msg,
            'parse_mode'=>'Markdown',
        ]);
    }

    private function sendNoWorkshopMessage(int $chatId): void
    {
        $msg="⚙ *Робот-добытчик прибыл*\nНо мастерская отсутствует.";
        Request::sendMessage([
            'chat_id'=>$chatId,
            'text'=>$msg,
            'parse_mode'=>'Markdown',
        ]);
    }

    /**
     * Глобальный расчёт для "одной биом-зоны".
     * Результат "как если бы вся зона = этот биом".
     *  (Не умножаем на число ячеек!)
     */
    private function calculateBiomeResources(array $availableResources, float $hoursSpent): array
    {
        $res = [];

        // Базовые (на 2ч)
        $baseAt2h = [
            10=>500,
            9 =>400,
            8 =>350,
            7 =>300,
            6 =>200,
            5 =>150,
            4 =>100,
            3 =>50,
            2 =>20,
            1 =>10
        ];
        $damping = [
            3=>0.9,
            2=>0.8,
            1=>0.7
        ];
        $rndRange = self::RANDOM_PERCENT;

        foreach ($availableResources as $r) {
            $rarity= (int)$r['rarity'];
            if (!isset($baseAt2h[$rarity])) {
                continue;
            }
            $base2 = $baseAt2h[$rarity];

            // timeCoef
            $timeCoef=0.0;
            if ($hoursSpent<=2) {
                $timeCoef= $hoursSpent/2.0;
            } else {
                if ($rarity>=4) {
                    $timeCoef= $hoursSpent/2.0; // линейно
                } else {
                    // затухание
                    $k= $damping[$rarity]??1.0;
                    $timeCoef= pow($hoursSpent/2.0, $k);
                }
            }
            $qty= $base2 * $timeCoef;

            // ±20%
            $rnd= rand(-$rndRange,$rndRange);
            $qty*= (1+ $rnd/100.0);

            $final = (int)round($qty);
            if ($final>0) {
                $rId= (int)$r['id'];
                if (!isset($res[$rId])) {
                    $res[$rId]=0;
                }
                $res[$rId]+= $final;
            }
        }

        return $res;
    }

    private function formatGatheringResultMessage(
        array $biomeGroupedResources,
        float $hoursSpent,
        int   $workshopLevel,
        array $biomeCellCounts
    ): string
    {
        $wh= floor($hoursSpent);
        $mm= floor(($hoursSpent-$wh)*60);
        $timeStr= "{$wh} ч {$mm} мин";

        $msg= "⚙ *Робот-добытчик завершил работу!* \n\n"
            ."⏳ Время сбора: `{$timeStr}`\n"
            ."🏭 Уровень мастерской: *{$workshopLevel}*\n\n"
            ."🎉 *Сводка по биомам:*";

        foreach ($biomeGroupedResources as $bId=>$rMap) {
            if (empty($rMap)) continue;
            $bRow= $this->biomeModel->find($bId);
            $bName= $bRow? $bRow['name']:("Биом#{$bId}");

            $cellCount= $biomeCellCounts[$bId]??1;
            $msg.="\n\n*{$bName}* (ячеек: {$cellCount}):";

            foreach ($rMap as $rId=>$amt) {
                $resRow= $this->resourceModel->find($rId);
                $resName= $resRow? $resRow['name']:("Res#{$rId}");
                $msg.="\n • `{$resName}`: {$amt}";
            }
        }
        $msg.="\n\nУдачи в дальнейших вылазках!";
        return $msg;
    }
}
