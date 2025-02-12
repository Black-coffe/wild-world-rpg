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

class CompleteRobotGatheringHandler extends Controller
{
    private const FALLBACK_WORKSHOP_ID = 9;
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
            // Логи убраны, но на практике лучше как-то обрабатывать ошибку
        }
    }

    public function handle(array $task)
    {
        // 1) Ставим статус completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем персонажа
        $character = $this->characterModel->find($task['character_id']);
        if (!$character) {
            return false;
        }

        // 2.1) Ищем телеграм-пользователя
        $chatRow = $this->telegramUserModel->find($task['telegram_user_id']);
        if (!$chatRow) {
            return false;
        }
        $chatId = $chatRow['telegram_id'];

        // 3) База
        $baseRow = $this->claimedCellModel
            ->where('character_id', $character['id'])
            ->where('status', 'active')
            ->first();
        if (!$baseRow) {
            $this->sendTextOnly($chatId, "⚙ *Робот-добытчик вернулся...*\nНо базы тут нет, всё потеряно!");
            return true;
        }

        // 4) Ячейка базы
        $mapRec = $this->mapModel->find($baseRow['map_cell_id']);
        if (!$mapRec) {
            $this->sendTextOnly($chatId, "⚙ *Робот-добытчик вернулся...*\nНо базы тут нет, всё потеряно!");
            return true;
        }
        $baseCellNumber = (int)$mapRec['cell_number'];

        // 5) Мастерская => уровень
        $workshop = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $this->workshopBuildingId)
            ->where('map_cell_id', $baseRow['map_cell_id'])
            ->first();
        if (!$workshop) {
            $this->sendTextOnly($chatId, "⚙ *Робот-добытчик прибыл*\nНо 🤖Мастерская робототехники🤖 отсутствует.");
            return true;
        }
        $workshopLevel = (int)$workshop['level'];

        // 6) Сколько часов прошло
        $startTime  = strtotime($task['start_time']);
        $endTime    = strtotime($task['end_time']);
        $now        = time();
        $actualEnd  = min($endTime, $now);
        $hoursSpent = max(0, ($actualEnd - $startTime) / 3600);

        // 7) Собираем ячейки BFS
        $desiredCellsCount = max(1, $workshopLevel);
        $uniqueCells = $this->getLimitedCells($baseCellNumber, $desiredCellsCount);

        if (empty($uniqueCells)) {
            $this->sendTextOnly($chatId, "⚙ *Робот-добытчик завершил работу*, но не удалось собрать ни одной ячейки?");
            return true;
        }

        // Подсчёт ячеек по биомам
        $biomeCellCounts = [];
        $totalCells      = 0;
        foreach ($uniqueCells as $cellNum) {
            $row = $this->mapModel->where('cell_number', $cellNum)->first();
            if (!$row) {
                continue;
            }
            $bId = (int)$row['biome_id'];
            if (!isset($biomeCellCounts[$bId])) {
                $biomeCellCounts[$bId] = 0;
            }
            $biomeCellCounts[$bId]++;
            $totalCells++;
        }
        if ($totalCells <= 0) {
            $this->sendTextOnly($chatId, "⚙ *Робот-добытчик завершил работу*, но нет доступных ячеек?");
            return true;
        }

        // 8) Расчёт ресурсов
        $biomeGroupedResources = [];
        $minRarity = max(1, 10 - ($workshopLevel - 1));

        foreach ($biomeCellCounts as $bId => $bCount) {
            $available = $this->resourceModel
                ->where('rarity >=', $minRarity)
                ->where('rarity <=', 10)
                ->like('biome_id', (string)$bId, 'both')
                ->findAll();

            $resMap = $this->calculateBiomeResources($available, $hoursSpent);
            $ratio  = $bCount / $totalCells;
            foreach ($resMap as $rId => $val) {
                $val *= $ratio;
                $resMap[$rId] = (int) round($val);
            }
            $biomeGroupedResources[$bId] = $resMap;
        }

        // Проверяем, всё ли нули
        $allZero = true;
        foreach ($biomeGroupedResources as $rMap) {
            foreach ($rMap as $val) {
                if ($val > 0) {
                    $allZero = false;
                    break 2;
                }
            }
        }

        // Если ничего не добыли
        if ($allZero) {
            $this->sendTextOnly($chatId, "⚙ *Робот-добытчик завершил работу*, но ничего не собрано.");
            return true;
        }

        // Сохраняем
        foreach ($biomeGroupedResources as $bId=>$resMap) {
            foreach ($resMap as $rId=>$amount) {
                if ($amount<=0) continue;
                $existing = $this->characterResourceModel
                    ->where('id_characters', $character['id'])
                    ->where('id_resources', $rId)
                    ->first();
                if ($existing) {
                    $this->characterResourceModel->update($existing['id'], [
                        'quantity'=>$existing['quantity'] + $amount
                    ]);
                } else {
                    $this->characterResourceModel->insert([
                        'id_characters'     => $character['id'],
                        'id_resources'      => $rId,
                        'id_telegram_users' => $task['telegram_user_id'],
                        'quantity'          => $amount
                    ]);
                }
            }
        }

        // Формируем финальное сообщение
        $msg = $this->formatGatheringResultMessage($biomeGroupedResources, $hoursSpent, $workshopLevel, $biomeCellCounts);

        // Проверка длины текста => либо отправить фото, либо только текст
        $safeCaption = $this->sanitizeForTelegram($msg);
        if (mb_strlen($safeCaption, 'UTF-8') > 1000) {
            // Слишком длинно => отправляем только текст
            $this->sendTextOnly($chatId, $msg);
        } else {
            // Отправляем фото + подпись
            $imagePath = base_url('uploads/telegram/craft/standard/robot_gatherer.jpg');
            $this->sendPhotoWithCaption($chatId, $imagePath, $safeCaption);
        }
        return true;
    }

    /**
     * Отправка ТОЛЬКО текста (без картинки).
     */
    private function sendTextOnly(int $chatId, string $rawMessage): void
    {
        $text = $this->sanitizeForTelegram($rawMessage);
        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            // Логика обработки, при необходимости
        }
    }

    /**
     * Отправка фото + подписи (где подпись уже безопасная).
     */
    private function sendPhotoWithCaption(int $chatId, string $photoUrl, string $caption): void
    {
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoUrl),
                'caption'    => $caption,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            // В случае ошибки, можно fallback на текст
            $this->sendTextOnly($chatId, $caption);
        }
    }

    /**
     * BFS-функция для получения $limit ячеек (или меньше).
     */
    private function getLimitedCells(int $baseCellNumber, int $limit): array
    {
        $result = [];
        $queue  = [];
        $visited= [];

        $result[] = $baseCellNumber;
        $queue[]  = $baseCellNumber;
        $visited[$baseCellNumber] = true;

        while (!empty($queue) && count($result) < $limit) {
            $current = array_shift($queue);
            $neighbors = $this->mapModel->getNeighboringCells($current);
            if (!$neighbors) {
                continue;
            }
            foreach ($neighbors as $n) {
                $nbrCellNum = $n['cell_number'];
                if (!isset($visited[$nbrCellNum])) {
                    $visited[$nbrCellNum] = true;
                    $result[] = $nbrCellNum;
                    $queue[]  = $nbrCellNum;

                    if (count($result) >= $limit) {
                        break 2;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Расчёт ресурсов для биома (без учёта кол-ва ячеек — это умножается снаружи).
     */
    private function calculateBiomeResources(array $availableResources, float $hoursSpent): array
    {
        $res = [];
        // Условные базовые значения "за 2 часа"
        $baseAt2h = [
            10=>500, 9=>400, 8=>350, 7=>300, 6=>200,
            5=>150, 4=>100, 3=>50,  2=>20,  1=>10
        ];
        $damping = [ 3=>0.9, 2=>0.8, 1=>0.7 ];
        $rndRange = self::RANDOM_PERCENT;

        foreach ($availableResources as $r) {
            $rarity= (int)$r['rarity'];
            if (!isset($baseAt2h[$rarity])) {
                continue;
            }
            $base2 = $baseAt2h[$rarity];

            // Вычисляем коэффициент времени
            $timeCoef=0.0;
            if ($hoursSpent<=2) {
                $timeCoef = $hoursSpent/2.0;
            } else {
                if ($rarity>=4) {
                    $timeCoef= $hoursSpent/2.0;
                } else {
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
                    $res[$rId] = 0;
                }
                $res[$rId]+= $final;
            }
        }
        return $res;
    }

    /**
     * Формирование итогового текста о добыче.
     */
    private function formatGatheringResultMessage(
        array $biomeGroupedResources,
        float $hoursSpent,
        int   $workshopLevel,
        array $biomeCellCounts
    ): string
    {
        $wh= floor($hoursSpent);
        $mm= floor(($hoursSpent - $wh)*60);
        $timeStr= "{$wh} ч {$mm} мин";

        $msg = "⚙ *Робот-добытчик завершил работу!* \n\n"
            ."⏳ Время сбора: `{$timeStr}`\n"
            ."🏭 Уровень мастерской: *{$workshopLevel}*\n\n"
            ."🎉 *Сводка по биомам:*";

        foreach ($biomeGroupedResources as $bId=>$rMap) {
            if (empty($rMap)) {
                continue;
            }
            $bRow= $this->biomeModel->find($bId);
            $bName= $bRow ? $bRow['name'] : ("Биом#{$bId}");

            $cellCount= $biomeCellCounts[$bId]??1;
            $msg.="\n\n*{$bName}* (ячеек: {$cellCount}):";

            foreach ($rMap as $rId=>$amt) {
                $resRow= $this->resourceModel->find($rId);
                $resName= $resRow ? $resRow['name'] : ("Res#{$rId}");
                $msg.="\n • `{$resName}`: {$amt}";
            }
        }
        $msg.="\n\nУдачи в дальнейших вылазках!";
        return $msg;
    }

    /**
     * Пример «санитизации»/экранирования для Markdown,
     * чтобы избежать ошибок от спецсимволов.
     * Если нужен именно 'MarkdownV2' — расширить список символов.
     */
    private function sanitizeForTelegram(string $text): string
    {
        // Экранируем только самые проблемные символы:
        // *   — жирный / курсив,
        // _   — курсив,
        // `   — блок кода,
        // [   — ссылки.
        //
        // Остальные (, ), ~, >, #, +, -, =, |, {, }, ., ! и т.д. не трогаем.
        // Так не будет лишних слешей перед скобками/восклиц. знаками и т.д.
        //        $charactersToEscape = ['*', '_', '`', '[', ']',];
        //
        //        foreach ($charactersToEscape as $char) {
        //            $text = str_replace($char, '\\' . $char, $text);
        //        }
        return $text;
    }
}
