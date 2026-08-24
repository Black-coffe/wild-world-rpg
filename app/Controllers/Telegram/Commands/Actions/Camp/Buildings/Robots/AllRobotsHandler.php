<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Services\Player\RobotService;

/**
 * Класс для отображения/активации всех роботов, доступных в мастерской.
 * В нём мы считаем «фактический остаток» запусков для каждого робота.
 */
class AllRobotsHandler extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected BuildingModel $buildingModel;
    protected CharacterBuildingModel $characterBuildingModel;
    protected RobotService $robotService;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotService           = new RobotService();
    }

    /**
     * Главный метод обработки (вызывается при нажатии inline-кнопки).
     */
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        // Если пользователя или персонажа не нашли — сообщаем об ошибке.
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $characterId = $character['id'];

        // 1) Ищем все записи в crafted_items, где type='robots'
        $allRobots = $this->craftedItemsModel
            ->where('type', 'robots')
            ->findAll();

        if (empty($allRobots)) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => 'Похоже, ни одного робота пока не существует.',
                'reply_markup' => json_encode(['inline_keyboard' => [$this->droneRepairRow()]]),
            ]);
        }

        // Собираем все их ID
        $robotIds = array_column($allRobots, 'id');

        // 2) Достаём все записи crafted_items_log (где quantity>0), принадлежащие персонажу
        $robotsLogRows = $this->craftedItemsLogModel
            ->whereIn('crafted_item_id', $robotIds)
            ->where('character_id', $characterId)
            ->where('quantity >', 0)
            ->findAll();

        if (empty($robotsLogRows)) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => 'У вас нет доступных роботов.',
                'reply_markup' => json_encode(['inline_keyboard' => [$this->droneRepairRow()]]),
            ]);
        }

        /**
         * 3) Будем группировать данные по ключу "name_rus" (русское название робота).
         *    Для каждой строки лога считаем остаток запусков как:
         *
         *    leftoverUses = (quantity - 1) * baseDurability + currentDurability
         *
         *    где:
         *      - baseDurability = поле durability_count из таблицы crafted_items (базовая прочность),
         *      - currentDurability = durability_count из лога (остаток у «текущего» робота),
         *      - quantity = общее кол-во роботов в данной записи (1 из них может быть частично использован).
         */
        $robotInfo = [];

        foreach ($robotsLogRows as $robotLog) {
            $craftedItemId = (int) $robotLog['crafted_item_id'];
            $quantity      = (int) $robotLog['quantity'];
            $usedDurability = (int) $robotLog['durability_count']; // остаток у «текущего» робота

            // Находим в справочнике baseDurability (сколько у нового робота)
            // и русское имя робота (name_rus).
            $robotDetails = $this->craftedItemsModel->find($craftedItemId);
            if (!$robotDetails) {
                // Если почему-то не нашли, пропускаем
                continue;
            }
            $nameRus        = $robotDetails['name_rus'] ?? '???';
            $nameEng        = $robotDetails['name_eng'] ?? null;
            $baseDurability = (int) $robotDetails['durability_count'];

            // Считаем «фактический остаток» по этой конкретной записи
            // Если quantity=1, значит только один робот, у которого остаток = usedDurability.
            // Если quantity>1, значит (quantity-1) роботов «новые» + 1 «частично израсходованный».
            $freshRobots = max(0, $quantity - 1);
            $leftoverUses = $freshRobots * $baseDurability + $usedDurability;

            if (!isset($robotInfo[$nameRus])) {
                $robotInfo[$nameRus] = [
                    'crafted_item_id' => $craftedItemId,
                    'name_eng'        => $nameEng,
                    'total_quantity'  => 0,
                    'total_leftover'  => 0
                ];
            }

            // Добавляем кол-во роботов и суммарный leftoverUses
            $robotInfo[$nameRus]['total_quantity'] += $quantity;
            $robotInfo[$nameRus]['total_leftover'] += $leftoverUses;
        }

        // 4) Убираем из итогового массива записи, у которых leftover=0 (нечего использовать)
        $filteredRobots = array_filter($robotInfo, function ($info) {
            return ($info['total_leftover'] > 0 && $info['total_quantity'] > 0);
        });

        if (empty($filteredRobots)) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => 'Все роботы полностью израсходованы, запусков не осталось.',
                'reply_markup' => json_encode(['inline_keyboard' => [$this->droneRepairRow()]]),
            ]);
        }

        // Chat-requests-batch-02/09: уровень Мастерской робототехники — тот же
        // канонический резолвер `BuildingModel::idByNameEn()`, что использует
        // соседний экран `RobotGathererActivator` (не второй ручной
        // `where('name_en', ...)`, найденный ревью).
        $roboticsWorkshopId = $this->buildingModel->idByNameEn('RoboticsWorkshop');
        $roboticsWorkshop = $roboticsWorkshopId > 0
            ? $this->characterBuildingModel
                ->where('character_id', $characterId)
                ->where('building_id', $roboticsWorkshopId)
                ->first()
            : null;
        $workshopLevel = is_array($roboticsWorkshop) && isset($roboticsWorkshop['level']) && is_numeric($roboticsWorkshop['level'])
            ? (int) $roboticsWorkshop['level']
            : 1;

        // 5) Формируем сообщение
        $text = "*У тебя в Мастерской робототехники доступны:* \n\n";
        foreach ($filteredRobots as $robotName => $data) {
            $robotNameEn = is_string($data['name_eng']) ? $data['name_eng'] : null;

            $text .= sprintf(
                "🤖 %s / %d шт. / %d запусков (общее)\n",
                $robotName,
                $data['total_quantity'],
                $data['total_leftover']
            );

            // Chat-requests-batch-09 review fix (BLOCK #1): строка охвата —
            // ТОЛЬКО у gatherer-роботов (RobotGatherer/RobotIndustrial).
            // Список собирается по `type='robots'`, куда попадает и семья
            // разведчиков (RobotExplorer/RobotScout) — у них другая механика
            // (открытие клеток карты, не обход вокруг базы), печатать им
            // ту же формулу — выдумывать число.
            if ($this->robotService->familyOf($robotNameEn) === 'gatherer') {
                $reachCurrent = $this->robotService->gatheringReachCells((int) $workshopLevel, $robotNameEn);
                $reachNext    = $this->robotService->gatheringReachCells((int) $workshopLevel + 1, $robotNameEn);
                $text .= "   🗺 обходит *{$reachCurrent}* яч. вокруг базы (след. уровень мастерской — *{$reachNext}*)\n";
            }
        }
        $text .= "\n_Выбери внизу робота для его активации:_";

        // 6) Генерируем Inline-кнопки
        $inlineButtons = [];
        foreach ($filteredRobots as $robotName => $data) {
            $inlineButtons[] = [
                'text'          => $robotName,
                'callback_data' => 'activateRobot_' . $data['crafted_item_id'],
            ];
        }

        $rows = [$inlineButtons];
        $rows[] = $this->droneRepairRow();

        $keyboard = [
            'inline_keyboard' => $rows,
        ];

        // 7) Отправляем результат
        $imagePath = base_url('uploads/telegram/craft/standard/all_robots.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * W4 (ADR-063) — кнопка «🔧 Дрон-ремонтник» ВСЕГДА видна на всех ветках выхода
     * (правило #4 UX-discoverability CLAUDE.md). Callback `repairDrone` сам
     * рендерит lock-state когда инстанса нет (инструкция крафтить в Стандартном
     * верстаке + RoboticsWorkshop L3).
     *
     * @return list<array{text:string,callback_data:string}>
     */
    private function droneRepairRow(): array
    {
        $service = new \App\Services\Player\DroneService();
        $row = [];
        if ($service->repairIsEnabled()) {
            $row[] = ['text' => '🔧 Дрон-ремонтник', 'callback_data' => 'repairDrone'];
        }
        $row[] = ['text' => '🏠 База', 'callback_data' => 'Base'];
        return $row;
    }
}
