<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TaskModel;
use App\Services\Notifications\MediaSender;
use App\Services\Player\DroneService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * E20 (ADR-120) — «🤖 Ангар»: единый хаб автоматизации (роботы + дроны). Callback `hangar`.
 *
 * Кнопка живёт на главном экране базы и ВСЕГДА видна (конституция UX-discoverability):
 *  - без Мастерской робототехники → lock-state: что даёт автоматизация + точный путь постройки;
 *  - с мастерской → сводка: роботы (шт/суммарные запуски + активные задачи с остатком времени)
 *    и дроны по типам (заряд-бар или «не скрафчен» + гейт уровня мастерской), кнопки на
 *    существующие экраны (AllRobots / droneScoutList / cargoDroneList / repairDrone /
 *    combatDroneList) с учётом killswitch'ей типов.
 *
 * Read-only поверх живых механик (баланс/экономика не тронуты) → live без killswitch,
 * как витрина E18. Media-off самодостаточен (текстовый экран).
 */
final class HangarAction extends BaseAction
{
    /** name_eng робота → эмодзи (как в крафт-меню). */
    private const ROBOT_ICONS = [
        'RobotExplorer'   => '🔍',
        'RobotGatherer'   => '⛏',
        'RobotScout'      => '🔭',
        'RobotIndustrial' => '🏭',
    ];

    /** Активные робот-задачи: имя задачи → читаемая метка. */
    private const ROBOT_TASKS = [
        'ExploringLocationRobot'  => '🔍 Исследование',
        'GatheringResourcesRobot' => '⛏ Добыча',
    ];

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $rawId  = $character['id'] ?? null;
        $charId = is_numeric($rawId) ? (int) $rawId : 0;

        $workshopLevel = $this->workshopLevel($charId);
        if ($workshopLevel <= 0) {
            return $this->renderLocked($chatId);
        }

