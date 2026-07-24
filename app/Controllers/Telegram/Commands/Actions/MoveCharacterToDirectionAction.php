<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerDetectionService;
use App\Services\Player\Progression\EarlyProgressionService;
use App\Services\World\TextMapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, обрабатывающий перемещение персонажа в одно из 8 направлений
 * через колбэк "move_dir_{direction}"
 *
 * 1) Проверяем ресурсы, исследования, блокировки
 * 2) Меняем координаты персонажа
 * 3) Формируем НОВЫЙ текст карты (в том же формате, что в MoveCharacterAction)
 * 4) Пытаемся "editMessageText", используя last_map_message_id
 * 5) Если неудачно (например, исходное сообщение удалено) — делаем sendMessage (fallback).
 */
class MoveCharacterToDirectionAction
{
    protected $callbackQuery;

    // Модели
    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;

    protected $playerDetectionService;

    /**
     * Границы карты — плотная сетка координат 0..999 × 0..999 (1 000 000 клеток).
     * E2 (ROADMAP-100): шаг за эти пределы = край острова, не немой отказ.
     */
    private const MAP_MIN = 0;
    private const MAP_MAX = 999;

    /** @var array<string, array{int,int}> dx,dy по 8 направлениям (y растёт на юг). */
    private const DIRECTIONS = [
        'north'      => [ 0, -1 ],
        'south'      => [ 0,  1 ],
        'west'       => [-1,  0 ],
        'east'       => [ 1,  0 ],
        'northwest'  => [-1, -1 ],
        'northeast'  => [ 1, -1 ],
        'southwest'  => [-1,  1 ],
        'southeast'  => [ 1,  1 ],
    ];

    // Расходы на перемещение
    protected $baseHealthCost = 0.1;
    protected $baseTiredCost  = 3.35;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;

        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->taskModel              = new TaskModel();
        $this->mapModel               = new MapModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->exploredCellsModel     = new ExploredCellsModel();
        $this->biomeModel             = new BiomeModel();
        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // E2: "часики" снимаем осмысленно ниже — пустым ответом на успешном/ресурсном
        // пути, либо alert'ом на краю мира. Ранний безусловный answer мешал бы показать
        // alert (Telegram позволяет ответить на callback_query лишь один раз).

        $callbackData = $this->callbackQuery->getData(); // "move_dir_north" etc
        $direction    = str_replace('move_dir_', '', $callbackData);

