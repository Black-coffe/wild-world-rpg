<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use Config\CallbackRoutes;

/**
 * Детерминированный экстрактор графа навигации игры.
 *
 * Источник правды о навигации Telegram-бота распределён по коду:
 *   - {@see \Config\CallbackRoutes} — резолв первого сегмента `callback_data` → класс-handler
 *     (exact + wildcard + prefix маршруты);
 *   - {@see \App\Services\Telegram\CallbackPrefixDispatcher} — отдельный слой prefix-маршрутов
 *     (`pollVote_`, `upgrade_building_`, `repair_`, …) — здесь зеркалится в {@see self::PREFIX_DISPATCHER};
 *   - сами кнопки (метка + `callback_data`) строятся внутри ~200 файлов
 *     (`Controllers/Telegram`, `Services`, `TaskHandlers`) как массивы
 *     `['text' => …, 'callback_data' => …]`.
 *
 * Сервис сканирует исходники, извлекает кнопки, резолвит каждую `callback_data`
 * в целевой класс по той же цепочке, что и {@see \App\Controllers\Telegram\Commands\SystemCommands\CallbackqueryCommand::execute()},
 * и строит граф: узлы (экраны), рёбра (кнопки→экран), точки входа, метрики UX
 * (глубина от входа, тупики, сироты, fan-out, нерезолвенные кнопки).
 *
 * Read-only: ничего не пишет в БД и не дёргает Telegram. Используется
 * админ-визуализацией `/admin/navigation` (live) и throwaway-генератором JSON-карты.
 *
 * ⚠️ Извлечение кнопок — эвристический regex-парсинг (не AST). Покрывает
 * доминирующий паттерн кодовой базы (`['text' => '…', 'callback_data' => '…']`).
 * Динамические `callback_data` (`'genericCraft_' . $key`, `"repair_$id"`) резолвятся
 * по статической ведущей части (до первой интерполяции/конкатенации).
 */
final class NavigationMapService
{
    /**
     * Корни сканирования относительно APPPATH. Любой `.php` под ними,
     * содержащий `callback_data`, становится узлом-источником рёбер.
     *
     * @var list<string>
     */
    private const SCAN_DIRS = [
        'Controllers/Telegram',
        'Services',
        'TaskHandlers',
    ];

    /**
     * Prefix-маршруты, зеркалящие {@see \App\Services\Telegram\CallbackPrefixDispatcher::tryDispatch()}.
     * Порядок сохранён 1:1 (str_starts_with, первый совпавший выигрывает).
     *
     * @var array<string, class-string>
     */
    private const PREFIX_DISPATCHER = [
        'pollVote_'                 => \App\Controllers\Telegram\Commands\Actions\Poll\PollVoteAction::class,
        'upgrade_building_'         => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction::class,
        'confirm_upgrade_building_' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction::class,
        'StartRelocationConfirm_'   => \App\Controllers\Telegram\Commands\BaseShiftingCommand::class,
        // ADR-041: ремонт оборонных зданий (confirm ПЕРЕД ask — конвенция диспетчера).
        'confirmRepairBuilding_'    => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RepairBuildingAction::class,
        'repairBuilding_'           => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RepairBuildingAction::class,
        // V25 (ADR-057) / W14b (ADR-068): NPC-караван — покупка offer'а / с торгом.
        'caravanBuyAll_'            => \App\Controllers\Telegram\Commands\Actions\Caravan\CaravanBuyAction::class,
        'caravanBuyBargain_'        => \App\Controllers\Telegram\Commands\Actions\Caravan\CaravanBuyAction::class,
        // W5 (ADR-064): NPC-караван — покупка готового дрона.
        'caravanBuyDrone_'          => \App\Controllers\Telegram\Commands\Actions\Caravan\CaravanBuyDroneAction::class,
        // W2 (ADR-058): запуск дрона-разведчика.
        'recceDrone_'               => \App\Controllers\Telegram\Commands\Actions\Drone\RecceDroneAction::class,
        // W3b (ADR-060): отправка грузового дрона.
        'cargoDroneSend_'           => \App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneSendAction::class,
        // W5 (ADR-064): активация боевого дрона.
        'combatDroneActivate_'      => \App\Controllers\Telegram\Commands\Actions\Drone\CombatDroneActivateAction::class,
        // V24 (ADR-056): NPC-страховой агент (confirm перед ask).
        'confirm_craft_insure_'     => \App\Controllers\Telegram\Commands\Actions\Craft\Insurance\CraftInsureItemAction::class,
        'craftInsure_'              => \App\Controllers\Telegram\Commands\Actions\Craft\Insurance\CraftInsureItemAction::class,
        // V23 (ADR-055): NPC-мастер на базе (confirm перед ask).
        'confirm_npc_repair_'       => \App\Controllers\Telegram\Commands\Actions\Craft\Repair\NpcRepairAction::class,
        'npc_repair_'               => \App\Controllers\Telegram\Commands\Actions\Craft\Repair\NpcRepairAction::class,
        // S5b: ремонт крафт-предметов у игрока (confirm перед ask).
        'confirm_repair_'           => \App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairCraftedItemAction::class,
        'repair_'                   => \App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairCraftedItemAction::class,
    ];