        return $this->renderHangar($chatId, $charId, $workshopLevel);
    }

    /**
     * Lock-state: мастерской нет — объясняем ценность автоматизации и путь к ней.
     */
    private function renderLocked(int $chatId): ServerResponse
    {
        $text = "🔒 *Ангар закрыт*\n\n"
            . "Автоматизация работает на тебя, пока ты офлайн:\n"
            . "  🤖 *роботы* — исследуют карту и добывают ресурсы часами;\n"
            . "  🚁 *дроны* — мгновенно разведывают зону 21×21, доставляют груз на базу, "
            . "чинят всех роботов разом и защищают базу в бою.\n\n"
            . "Для всего этого нужна 🤖 *Мастерская робототехники*.\n"
            . "Построй её: 🏠 База → 🏗 Строить → 🤖 Мастерская робототехники.";

        $rows = [
            [
                ['text' => '🏗 Строить', 'callback_data' => 'Build'],
                ['text' => '🏠 База',    'callback_data' => 'Base'],
            ],
        ];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }

    /**
     * Основной экран: сводка роботов + дронов + кнопки на существующие экраны.
     */
    private function renderHangar(int $chatId, int $charId, int $workshopLevel): ServerResponse
    {
        $service = new DroneService();

        $text = "🤖 *Ангар автоматизации*\n"
            . "_Мастерская робототехники: уровень {$workshopLevel}_\n\n";

        $text .= "*Роботы:*\n" . $this->robotsBlock($charId) . "\n";
        $text .= "*Дроны:*\n" . $this->dronesBlock($charId, $workshopLevel, $service);

        $rows   = [];
        $rows[] = [['text' => '🤖 Роботы', 'callback_data' => 'AllRobots']];

        $droneButtons = [];
        if ($service->isEnabled()) {
            $droneButtons[] = ['text' => '🚁 Разведчик', 'callback_data' => 'droneScoutList'];
        }
        if ($service->cargoIsEnabled()) {
            $droneButtons[] = ['text' => '🚚 Карго', 'callback_data' => 'cargoDroneList'];
        }
        if ($service->repairIsEnabled()) {
            $droneButtons[] = ['text' => '🔧 Ремонтник', 'callback_data' => 'repairDrone'];
        }
        if ($service->combatIsEnabled()) {
            $droneButtons[] = ['text' => '🛡 Боевой', 'callback_data' => 'combatDroneList'];
        }
        foreach (array_chunk($droneButtons, 2) as $chunk) {
            $rows[] = $chunk;
        }

        $rows[] = [['text' => '🏠 База', 'callback_data' => 'Base']];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }

    /**
     * Сводка роботов: по типам шт + суммарный остаток запусков (часов работы),
     * плюс активные задачи с остатком времени.
     */
    private function robotsBlock(int $charId): string
    {
        $items = (new CraftedItemsModel())->where('type', 'robots')->findAll();

        $byId = [];
        foreach ($items as $item) {
            $idRaw = $this->rowVal($item, 'id');
            if (! is_numeric($idRaw)) {
                continue;
            }
            $byId[(int) $idRaw] = $item;
        }

        $lines = [];
        if ($byId !== []) {
            $logRows = (new CraftedItemsLogModel())
                ->whereIn('crafted_item_id', array_keys($byId))
                ->where('character_id', $charId)
                ->where('quantity >', 0)
                ->findAll();

            /** @var array<int, array{qty:int, leftover:int}> $sums */
            $sums = [];
            foreach ($logRows as $row) {
                $itemIdRaw = $this->rowVal($row, 'crafted_item_id');
                $itemId    = is_numeric($itemIdRaw) ? (int) $itemIdRaw : 0;
                $item      = $byId[$itemId] ?? null;
                if ($item === null) {
                    continue;
                }
                $qtyRaw  = $this->rowVal($row, 'quantity');
                $durRaw  = $this->rowVal($row, 'durability_count');
                $baseRaw = $this->rowVal($item, 'durability_count');
                $qty     = is_numeric($qtyRaw) ? (int) $qtyRaw : 0;
                $dur     = is_numeric($durRaw) ? (int) $durRaw : 0;
                $base    = is_numeric($baseRaw) ? (int) $baseRaw : 0;

                if (! isset($sums[$itemId])) {
                    $sums[$itemId] = ['qty' => 0, 'leftover' => 0];
                }
                $sums[$itemId]['qty']      += $qty;
                $sums[$itemId]['leftover'] += max(0, $qty - 1) * $base + $dur;
            }

            foreach ($sums as $itemId => $sum) {
                $item    = $byId[$itemId] ?? [];
                $nameRaw = $this->rowVal($item, 'name_rus');
                $engRaw  = $this->rowVal($item, 'name_eng');
                $name    = is_string($nameRaw) ? $nameRaw : '???';
                $eng     = is_string($engRaw) ? $engRaw : '';
                $icon    = self::ROBOT_ICONS[$eng] ?? '🤖';
                $lines[] = "  {$icon} {$name} — *{$sum['qty']}* шт. (ресурс ~{$sum['leftover']} ч)";
            }
        }

        if ($lines === []) {
            $lines[] = "  _Роботов нет. Крафт: 🛠 Крафт → Стандартный верстак → Роботы._";
        }

        foreach ($this->activeRobotTasks($charId) as $taskLine) {
            $lines[] = $taskLine;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Активные робот-задачи персонажа (in_work) с остатком времени.
     *
     * @return list<string>
     */
    private function activeRobotTasks(int $charId): array
    {
        $taskRows = (new TaskModel())->whereIn('name', array_keys(self::ROBOT_TASKS))->findAll();
        if ($taskRows === []) {
            return [];
        }

        $labelByTaskId = [];
        foreach ($taskRows as $t) {
            $idRaw   = $this->rowVal($t, 'id');
            $nameRaw = $this->rowVal($t, 'name');
            if (! is_numeric($idRaw) || ! is_string($nameRaw)) {
                continue;
            }
            $labelByTaskId[(int) $idRaw] = self::ROBOT_TASKS[$nameRaw] ?? $nameRaw;
        }
        if ($labelByTaskId === []) {
            return [];
        }

        $active = (new CharacterTaskModel())
            ->where('character_id', $charId)
            ->whereIn('task_id', array_keys($labelByTaskId))
            ->where('status', 'in_work')
            ->findAll();

        $lines = [];
        foreach ($active as $row) {
            $taskIdRaw = $this->rowVal($row, 'task_id');
            $endRaw    = $this->rowVal($row, 'end_time');
            $taskId    = is_numeric($taskIdRaw) ? (int) $taskIdRaw : 0;
            $label     = $labelByTaskId[$taskId] ?? '🤖 Задача';
            $remain    = is_string($endRaw) ? $this->remainingHuman($endRaw) : '';
            $lines[]   = $remain !== ''
                ? "  ▶️ В работе: {$label} — ещё ~{$remain}"
                : "  ▶️ В работе: {$label}";
        }

        return $lines;
    }

    /**
     * Сводка дронов: на каждый ВКЛЮЧЁННЫЙ killswitch'ем тип — заряд-бар (если скрафчен)
     * или гейт-подсказка (если нет). Выключенные типы не показываем (не фича).
     */
    private function dronesBlock(int $charId, int $workshopLevel, DroneService $service): string
    {
        /** @var list<array{eng:string, icon:string, label:string, enabled:bool, max:int, gate:int}> $types */
        $types = [
            ['eng' => 'DroneScout',  'icon' => '🚁', 'label' => 'Разведчик', 'enabled' => $service->isEnabled(),       'max' => $service->batteryMax(),       'gate' => 1],
            ['eng' => 'DroneCargo',  'icon' => '🚚', 'label' => 'Карго',     'enabled' => $service->cargoIsEnabled(),  'max' => $service->cargoBatteryMax(),  'gate' => 2],
            ['eng' => 'DroneRepair', 'icon' => '🔧', 'label' => 'Ремонтник', 'enabled' => $service->repairIsEnabled(), 'max' => $service->repairBatteryMax(), 'gate' => 3],
            ['eng' => 'DroneCombat', 'icon' => '🛡', 'label' => 'Боевой',    'enabled' => $service->combatIsEnabled(), 'max' => $service->combatBatteryMax(), 'gate' => 4],
        ];

        $itemModel = new CraftedItemsModel();
        $logModel  = new CraftedItemsLogModel();

        $lines = [];
        foreach ($types as $type) {
            if (! $type['enabled']) {
                continue;
            }

            $item     = $itemModel->where('name_eng', $type['eng'])->first();
            $itemIdRaw = is_array($item) ? ($item['id'] ?? null) : null;
            $itemId   = is_numeric($itemIdRaw) ? (int) $itemIdRaw : 0;

            $charge = null;
            if ($itemId > 0) {
                $log = $logModel
                    ->where('character_id', $charId)
                    ->where('crafted_item_id', $itemId)
                    ->where('quantity >', 0)
                    ->orderBy('durability_count', 'DESC')
                    ->first();
                if (is_array($log)) {
                    $chargeRaw = $log['durability_count'] ?? null;
                    $charge    = is_numeric($chargeRaw) ? (int) $chargeRaw : 0;
                }
            }

            if ($charge !== null) {
                $max     = max(1, $type['max']);
                $pct     = (int) round(min($charge, $max) * 100 / $max);
                $bar     = $this->chargeBar($pct);
                $lines[] = "  {$type['icon']} {$type['label']} — {$bar} `{$charge}/{$max}`";
            } elseif ($workshopLevel >= $type['gate']) {
                $lines[] = "  {$type['icon']} {$type['label']} — _не скрафчен (доступен: Стандартный верстак)_";
            } else {
                $lines[] = "  {$type['icon']} {$type['label']} — 🔒 _нужна Мастерская ур. {$type['gate']}_";
            }
        }

        if ($lines === []) {
            $lines[] = "  _Дроны временно отключены._";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Уровень Мастерской робототехники персонажа (max по базам, ADR-102 per-base);
     * 0 = не построена.
     */
    private function workshopLevel(int $charId): int
    {
        $building = (new BuildingModel())->where('name_en', 'RoboticsWorkshop')->first();
        $bIdRaw   = is_array($building) ? ($building['id'] ?? null) : null;
        if (! is_numeric($bIdRaw) || (int) $bIdRaw <= 0) {
            return 0;
        }

        $rows = (new CharacterBuildingModel())
            ->where('character_id', $charId)
            ->where('building_id', (int) $bIdRaw)
            ->findAll();

        $maxLevel = 0;
        foreach ($rows as $row) {
            $lvlRaw = $this->rowVal($row, 'level');
            $lvl    = is_numeric($lvlRaw) ? (int) $lvlRaw : 0;
            $maxLevel = max($maxLevel, $lvl);
        }

        // Запись есть, но level некорректен → считаем построенной (L1).
        if ($maxLevel === 0 && $rows !== []) {
            return 1;
        }

        return $maxLevel;
    }

    /** «3ч 12м» из end_time (пусто, если время вышло/не парсится). */
    private function remainingHuman(string $endTime): string
    {
        $end = strtotime($endTime);
        if ($end === false) {
            return '';
        }
        $diff = $end - time();
        if ($diff <= 0) {
            return '';
        }
        $hours   = intdiv($diff, 3600);
        $minutes = intdiv($diff % 3600, 60);
        if ($hours > 0) {
            return "{$hours}ч {$minutes}м";
        }
        return "{$minutes}м";
    }

    private function chargeBar(int $pct): string
    {
        $pct    = max(0, min(100, $pct));
        $filled = (int) round($pct / 10);
        return str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled);
    }

    /**
     * Модели после F1.4 возвращают Entity (ArrayAccess) ИЛИ массив — безопасное
     * чтение поля строки без offset-доступа к mixed (phpstan L9).
     */
    private function rowVal(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        if ($row instanceof \ArrayAccess) {
            return $row[$key] ?? null;
        }

        return null;
    }
}