        // Валидируем направление
        if (!isset(self::DIRECTIONS[$direction])) {
            $this->dismissSpinner();
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Неизвестное направление: {$direction}."
            ]);
        }

        // Ищем telegram-пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            $this->dismissSpinner();
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден.'
            ]);
        }

        // Ищем персонажа
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            $this->dismissSpinner();
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или нет cell_number.'
            ]);
        }

        // Проверка активного переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверка других блокирующих задач
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('telegram_user_id', $user['id'])
            ->where('status', 'in_work')
            ->findAll();

        foreach ($activeTasks as $taskRow) {
            $taskInfo = $this->taskModel->find($taskRow['task_id']);
            if ($taskInfo && $taskInfo['parallel_execution_allowed'] == 0) {
                $taskName = $taskInfo['name_rus'] ?? $taskInfo['name'];
                $text = "🚫 Невозможно переместиться!\n"
                    . "У вас идёт задача: {$taskName}\n"
                    . "Сначала дождитесь окончания.";
                $this->dismissSpinner();
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'Markdown'
                ]);
            }
        }

        // Текущая ячейка
        $currentCell = $this->mapModel
            ->where('cell_number', $character['cell_number'])
            ->first();
        if (!$currentCell) {
            $this->dismissSpinner();
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Текущая локация не найдена!"
            ]);
        }

        // Координаты новой ячейки
        [$dx, $dy] = self::DIRECTIONS[$direction];
        $curX = (int) $currentCell['coordinate_x'];
        $curY = (int) $currentCell['coordinate_y'];
        $newX = $curX + $dx;
        $newY = $curY + $dy;

        // — Край мира (E2, ROADMAP-100) —
        // Шаг за пределы сетки 0..999 = край острова. Раньше это давало немой сухой
        // отказ «Нет ячейки по направлению» (новички у южной кромки Y≥900 регулярно
        // в него упирались). Теперь — заметный alert с подсказкой, куда МОЖНО пойти.
        // Экран не дёргаем: персонаж остаётся на месте, ход не тратится, кнопки те же.
        $targetCell = $this->mapModel
            ->where('coordinate_x', $newX)
            ->where('coordinate_y', $newY)
            ->first();
        $outOfBounds = $newX < self::MAP_MIN || $newX > self::MAP_MAX
            || $newY < self::MAP_MIN || $newY > self::MAP_MAX;
        if ($outOfBounds || !$targetCell) {
            $dirRu     = $this->directionsTranslation[$direction] ?? $direction;
            $available = $this->describeAvailableDirections($curX, $curY);
            $alert     = "🧭 Там край острова — дальше на {$dirRu} пути нет.\n"
                . ($available !== '' ? "Можно пойти: {$available}" : 'Двигайся вглубь острова.');
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => mb_substr($alert, 0, 200),
                'show_alert'        => true,
            ]);
            return Request::emptyResponse();
        }

        // Не край — снимаем «часики»; дальше либо отказ по ресурсам, либо успешный шаг.
        $this->dismissSpinner();

        // ADR-019 §2 (Step 1): гейт «клетка должна быть заранее исследована» снят —
        // движение И есть разведка. В любую in-bounds клетку шагнуть можно; факт
        // прихода раскрывает её + 8 соседей (туман войны radius-1) ниже, после move.
        // (Блокировки воды / чужого лагеря для одиночного шага — отдельный батч марша.)

        // Списываем здоровье/усталость.
        // ADR-138 (S3): новичку (level<cap) цена усталости хода ×move_cost_factor + ранние
        // гейны ×gain_multiplier. Dormant/ветеран → факторы 1.0 = byte-identical.
        $early      = new EarlyProgressionService();
        $charLevel  = (float) ($character['level'] ?? 1);
        $healthCost = $this->baseHealthCost;
        $tiredCost  = $this->baseTiredCost * $early->moveCostFactor($charLevel);

        // Если биом опасный
        $biome = $this->biomeModel->find($targetCell['biome_id']);
        if ($biome && $biome['danger_level'] >= 8) {
            $healthCost += 1.15;
        }

        $futureHealth = $character['health'] - $healthCost;
        $futureTired  = $character['tired']  - $tiredCost;
        if ($futureHealth < 0 || $futureTired < 0) {
            $text = "Недостаточно ресурсов на перемещение!\n"
                . "Здоровье после перехода: {$futureHealth}\n"
                . "Усталость после перехода: {$futureTired}";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text
            ]);
        }

        // Обновляем персонажа (ADR-138: ранние гейны ×gain_multiplier для новичка).
        // Fix 2026-07-13 (класс lost-update): статы — атомарным relative-UPDATE от
        // свежих значений (CharacterStatsService), позиция — отдельным update.
        $earlyMult       = $early->gainMultiplier($charLevel);
        $addedStrength   = 0.02 * $earlyMult;
        $addedExperience = 0.03 * $earlyMult;
        (new \App\Services\Player\CharacterStatsService())->adjust((int) $character['id'], [
            'health'     => -$healthCost,
            'tired'      => -$tiredCost,
            'strength'   => $addedStrength,
            'experience' => $addedExperience,
        ]);
        $this->characterModel->update($character['id'], [
            'cell_number' => $targetCell['cell_number'],
            'biome_id'    => $targetCell['biome_id'],
        ]);

        // Туман войны (ADR-019 §1): раскрываем 3×3-окно вокруг новой позиции —
        // саму клетку + 8 соседей по Чебышёву. Идемпотентно (де-дуп внутри).
        $this->exploredCellsModel->revealAround(
            (int) $character['id'],
            (int) $user['id'],
            (int) $targetCell['coordinate_x'],
            (int) $targetCell['coordinate_y'],
            isset($character['level']) ? (int) $character['level'] : null
        );

        // Теперь нужно заново нарисовать 12×12 карту, легенду и т.д.,
        // как в MoveCharacterAction
        $textMapService = new TextMapService();
        $updatedText    = $this->buildUpdatedMapText($character['id'], $textMapService);

        // Дополнительно можно приписать "Вы двинулись: {direction}"
        // Либо вписать в карту, на ваше усмотрение:
        $directionTranslated = $this->directionsTranslation[$direction] ?? $direction;
        $updatedText .= "\nВы двинулись на: *{$directionTranslated}*\n";

        // Кнопки те же самые, чтобы можно было продолжать двигаться. Розетка берётся из
        // ЕДИНОГО источника {@see MoveSurfaceService::compassRows()}: раньше здесь лежала
        // собственная копия тех же трёх рядов, и любая правка поверхности ходьбы обязана
        // была помнить про оба места (memory feedback_twin_hotfix_grep). Копия и разошлась —
        // слайс «Второй шаг» (2026-07-24) чинил бы первый рендер, а экран шага, где новичок
        // и проводит всё время, остался бы прежним.
        $directionsKeyboard = (new \App\Services\World\MoveSurfaceService())->compassRows();

        // Tail (sibling-кнопки за пределами 3×3 направлений): Поход / Караван / Дрон /
        // Карго-дрон / Склад. Накопить в одном массиве и разбить по 2 в строку —
        // memory feedback_inline_keyboard_pack_sibling_buttons (rework-rule, повторно
        // нарушено 2026-05-27: cargo-button и storage-button сначала шли соло-строками
        // тогда как «Поход» был sibling-кандидатом для паковки). Telegram inline
        // keyboard вмещает 2-3 короткие кнопки в строку — компактнее, читабельнее.
        // ADR-150 Слайс 1 — world_hub флаг. При ON нав-ряд «Поход/Легенда/Обзор» идёт
        // ТРЕМЯ кнопками в ОДНОМ ряду (Легенда посередине), а сиблинги (караван/дрон/…)
        // пакуются по 2 отдельными рядами ниже. При OFF — «Поход» в общей паковке с
        // сиблингами → рендер $tail byte-identical прежнему (и синхронно с MoveSurfaceService).
        $whRaw      = (new \App\Services\GameSettings\GameSettingsService())->get('navigation.world_hub.enabled', false);
        $worldHubOn = is_bool($whRaw) ? $whRaw : (is_numeric($whRaw) && (int) $whRaw === 1);

        $tail = [];
        if ($worldHubOn) {
            $directionsKeyboard[] = [
                ['text' => '🗺️ Поход',   'callback_data' => 'march'],       // ADR-019
                ['text' => '❓ Легенда', 'callback_data' => 'mapLegend'],   // тумблер легенды
                ['text' => '🗺 Обзор',   'callback_data' => 'mapOverview'], // фото карты мира
            ];
        } else {
            $tail[] = ['text' => '🗺️ Поход', 'callback_data' => 'march']; // ADR-019
        }

        // V25 (ADR-057) — если на НОВОЙ клетке (после move) стоит активный караван,
        // показать кнопку «🚚 Караван». `$character` загружен ДО update'а, поэтому
        // берём `$targetCell['cell_number']` — куда персонаж только что перешёл.
        if ((new \App\Services\Player\CaravanService())->enabled()) {
            $caravansHere = (new \App\Models\CaravanModel())->findActiveOnCell((int) $targetCell['cell_number']);
            if (! empty($caravansHere)) {
                $tail[] = ['text' => '🚚 Караван', 'callback_data' => 'caravanLook'];
            }
        }

        // ADR-101 Фаза 1 — если на клетке активное поселение, показать «🏚 Войти» → экран-хаб.
        // Подавляем «👤 Незнакомец»: жители поселения доступны через хаб (safe-зона блокирует атаку).
        // Killswitch settlements.enabled → policyAt=null = dormant.
        $settleCell      = (int) $targetCell['cell_number'];
        $nodeSightedHere = false; // WB13 — живой узел на новой клетке → one-shot подсказка ниже
        $settlePolicy    = (new \App\Services\Settlement\SettlementZoneService())->policyAt($settleCell);
        if ($settlePolicy !== null) {
            $sName = is_string($settlePolicy['settlement']['name_ru'] ?? null) ? $settlePolicy['settlement']['name_ru'] : 'Поселение';
            $tail[] = ['text' => '🏚 ' . $sName, 'callback_data' => 'settleHub'];
        } else {
            // WB12 (ADR-137 «Узлы») — узел-босс на клетке (alive ИЛИ cooldown): явная кнопка «☠ Узел»
            // → карточка осмотра (nodeAct_look). Приоритет над «👤 Незнакомец» (узел материализуется
            // как npc_spawn → иначе выглядел бы нейтралом). Killswitch world.nodes.point_mode_enabled
            // внутри pointAtCell → OFF = null = dormant (без лишнего запроса).
            $nodeSvc   = new \App\Services\PVE\BossEncounterService();
            $nodePoint = $nodeSvc->pointAtCell($settleCell);
            if ($nodePoint !== null) {
                $tail[] = ['text' => '☠ Узел', 'callback_data' => 'nodeAct_look_0'];
                // WB13 (ADR-137) — first-sighting хинт только для ЖИВОГО узла (его можно осмотреть/
                // атаковать). Cooldown-точку (lock-state) хинтом не сопровождаем — Осмотр там покажет
                // лишь таймер респавна. One-shot/opt-out/killswitch — внутри сервиса хинтов.
                $nodeSightedHere = ($nodePoint['status'] ?? '') === 'alive';
            } else {
                // ADR-089 Фаза 1 — если на клетке стоит нейтральный (passive) NPC, показать
                // кнопку «👤 Незнакомец» → экран встречи. Killswitch npc.interaction_enabled (dormant).
                $npcInteraction = new \App\Services\NPC\NpcInteractionService();
                if ($npcInteraction->enabled()
                    && $npcInteraction->passiveSpawnOnCell($settleCell) !== null) {
                    $tail[] = ['text' => '👤 Незнакомец', 'callback_data' => 'npcEncounter'];
                }
            }
        }

        // ADR-129 — если на клетке active strategic-объект (Бункер/Технопарк/Город-призрак/
        // Старая ферма/Сердце острова), показать «🔍 Обыскать». Радиосигнал (ADR-098) ведёт
        // сюда, но одиночный шаг (move_dir) НЕ запускает discovery (только марш) → без явной
        // кнопки игрок упирался в «ничего не происходит» (прод-инцидент 2026-06-15).
        $stratName = (new \App\Services\World\StrategicObjectService())->nameOnCell($settleCell);
        if ($stratName !== null) {
            $tail[] = ['text' => '🔍 Обыскать: ' . $stratName, 'callback_data' => 'strategicSearch'];
        }

        // W2 (ADR-058) — кнопка «🚁 Дрон» если у чара есть DroneScout с qty>0.
        // W3b (ADR-060) + CLAUDE.md §🎮 UX-DISCOVERABILITY правило #4 —
        // Карго-дрон ВСЕГДА виден (active или lock-state), discoverability
        // без предзнаний. Active → cargoDroneList; lock → cargoDroneLocked alert.
        $droneService = new \App\Services\Player\DroneService();
        if ($droneService->isEnabled()) {
            $droneRow = (new \App\Models\CraftedItemsModel())->where('name_eng', 'DroneScout')->first();
            if (is_array($droneRow)) {
                $rawDroneId = $droneRow['id'] ?? null;
                $droneId    = is_numeric($rawDroneId) ? (int) $rawDroneId : 0;
                if ($droneId > 0) {
                    $hasDrone = (new \App\Models\CraftedItemsLogModel())
                        ->where('character_id', $character['id'])
                        ->where('crafted_item_id', $droneId)
                        ->where('quantity >', 0)
                        ->first();
                    if ($hasDrone) {
                        $tail[] = ['text' => '🚁 Дрон', 'callback_data' => 'droneScoutList'];
                    }
                }
            }
        }

        if ($droneService->cargoIsEnabled()) {
            $cargoRow = (new \App\Models\CraftedItemsModel())->where('name_eng', 'DroneCargo')->first();
            $cargoId  = is_array($cargoRow) && is_numeric($cargoRow['id'] ?? null) ? (int) $cargoRow['id'] : 0;
            $hasCargo = false;
            if ($cargoId > 0) {
                $cargoLog = (new \App\Models\CraftedItemsLogModel())
                    ->where('character_id', $character['id'])
                    ->where('crafted_item_id', $cargoId)
                    ->where('quantity >', 0)
                    ->first();
                $hasCargo = is_array($cargoLog);
            }
            $tail[] = $hasCargo
                ? ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList']
                : ['text' => '🔒 Карго-дрон', 'callback_data' => 'cargoDroneLocked'];

            // W3b: «📦 Склад» видна когда у чара есть entries в base_storage
            // (закрывает loop: cargo доставил → retrieve UI достижим без предзнаний).
            $hasStorage = (new \App\Models\BaseStorageModel())
                ->where('character_id', $character['id'])
                ->countAllResults();
            if ($hasStorage > 0) {
                $tail[] = ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'];
            }
        }

        // Пакуем tail по 2 кнопки в строку (компактнее, sibling-rule).
        for ($i = 0, $n = count($tail); $i < $n; $i += 2) {
            $directionsKeyboard[] = array_slice($tail, $i, 2);
        }

        $keyboard = ['inline_keyboard' => $directionsKeyboard];

        // Редактируем ИМЕННО ТО сообщение, на котором нажали кнопку направления
        // (ADR-018 navTarget-паттерн) — а не «последнее» last_map_message_id: если
        // у игрока открыто несколько экранов «Переехать», правка должна попадать в
        // тот, где он жмёт. last_map_message_id остаётся вторым кандидатом.
        $clickedMsgId = $this->callbackQuery->getMessage()->getMessageId();
        $editTargets  = array_values(array_unique(array_filter([
            $clickedMsgId,
            $user['last_map_message_id'] ?? null,
        ])));

        foreach ($editTargets as $targetMsgId) {
            $editResponse = Request::editMessageText([
                'chat_id'      => $chatId,
                'message_id'   => $targetMsgId,
                'text'         => $updatedText,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);

            if ($editResponse->isOk()) {
                // Запоминаем фактически отредактированное сообщение как «карту».
                if ($targetMsgId !== ($user['last_map_message_id'] ?? null)) {
                    $this->telegramUserModel->update($user['id'], ['last_map_message_id' => $targetMsgId]);
                }
                $this->playerDetectionService->detectNearbyPlayers($character['id']);
                // ADR-103 Часть B Слой 1 — one-shot подсказка «первая база» новичку без базы.
                $hintSvc = new \App\Services\Onboarding\OnboardingHintService();
                $hintSvc->maybeSendFirstBaseTip($character, $chatId);
                // WB13 (ADR-137 «Узлы») — one-shot подсказка при первом обнаружении живого узла.
                if ($nodeSightedHere) {
                    $hintSvc->maybeSendFirstBossSightingHint($character, $chatId);
                }
                // ADR-104 Ф3b — гарантированный «момент удачи» на первый ход новичка (one-shot).
                (new \App\Services\Onboarding\LuckyFindService())->maybeGrantFirstMove($character, $chatId);
                // S4 (ADR-139) слайс 3 — cold-open приманка: если на НОВОЙ клетке ($targetCell) активная
                // приманка по сигналу → авто-находка + cleared (одиночный ход discovery не запускает).
                (new \App\Services\Onboarding\ColdOpenSignalService())->tryReachBait($character, $targetCell, $chatId);
                // S9 (ADR-147) — ранняя выживальческая атмосфера: телеграфированный шорох-выбор / JIT
                // «найди воду» новичку (one-shot). Killswitch world.events.newbie_atmosphere.enabled
                // → OFF = no-op = byte-identical.
                (new \App\Services\Onboarding\NewbieAtmosphereService())->maybeSendAtmosphere($character, $chatId);
                return $editResponse;
            }
        }

        // Если дошли сюда, значит либо нет last_map_message_id,
        // либо editMessageText вернул ошибку.
        // => отправляем новое сообщение
        $newMsgResponse = Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $updatedText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        if ($newMsgResponse->isOk()) {
            $result = $newMsgResponse->getResult();
            if (is_object($result) && method_exists($result, 'getMessageId')) {
                $messageId = $result->getMessageId();
                $this->telegramUserModel->update($user['id'], [
                    'last_map_message_id' => $messageId
                ]);
            }
        }

        $this->playerDetectionService->detectNearbyPlayers($character['id']);
        // ADR-103 Часть B Слой 1 — one-shot подсказка «первая база» новичку без базы.
        $hintSvc = new \App\Services\Onboarding\OnboardingHintService();
        $hintSvc->maybeSendFirstBaseTip($character, $chatId);
        // WB13 (ADR-137 «Узлы») — one-shot подсказка при первом обнаружении живого узла.
        if ($nodeSightedHere) {
            $hintSvc->maybeSendFirstBossSightingHint($character, $chatId);
        }
        // ADR-104 Ф3b — гарантированный «момент удачи» на первый ход новичка (one-shot).
        (new \App\Services\Onboarding\LuckyFindService())->maybeGrantFirstMove($character, $chatId);
        // S9 (ADR-147) — ранняя выживальческая атмосфера (шорох-выбор / JIT «найди воду»), one-shot,
        // killswitch world.events.newbie_atmosphere.enabled → OFF = no-op = byte-identical.
        (new \App\Services\Onboarding\NewbieAtmosphereService())->maybeSendAtmosphere($character, $chatId);
        return $newMsgResponse;
    }

    protected $directionsTranslation = [
        'north'      => 'север',
        'south'      => 'юг',
        'west'       => 'запад',
        'east'       => 'восток',
        'northwest'  => 'северо-запад',
        'northeast'  => 'северо-восток',
        'southwest'  => 'юго-запад',
        'southeast'  => 'юго-восток',
    ];

    /** Снять «часики» с нажатой кнопки (пустой ответ на callback_query). */
    private function dismissSpinner(): void
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);
    }

    /**
     * E2 — направления (из 8), по которым из клетки (curX,curY) есть ход в пределах
     * карты. Чистая функция: только проверка границ сетки, без обращения к БД.
     * Карта плотная (1M клеток без дыр) → in-bounds эквивалентно «клетка существует».
     *
     * @return list<string> ключи направлений в каноничном порядке self::DIRECTIONS
     */
    public static function availableDirections(int $curX, int $curY): array
    {
        $out = [];
        foreach (self::DIRECTIONS as $dir => [$dx, $dy]) {
            $nx = $curX + $dx;
            $ny = $curY + $dy;
            if ($nx >= self::MAP_MIN && $nx <= self::MAP_MAX
                && $ny >= self::MAP_MIN && $ny <= self::MAP_MAX) {
                $out[] = $dir;
            }
        }
        return $out;
    }

    /** E2 — человекочитаемый список доступных направлений (для alert'а у края). */
    private function describeAvailableDirections(int $curX, int $curY): string
    {
        $labels = [];
        foreach (self::availableDirections($curX, $curY) as $dir) {
            $labels[] = $this->directionsTranslation[$dir] ?? $dir;
        }
        return implode(', ', $labels);
    }

    /**
     * Собираем заново 12×12 карту, легенду и т.д. для уже ОБНОВЛЁННОГО персонажа.
     * (аналогично тому, что делали в MoveCharacterAction)
     */
    protected function buildUpdatedMapText(int $characterId, TextMapService $textMapService): string
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return "Ошибка: персонаж не найден!";
        }

        $text = "Куда пойдём? Выберите направление:\n\n";

        // ADR-150 Слайс 1 — при world_hub ON легенда спрятана за кнопку «❓ Легенда»
        // (тумблер), чтобы не занимать ~80% сообщения. При OFF — в теле (byte-identical).
        $whRaw      = (new \App\Services\GameSettings\GameSettingsService())->get('navigation.world_hub.enabled', false);
        $worldHubOn = is_bool($whRaw) ? $whRaw : (is_numeric($whRaw) && (int) $whRaw === 1);
        if (! $worldHubOn) {
            $text .= $textMapService->getLegend() . "\n";
        }

        // Расстояние до базы
        $distanceLine = $textMapService->getDistanceLine($character);
        if ($distanceLine) {
            $text .= $distanceLine . "\n";
        }

        // Здоровье / усталость (уже обновлённые)
        $hp    = (float)($character['health'] ?? 0);
        $tired = (float)($character['tired'] ?? 0);
        $text .= "❤️ Здоровье: {$hp}\n"
            . "💤 Усталость: {$tired}\n\n";

        // 12×12 карта
        $mapOnly = $textMapService->buildMapOnly($character);
        $text   .= $mapOnly . "\n";

        // Координаты
        $mapRow = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if ($mapRow) {
            $px = $mapRow['coordinate_x'];
            $py = $mapRow['coordinate_y'];
            $text .= "Игрок по центру (X={$px}, Y={$py})\n";
        }

        return $text;
    }
}