    /**
     * Точки входа в навигацию: слэш-команды + кнопки постоянной reply-клавиатуры
     * (зеркалит {@see \App\Controllers\Telegram\Commands\SystemCommands\GenericmessageCommand::execute()}
     * switch + {@see \App\Controllers\Telegram\Commands\StartCommand}).
     *
     * @var list<array{type:string, trigger:string, label:string, target:class-string}>
     */
    private const ENTRYPOINTS = [
        ['type' => 'command', 'trigger' => '/start',    'label' => 'Старт / онбординг',  'target' => \App\Controllers\Telegram\Commands\StartCommand::class],
        ['type' => 'command', 'trigger' => '/tasks',    'label' => 'Задачи (таймеры)',   'target' => \App\Controllers\Telegram\Commands\TasksCommand::class],
        ['type' => 'command', 'trigger' => '/tips',     'label' => 'Советы',             'target' => \App\Controllers\Telegram\Commands\TipsCommand::class],
        ['type' => 'command', 'trigger' => '/settings', 'label' => 'Настройки',          'target' => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class],
        ['type' => 'reply',   'trigger' => 'Перс',      'label' => 'Лист персонажа',     'target' => \App\Services\Player\CharacterService::class],
        ['type' => 'reply',   'trigger' => 'База',      'label' => 'Меню базы',          'target' => \App\Services\Bases\BaseServiceMessageFormatter::class],
        ['type' => 'reply',   'trigger' => 'Крафт',     'label' => 'Меню крафта',        'target' => \App\Services\Player\CraftService::class],
        ['type' => 'reply',   'trigger' => 'Карта',     'label' => 'Карта мира',         'target' => \App\Services\World\MapService::class],
        ['type' => 'reply',   'trigger' => 'Настройки', 'label' => 'Настройки',          'target' => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class],
    ];

