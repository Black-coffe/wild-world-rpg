<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotGatheringAction;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TaskModel;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Notifications\MediaSender;
use App\Services\Player\DroneService;
use App\Services\Telegram\BotMenuService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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

        $cellRaw     = $character['cell_number'] ?? null;
        $currentCell = is_numeric($cellRaw) ? (int) $cellRaw : 0;

        // multibase-picker-04: «🤖 Ангар» с суффиксом (`hangar_b<id>`) обязан показать
        // Мастерскую именно ТОЙ базы, а не max(level) по всем (корень бага —
        // recon.md «Ангар и роботы», workshopLevel() :371-397). Роутер суффикс не
        // снимает — разбираем сами (BaseCallbackSuffix).
        [, $baseId] = BaseCallbackSuffix::split($this->callbackQuery->getData());
        $resolver   = new BaseScopeResolver();

        if ($baseId !== null) {
            $scope = $resolver->resolveForBase($charId, $currentCell, $baseId);
            if ($scope['reason'] === BaseScopeResolver::REASON_UNAVAILABLE) {
                return Request::sendMessage(['chat_id' => $chatId, 'text' => $scope['text']]);
            }
            $baseCell = $scope['cell'];
        } else {
            $scope    = $resolver->resolve($charId, $currentCell);
            $baseCell = $scope['cell'];
            if ($baseCell === null) {
                // multibase-picker-10 (lead-review Major 1) — голый `hangar` без базы
                // в охвате (нет баз ИЛИ 2+ базы вне базы и вне сигнала) остаётся хабом
                // ADR-120, не голым отказом: инвентарь роботов/дронов + lock-объяснение,
                // ни одна база не называется местной.
                return $this->renderNoBaseHub($chatId, $charId, $scope['reason'] ?? null);
            }
        }

        if ($baseCell === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $scope['text'] ?? BaseScopeResolver::TEXT_AMBIGUOUS]);
        }

        $baseLabel = StartRobotGatheringAction::baseLabel($charId, $baseCell);
        $workshop  = StartRobotGatheringAction::workshopAtBase($charId, $baseCell);
        $levelRaw      = is_array($workshop) ? ($workshop['level'] ?? 0) : 0;
        $workshopLevel = is_numeric($levelRaw) ? (int) $levelRaw : 0;

        if ($workshopLevel <= 0 && ! $this->hasAutomationGear($charId)) {
            return $this->renderLocked($chatId, $baseLabel, $baseId);
        }

        return $this->renderHangar($chatId, $charId, $workshopLevel, $baseLabel, $baseId);
    }

    /**
     * Lock-state: на ЭТОЙ базе мастерской нет — объясняем ценность автоматизации
     * и путь к ней (не хозяйство другой базы — multibase-picker-04).
     */
    private function renderLocked(int $chatId, string $baseLabel, ?int $baseId): ServerResponse
    {
        $text = "🔒 *Ангар закрыт — база {$baseLabel}*\n\n"
            . "Автоматизация работает на тебя, пока ты офлайн:\n"
            . "  🤖 *роботы* — исследуют карту и добывают ресурсы часами;\n"
            . "  🚁 *дроны* — мгновенно разведывают зону 21×21, доставляют груз на базу, "
            . "чинят всех роботов разом и защищают базу в бою.\n\n"
            . "Здесь нужна Мастерская робототехники на этой базе. "
            . "Построй её: 🏠 База → 🏗 Строить → 🤖 Мастерская робототехники.";

        $rows = [
            [
                ['text' => '🏗 Строить', 'callback_data' => $this->withBaseSuffix('Build', $baseId)],
                ['text' => '🏠 База',    'callback_data' => $this->withBaseSuffix('Base', $baseId)],
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
     * `callback_data` с суффиксом базы, если ангар был открыт с ним (`hangar_b<id>`),
     * иначе — без изменений (multibase-picker-04). Роботы/дроны — инвентарь персонажа
     * (не база-scoped, см. Non-goals истории), суффикс им не нужен.
     */
    private function withBaseSuffix(string $callbackData, ?int $baseId): string
    {
        if ($baseId === null) {
            return $callbackData;
        }
        try {
            return BaseCallbackSuffix::append($callbackData, $baseId);
        } catch (\LengthException) {
            return $callbackData;
        }
    }

    /**
     * Основной экран: сводка роботов + дронов + кнопки на существующие экраны.
     */
    private function renderHangar(int $chatId, int $charId, int $workshopLevel, string $baseLabel, ?int $baseId): ServerResponse
    {
        $service = new DroneService();

        $workshopLine = $workshopLevel > 0
            ? "_Мастерская робототехники ({$baseLabel}): уровень {$workshopLevel}_"
            : "_Мастерской робототехники на этой базе ({$baseLabel}) нет — построй: 🏠 База → 🏗 Строить → 🤖 Мастерская робототехники_";

        $text = "🤖 *Ангар автоматизации*\n"
            . "{$workshopLine}\n\n";

        $text .= "*Роботы:*\n" . $this->robotsBlock($charId) . "\n";
        $text .= "*Дроны:*\n" . $this->dronesBlock($charId, $workshopLevel, $service);

        $rows = $this->automationRows($service, $baseId);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }

    /**
     * multibase-picker-10 (lead-review Major 1) — «голый» хаб: игрок без единой
     * базы в охвате (нет баз вовсе, или 2+ базы вне текущей и вне сигнала).
     * Инвентарь роботов/дронов — как в обычном хабе (это имущество персонажа,
     * не базы), но вместо строки Мастерской — честный lock, и НИ ОДНА база не
     * называется местной, уровень Мастерской не показывается (ask 4).
     */
    private function renderNoBaseHub(int $chatId, int $charId, ?string $reason): ServerResponse
    {
        $service = new DroneService();

        $lockLine = $reason === BaseScopeResolver::REASON_NO_BASES
            ? "🔒 *Ангар закрыт — базы нет*\n\nСначала разбей лагерь: без базы негде поставить Мастерскую робототехники."
            : "🔒 *Ангар закрыт — рядом нет твоей базы*\n\nВстань на базу или подойди под сигнал её Вышки связи — Ангар покажет Мастерскую именно той базы.";

        $text = "🤖 *Ангар автоматизации*\n"
            . "{$lockLine}\n"
            . "Построй Мастерскую: 🏠 База → 🏗 Строить → 🤖 Мастерская робототехники.\n\n";

        $text .= "*Роботы:*\n" . $this->robotsBlock($charId) . "\n";
        $text .= "*Дроны:*\n" . $this->dronesBlock($charId, 0, $service);

        $rows = $this->automationRows($service, null);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }

    /**
     * Кнопки хаба: роботы, включённые типы дронов, крафт-страховка и «🏠 База».
     * `$baseId === null` (нет базы в охвате) → «🏠 База» без суффикса — ведёт
     * на пикер/единственную базу, а не выдумывает суффикс несуществующей базы.
     *
     * @return list<list<array{text:string, callback_data:string}>>
     */
    private function automationRows(DroneService $service, ?int $baseId): array
    {
        $rows   = [];
        $rows[] = [['text' => '🤖 Роботы', 'callback_data' => 'AllRobots']];

        $droneButtons = [];
        if ($service->isEnabled()) {
            $droneButtons[] = ['text' => '🚁 Дроны', 'callback_data' => 'droneScoutList'];
        }
        if ($service->cargoIsEnabled()) {
            $droneButtons[] = ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'];
        }
        if ($service->repairIsEnabled()) {
            $droneButtons[] = ['text' => '🔧 Ремонтник', 'callback_data' => 'repairDrone'];
        }
        if ($service->combatIsEnabled()) {
            $droneButtons[] = ['text' => '🛡 Боевой дрон', 'callback_data' => 'combatDroneList'];
        }
        foreach (array_chunk($droneButtons, 2) as $chunk) {
            $rows[] = $chunk;
        }

        // ADR-172 — вход в крафт-страховку прямо оттуда, где игрок смотрит на свою
        // технику. Кнопка безусловная: при выключенном killswitch'е экран страховки
        // сам объяснит, что агент не работает, а не оставит игрока без двери.
        $rows[] = [
            ['text' => '📦 Крафт-страховка', 'callback_data' => 'craftInsuranceList'],
            ['text' => '🏠 База',              'callback_data' => $this->withBaseSuffix('Base', $baseId)],
        ];

        return $rows;
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
            // Путь называем ЖИВЫМИ подписями кнопок: нижнее меню отдаёт метку через
            // menuLabel(), а раздел крафта подписан «🔧 Стандартный крафт» — кнопки
            // «Стандартный верстак» в интерфейсе нет вовсе (это имя верстака-предмета).
            $lines[] = "  _Роботов нет. Крафт: " . BotMenuService::menuLabel('craft')
                . " → 🔧 Стандартный крафт → 🤖 Роботы._";
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
                $lines[] = "  {$type['icon']} {$type['label']} — _не скрафчен (раздел «🔧 Стандартный крафт»)_";
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
     * Есть ли у персонажа хотя бы одна единица техники (дрон/робот) на руках —
     * например, дрон куплен у каравана без мастерской (ADR-172/S03). Один запрос
     * на оба типа сразу, не по одному на тип.
     */
    private function hasAutomationGear(int $charId): bool
    {
        $ids = (new CraftedItemsModel())
            ->whereIn('type', ['drones', 'robots'])
            ->findColumn('id');
        if ($ids === null || $ids === []) {
            return false;
        }

        $numericIds = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $numericIds[] = (int) $id;
            }
        }
        if ($numericIds === []) {
            return false;
        }

        return (new CraftedItemsLogModel())
            ->where('character_id', $charId)
            ->whereIn('crafted_item_id', $numericIds)
            ->where('quantity >', 0)
            ->countAllResults() > 0;
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