    /**
     * Хабы внутренней PHP-диспетчеризации: класс эмитит callback с динамическим
     * суффиксом, а реальный целевой экран выбирается `switch`/`match` внутри кода
     * (невидимо для callback-парсинга). Здесь зеркалятся такие развилки, чтобы
     * дерево было связным.
     *
     * Источник: {@see \App\Controllers\Telegram\Commands\Actions\Camp\BuildingHandlerAction::handle()}
     * (`building_<id>_<NameEng>` switch → конкретный *Handler).
     *
     * @var array<class-string, list<array{label:string, to:class-string}>>
     */
    private const INTERNAL_DISPATCH = [
        \App\Controllers\Telegram\Commands\Actions\Camp\BuildingHandlerAction::class => [
            ['label' => '🚰 Колонка',            'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\HandPumpHandler::class],
            ['label' => '🔥 Домна',              'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\BlastFurnaceHandler::class],
            ['label' => '🤖 Мастерская роботов', 'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RoboticsWorkshopHandler::class],
            ['label' => '🛠️ Мастерская',         'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WorkshopHandler::class],
            ['label' => '📦 Склад',              'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WarehouseHandler::class],
            ['label' => '🔬 Лаборатория',        'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\LaboratoryHandler::class],
            ['label' => '🌱 Теплица',            'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\GreenhouseHandler::class],
            ['label' => '☀️ Солнечная станция',  'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\SolarStationHandler::class],
            ['label' => '🏋️ Спортзал',           'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\GymHandler::class],
            ['label' => '📡 Центр телепортации', 'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportationCenterHandler::class],
            ['label' => '⚔️ Арсенал',            'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\ArsenalHandler::class],
            ['label' => '📶 Вышка связи',        'to' => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\CommunicationTowerHandler::class],
        ],
    ];

    /**
     * Кэш карты класс→путь и распарсенных кнопок в пределах одного запроса.
     *
     * @var array<class-string, list<array{label:string, callback:string, callback_static:string}>>|null
     */
    private ?array $buttonsCache = null;

    /** @var array<string, mixed>|null */
    private ?array $graphCache = null;

    /**
     * Собрать полный граф навигации.
     *
     * @return array{
     *     generated_at:string,
     *     stats:array<string,int>,
     *     entrypoints:list<array<string,mixed>>,
     *     nodes:list<array<string,mixed>>,
     *     edges:list<array<string,mixed>>
     * }
     */
    public function buildGraph(): array
    {
        if ($this->graphCache !== null) {
            /** @var array{generated_at:string, stats:array<string,int>, entrypoints:list<array<string,mixed>>, nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>} $cached */
            $cached = $this->graphCache;
            return $cached;
        }

        $routes      = new CallbackRoutes();
        $buttonsByCls = $this->collectButtons();

        // --- Узлы: все классы, эмитящие кнопки, + все цели маршрутов + точки входа ---
        $nodeClasses = $this->collectNodeClasses($routes, $buttonsByCls);

        // route-карта: класс → список callback-ключей, которые в него резолвятся
        $routesByClass = $this->routeKeysByClass($routes);

        $nodes = [];
        foreach ($nodeClasses as $cls) {
            $nodes[$cls] = [
                'id'        => $this->nodeId($cls),
                'class'     => $cls,
                'short'     => $this->shortName($cls),
                'group'     => $this->groupOf($cls),
                'file'      => $this->relativePath($this->classToPath($cls)),
                'routes'    => $routesByClass[$cls] ?? [],
                'kind'      => $this->kindOf($cls, $routesByClass[$cls] ?? []),
                'out'       => 0,
                'in'        => 0,
                'depth'     => null,
            ];
        }

        // --- Рёбра: для каждого узла-источника резолвим его кнопки ---
        $edges = [];
        foreach ($buttonsByCls as $fromCls => $buttons) {
            if (!isset($nodes[$fromCls])) {
                continue;
            }
            $seen = [];
            foreach ($buttons as $btn) {
                $resolution = $this->resolveCallback($btn['callback_static'], $routes);
                $toCls      = $resolution['class'];
                // дедуп одинаковых рёбер (label+target) внутри одного экрана
                $sig = $btn['label'] . '|' . ($toCls ?? '') . '|' . $btn['callback_static'];
                if (isset($seen[$sig])) {
                    continue;
                }
                $seen[$sig] = true;

                $edges[] = [
                    'from'        => $this->nodeId($fromCls),
                    'from_class'  => $fromCls,
                    'to'          => $toCls !== null ? $this->nodeId($toCls) : null,
                    'to_class'    => $toCls,
                    'label'       => $btn['label'],
                    'callback'    => $btn['callback'],
                    'matched_by'  => $resolution['matched_by'],
                    'unresolved'  => $toCls === null,
                ];

                $nodes[$fromCls]['out']++;
                if ($toCls !== null && isset($nodes[$toCls])) {
                    $nodes[$toCls]['in']++;
                }
            }
        }

        // --- Рёбра внутренней PHP-диспетчеризации (switch/match хабы) ---
        foreach (self::INTERNAL_DISPATCH as $fromCls => $targets) {
            if (!isset($nodes[$fromCls])) {
                continue;
            }
            foreach ($targets as $t) {
                $toCls = $t['to'];
                $edges[] = [
                    'from'        => $this->nodeId($fromCls),
                    'from_class'  => $fromCls,
                    'to'          => isset($nodes[$toCls]) ? $this->nodeId($toCls) : null,
                    'to_class'    => isset($nodes[$toCls]) ? $toCls : null,
                    'label'       => $t['label'],
                    'callback'    => '(внутр. dispatch)',
                    'matched_by'  => 'internal-dispatch',
                    'unresolved'  => !isset($nodes[$toCls]),
                ];
                $nodes[$fromCls]['out']++;
                if (isset($nodes[$toCls])) {
                    $nodes[$toCls]['in']++;
                }
            }
        }

        // --- Точки входа + рёбра от них ---
        $entrypoints = [];
        foreach (self::ENTRYPOINTS as $ep) {
            $target = $ep['target'];
            $entrypoints[] = [
                'type'       => $ep['type'],
                'trigger'    => $ep['trigger'],
                'label'      => $ep['label'],
                'to_class'   => $target,
                'to'         => isset($nodes[$target]) ? $this->nodeId($target) : null,
                'resolved'   => isset($nodes[$target]),
            ];
            if (isset($nodes[$target])) {
                $nodes[$target]['in']++;
            }
        }

        // --- Метрики глубины (BFS от точек входа по рёбрам) ---
        $this->computeDepth($nodes, $edges, $entrypoints);

        $nodeList = array_values($nodes);

        $stats = [
            'nodes'       => count($nodeList),
            'edges'       => count($edges),
            'entrypoints' => count($entrypoints),
            'unresolved'  => count(array_filter($edges, static fn (array $e): bool => $e['unresolved'] === true)),
            'dead_ends'   => count(array_filter($nodeList, static fn (array $n): bool => $n['out'] === 0)),
            'orphans'     => count(array_filter($nodeList, static fn (array $n): bool => $n['in'] === 0 && $n['depth'] === null)),
            'unreachable' => count(array_filter($nodeList, static fn (array $n): bool => $n['depth'] === null)),
            'max_depth'   => $this->maxDepth($nodeList),
        ];

        $graph = [
            'generated_at' => date('c'),
            'stats'        => $stats,
            'entrypoints'  => $entrypoints,
            'nodes'        => $nodeList,
            'edges'        => $edges,
        ];

        $this->graphCache = $graph;
        return $graph;
    }

    // ====================================================================
    // Резолв callback → класс (зеркало CallbackqueryCommand цепочки)
    // ====================================================================

    /**
     * @return array{class:class-string|null, matched_by:string}
     */
    private function resolveCallback(string $callbackStatic, CallbackRoutes $routes): array
    {
        $data = $callbackStatic;
        if ($data === '') {
            return ['class' => null, 'matched_by' => 'dynamic'];
        }

        $action = explode('_', $data)[0];

        // 1) character inline shortcut
        if ($action === 'character') {
            return ['class' => \App\Services\Player\CharacterService::class, 'matched_by' => 'character-shortcut'];
        }

        // 2) wildcard routes (full callbackData)
        foreach ($routes->wildcardRoutes as $pattern => $cls) {
            if ($this->matchesWildcard($pattern, $data)) {
                return ['class' => $cls, 'matched_by' => 'wildcard:' . $pattern];
            }
        }

        // 3) prefix dispatcher (full callbackData)
        foreach (self::PREFIX_DISPATCHER as $prefix => $cls) {
            if (str_starts_with($data, $prefix) || $data === rtrim($prefix, '_')) {
                return ['class' => $cls, 'matched_by' => 'prefix-dispatcher:' . $prefix];
            }
        }

        // 4) exact route (по первому сегменту)
        if (isset($routes->exactRoutes[$action])) {
            return ['class' => $routes->exactRoutes[$action], 'matched_by' => 'exact:' . $action];
        }

        // 5) prefixRoutes (str_starts_with по первому сегменту)
        foreach ($routes->prefixRoutes as $prefix => $cls) {
            if (str_starts_with($action, $prefix)) {
                return ['class' => $cls, 'matched_by' => 'prefix:' . $prefix];
            }
        }

        return ['class' => null, 'matched_by' => 'unresolved'];
    }

    private function matchesWildcard(string $pattern, string $callbackData): bool
    {
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
        return (bool) preg_match($regex, $callbackData);
    }

    // ====================================================================
    // Сбор узлов
    // ====================================================================

    /**
     * @param array<class-string, list<array{label:string, callback:string, callback_static:string}>> $buttonsByCls
     * @return list<class-string>
     */
    private function collectNodeClasses(CallbackRoutes $routes, array $buttonsByCls): array
    {
        /** @var array<class-string, true> $set */
        $set = [];

        foreach (array_keys($buttonsByCls) as $cls) {
            $set[$cls] = true;
        }
        foreach ($routes->exactRoutes as $cls) {
            $set[$cls] = true;
        }
        foreach ($routes->wildcardRoutes as $cls) {
            $set[$cls] = true;
        }
        foreach ($routes->prefixRoutes as $cls) {
            $set[$cls] = true;
        }
        foreach (self::PREFIX_DISPATCHER as $cls) {
            $set[$cls] = true;
        }
        foreach (self::ENTRYPOINTS as $ep) {
            $set[$ep['target']] = true;
        }
        $set[\App\Services\Player\CharacterService::class] = true;

        return array_keys($set);
    }

    /**
     * Карта класс → список callback-ключей, которые в него резолвятся
     * (для отображения «как сюда попадают»).
     *
     * @return array<string, list<string>>
     */
    private function routeKeysByClass(CallbackRoutes $routes): array
    {
        /** @var array<string, list<string>> $map */
        $map = [];
        $add = static function (string $key, string $cls) use (&$map): void {
            $map[$cls] ??= [];
            $map[$cls][] = $key;
        };
        foreach ($routes->exactRoutes as $key => $cls) {
            $add((string) $key, $cls);
        }
        foreach ($routes->wildcardRoutes as $key => $cls) {
            $add((string) $key, $cls);
        }
        foreach ($routes->prefixRoutes as $key => $cls) {
            $add($key . '*', $cls);
        }
        foreach (self::PREFIX_DISPATCHER as $key => $cls) {
            $add($key . '*', $cls);
        }
        return $map;
    }

    // ====================================================================
    // Извлечение кнопок из исходников
    // ====================================================================

    /**
     * @return array<class-string, list<array{label:string, callback:string, callback_static:string}>>
     */
    private function collectButtons(): array
    {
        if ($this->buttonsCache !== null) {
            return $this->buttonsCache;
        }

        /** @var array<class-string, list<array{label:string, callback:string, callback_static:string}>> $result */
        $result = [];

        foreach ($this->scanFiles() as $path) {
            $src = @file_get_contents($path);
            if ($src === false || !str_contains($src, 'callback_data')) {
                continue;
            }
            $cls = $this->parseClass($src);
            if ($cls === null) {
                continue;
            }
            $buttons = $this->extractButtons($src);
            if ($buttons !== []) {
                $result[$cls] = array_merge($result[$cls] ?? [], $buttons);
            }
        }

        $this->buttonsCache = $result;
        return $result;
    }

    /**
     * Извлечь все кнопки из исходника: для каждой `callback_data` ищем ближайшую
     * по тексту метку `text`.
     *
     * @return list<array{label:string, callback:string, callback_static:string}>
     */
    private function extractButtons(string $src): array
    {
        // Паттерн 1: inline-кнопки Telegram — `'callback_data' => …`.
        // Паттерн 2: локальные data-массивы `['label' => …, 'callback' => …]`,
        //   которые позже мапятся в `'callback_data' => $item['callback']`
        //   (доминирует в крафт-/строй-меню: MedicalCraft1Action и т.п.).
        // `[\'"]callback[\'"]` НЕ совпадает с `callback_data` (разные литералы).
        return array_merge(
            $this->extractButtonsByKey($src, 'callback_data'),
            $this->extractButtonsByKey($src, 'callback'),
        );
    }

    /**
     * @return list<array{label:string, callback:string, callback_static:string}>
     */
    private function extractButtonsByKey(string $src, string $key): array
    {
        $buttons = [];
        $pattern = '/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*([^\r\n,\]]+)/';

        if (preg_match_all($pattern, $src, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $caps = $matches[1] ?? [];

        foreach ($caps as $cap) {
            // PREG_OFFSET_CAPTURE: $cap = [совпавшая_строка, byte-offset].
            $expr   = trim($cap[0]);
            $offset = $cap[1];
            if ($expr === '') {
                continue;
            }
            // Пропустить совпадения в строковых комментариях (`//`) — это
            // закомментированный/будущий код, не живые кнопки (иначе раздувает
            // метрику «мёртвых кнопок» ложными срабатываниями).
            $lineStartPos = strrpos(substr($src, 0, $offset), "\n");
            $lineStart    = $lineStartPos === false ? 0 : $lineStartPos + 1;
            if (str_contains(substr($src, $lineStart, $offset - $lineStart), '//')) {
                continue;
            }

            $static = $this->staticLeading($expr);
            if ($key === 'callback' && $static === '') {
                continue; // динамические `'callback' => $x` в data-массивах — шум
            }

            $buttons[] = [
                'label'           => $this->findLabel($src, $offset),
                'callback'        => $this->collapse($expr),
                'callback_static' => $static,
            ];
        }

        return $buttons;
    }

    /**
     * Найти метку кнопки: последняя `'text' => …` перед callback_data в окне
     * (порядок text→callback_data доминирует); fallback — после.
     */
    private function findLabel(string $src, int $cbOffset): string
    {
        $winStart = max(0, $cbOffset - 320);
        $before   = substr($src, $winStart, $cbOffset - $winStart);
        if (preg_match_all('/[\'"](?:text|label)[\'"]\s*=>\s*([^\r\n]+)/', $before, $m) && $m[1] !== []) {
            $raw = (string) end($m[1]);
            return $this->cleanLabel($raw);
        }
        $after = substr($src, $cbOffset, 200);
        if (preg_match('/[\'"](?:text|label)[\'"]\s*=>\s*([^\r\n]+)/', $after, $m2)) {
            return $this->cleanLabel($m2[1]);
        }
        return '(без метки)';
    }

    /**
     * Извлечь статическую ведущую часть выражения callback_data:
     *   'foo'            → foo
     *   'foo_' . $x      → foo_
     *   "repair_$id"     → repair_
     *   $var             → '' (полностью динамическая)
     */
    private function staticLeading(string $expr): string
    {
        $expr = ltrim($expr);
        if ($expr === '') {
            return '';
        }
        $quote = $expr[0];
        if ($quote !== '"' && $quote !== "'") {
            return ''; // переменная / вызов — динамика
        }
        $literal = '';
        $len = strlen($expr);
        for ($i = 1; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $literal .= $expr[$i + 1];
                $i++;
                continue;
            }
            if ($ch === $quote) {
                break; // конец литерала
            }
            if ($quote === '"' && ($ch === '$' || $ch === '{')) {
                break; // начало интерполяции в double-quoted
            }
            $literal .= $ch;
        }
        return $literal;
    }

    private function cleanLabel(string $raw): string
    {
        $raw = trim($raw);
        // первый строковый литерал
        if (preg_match('/([\'"])(.*?)\1/', $raw, $m)) {
            $label = $m[2];
            // признак конкатенации динамики после литерала
            if (preg_match('/\.\s*\$|\.\s*[A-Za-z_]/', $raw)) {
                $label .= ' …';
            }
            return $this->collapse($label);
        }
        return $this->collapse($raw);
    }

    private function collapse(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
        if (mb_strlen($s) > 80) {
            $s = mb_substr($s, 0, 79) . '…';
        }
        return $s;
    }

    // ====================================================================
    // Глубина / метрики
    // ====================================================================

    /**
     * BFS от точек входа: проставить min-глубину каждому достижимому узлу.
     *
     * @param array<class-string, array<string,mixed>> $nodes   (по ссылке)
     * @param list<array<string,mixed>>                $edges
     * @param list<array<string,mixed>>                $entrypoints
     */
    private function computeDepth(array &$nodes, array $edges, array $entrypoints): void
    {
        // adjacency by class
        /** @var array<class-string, list<class-string>> $adj */
        $adj = [];
        foreach ($edges as $e) {
            $from = $e['from_class'];
            $to   = $e['to_class'];
            if (is_string($from) && is_string($to)) {
                $adj[$from] ??= [];
                $adj[$from][] = $to;
            }
        }

        /** @var list<class-string> $queue */
        $queue = [];
        foreach ($entrypoints as $ep) {
            $cls = $ep['to_class'];
            if (is_string($cls) && isset($nodes[$cls]) && $nodes[$cls]['depth'] === null) {
                $nodes[$cls]['depth'] = 1;
                $queue[] = $cls;
            }
        }

        while ($queue !== []) {
            $cur = array_shift($queue);
            $curDepth = $nodes[$cur]['depth'];
            $d = is_int($curDepth) ? $curDepth : 1;
            foreach ($adj[$cur] ?? [] as $next) {
                if (isset($nodes[$next]) && $nodes[$next]['depth'] === null) {
                    $nodes[$next]['depth'] = $d + 1;
                    $queue[] = $next;
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $nodeList
     */
    private function maxDepth(array $nodeList): int
    {
        $max = 0;
        foreach ($nodeList as $n) {
            $d = $n['depth'];
            if (is_int($d) && $d > $max) {
                $max = $d;
            }
        }
        return $max;
    }

    // ====================================================================
    // Файлы / классы / именование
    // ====================================================================

    /**
     * @return list<string>
     */
    private function scanFiles(): array
    {
        /** @var list<string> $files */
        $files = [];
        foreach (self::SCAN_DIRS as $dir) {
            $root = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $pathName = $fileInfo->getPathname();
                    // Не сканировать сам инструмент карты — иначе его литералы
                    // `callback_data`/`callback` попадают в граф как фейковые кнопки.
                    if (str_contains(str_replace('\\', '/', $pathName), '/Services/Navigation/')) {
                        continue;
                    }
                    $files[] = $pathName;
                }
            }
        }
        return $files;
    }

    /**
     * @return class-string|null
     */
    private function parseClass(string $src): ?string
    {
        if (!preg_match('/namespace\s+([^;]+);/', $src, $nm)) {
            return null;
        }
        if (!preg_match('/(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/', $src, $cm)) {
            return null;
        }
        $fqcn = trim($nm[1]) . '\\' . trim($cm[1]);
        /** @var class-string $fqcn */
        return $fqcn;
    }

    private function classToPath(string $cls): string
    {
        // PSR-4: App\ → app/
        $rel = preg_replace('/^App\\\\/', '', $cls) ?? $cls;
        $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
        return rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . $rel;
    }

    private function relativePath(string $abs): string
    {
        $appRoot = rtrim(APPPATH, '/\\');
        $norm = str_replace('\\', '/', $abs);
        $rootNorm = str_replace('\\', '/', $appRoot);
        if (str_starts_with($norm, $rootNorm)) {
            return 'app' . substr($norm, strlen($rootNorm));
        }
        return $norm;
    }

    private function shortName(string $cls): string
    {
        $parts = explode('\\', $cls);
        return end($parts) ?: $cls;
    }

    private function nodeId(string $cls): string
    {
        return str_replace('\\', '.', preg_replace('/^App\\\\/', '', $cls) ?? $cls);
    }

    /**
     * Логическая группа узла (для веток дерева/раскраски).
     */
    private function groupOf(string $cls): string
    {
        $map = [
            'Actions\\StartGame'      => 'onboarding',
            'Actions\\Craft'          => 'craft',
            'Actions\\Sell'           => 'trade',
            'Actions\\Camp'           => 'base',
            'Actions\\Quest'          => 'quests',
            'Actions\\PVP'            => 'combat',
            'Actions\\Games'          => 'entertainment',
            'Actions\\Faction'        => 'faction',
            'Actions\\Objects'        => 'world',
            'Profile\\'               => 'profile',
            'Commands\\'              => 'commands',
            'Services\\Player'        => 'services',
            'Services\\Bases'         => 'services',
            'Services\\World'         => 'services',
            'Services\\Events'        => 'services',
            'TaskHandlers\\'          => 'task-results',
        ];
        foreach ($map as $needle => $group) {
            if (str_contains($cls, $needle)) {
                return $group;
            }
        }
        if (str_contains($cls, 'Actions\\')) {
            return 'core';
        }
        if (str_contains($cls, 'Services\\')) {
            return 'services';
        }
        return 'other';
    }

    /**
     * @param list<string> $routeKeys
     */
    private function kindOf(string $cls, array $routeKeys): string
    {
        if (str_contains($cls, 'TaskHandlers\\')) {
            return 'task-result';
        }
        if (str_contains($cls, '\\Commands\\') && !str_contains($cls, 'Actions')) {
            return 'command';
        }
        if (str_contains($cls, 'Services\\')) {
            return 'service-screen';
        }
        return $routeKeys === [] ? 'screen' : 'action';
    }
}
