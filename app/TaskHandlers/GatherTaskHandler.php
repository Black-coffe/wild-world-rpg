<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Models\TaskModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use CodeIgniter\Controller;
use DateTime;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use App\Libraries\BiomeResourceModifier;
use App\Libraries\ToolManager;

class GatherTaskHandler extends Controller
{
    protected $characterModel;
    protected $characterResourceModel;
    protected $characterTaskModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $telegramUserModel;
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $biomeResourceModifier;
    protected $toolManager;

    private $telegram;

    /**
     * Сохраняет, сколько инструментов мы использовали в рамках одного процесса сбора
     * (ключ: имя инструмента, значение: сколько раз применили).
     */
    protected array $usedToolsCount = [];

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel         = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->resourceModel          = new ResourceModel();
        $this->taskModel              = new TaskModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->biomeResourceModifier  = new BiomeResourceModifier();
        $this->toolManager            = new ToolManager();

        // Инициализация Telegram SDK
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Основная точка входа для обработки задачи сбора ресурсов.
     */
    public function handle($task)
    {
        // 1) Ищем персонажа
        $character = $this->characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', "Character not found for task ID: {$task['id']}");
            return;
        }

        // 2) Проверяем глобальное событие 'LocustExodus' (саранча)
        $locustExodusActive = $this->isEventActive('LocustExodus');
        $locustExodusEffect = 0;
        if ($locustExodusActive) {
            $locustExodusEvent  = $this->eventModel->where('name_english', 'LocustExodus')->first();
            $locustExodusEffect = (float) $locustExodusEvent['effect_value'];
        }

        // 3) Находим все потенциально доступные ресурсы для данного биома + level_required
        $resources = $this->getAvailableResources($character);

        // 4) Сколько реально времени прошло (в минутах)
        $spentMinutes = $this->calculateSpentMinutes($task['start_time'], $task['end_time']);

        // 5) Считаем добычу по новой формуле (блочной)
        $foundResources = $this->calculateFoundResources(
            resources: $resources,
            spentMinutes: $spentMinutes,
            character: $character,
            task: $task,
            locustExodusActive: $locustExodusActive,
            locustExodusEffect: $locustExodusEffect
        );

        // 6) Определяем биом (чтобы отобразить в сообщении)
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        $biome       = $currentCell ? $this->biomeModel->find($currentCell['biome_id']) : null;
        $biomeName   = $biome['name'] ?? '???';

        // 7) Применяем модификатор биома (лес, пустыня и т.д.)
        $foundResources = $this->biomeResourceModifier->modifyResourcesByBiome($biome['id'] ?? null, $foundResources);

        // 8) Сохраняем итог в БД, отмечаем задачу завершённой
        $this->saveFoundResources($foundResources, $character, $task);
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 9) Отправляем сообщение игроку (передаём $spentMinutes, $biomeName)
        $this->sendResourcesFoundReply($foundResources, $character, $spentMinutes, $biomeName);
    }

    // -------------------------------------------------------------------------
    //                             ОСНОВНЫЕ МЕТОДЫ
    // -------------------------------------------------------------------------

    /**
     * Переработанный метод: блочная логика (1 инструмент = 1 блок = 10 мин).
     * Все события, ±20% и здоровье/выносливость — применяем в конце, одним шагом.
     */
    /**
     * Переработанный метод: блочная логика (1 инструмент = 1 блок = 10 мин).
     * Все события, ±20% и здоровье/выносливость — применяем единоразово в конце.
     */
    protected function calculateFoundResources(
        array $resources,
        int $spentMinutes,
        array $character,
        array $task,
        bool $locustExodusActive,
        float $locustExodusEffect
    ): array {
        // 1) Проверяем ивенты и т.д. (как у вас)
        $isFishStockActive       = $this->isEventActive('FishStock');
        $isExoticFloweringActive = $this->isEventActive('ExoticFlowering');
        $isBerryBoomActive       = $this->isEventActive('BerryBoom');
        $isDrynessActive         = $this->isDrynessEventActive();

        $fishStockEvent       = $isFishStockActive       ? $this->eventModel->where('name_english', 'FishStock')->first() : null;
        $exoticFloweringEvent = $isExoticFloweringActive ? $this->eventModel->where('name_english', 'ExoticFlowering')->first() : null;
        $berryBoomEvent       = $isBerryBoomActive       ? $this->eventModel->where('name_english', 'BerryBoom')->first() : null;
        $drynessEvent         = $isDrynessActive         ? $this->eventModel->where('name_english', 'Dryness')->first() : null;

        // 2) rarities
        $allowedRarities = $this->getAllowedRarities($character['level']);

        // 3) Делим время на блоки
        $blocksCount = intdiv($spentMinutes, 10);
        $remainder   = $spentMinutes % 10;

        // Сколько ресурсов «за один полноценный блок»
        // Соберём это в массив вида:
        //   $baseBlockResources[$resourceId] = [
        //       'resource' => [...] (из БД),
        //       'baseQty'  => (int) ...,
        //   ];
        $baseBlockResources = [];

        foreach ($resources as $resource) {
            if (!in_array($resource['rarity'], $allowedRarities)) {
                continue;
            }
        $baseFor10Min = $this->getBaseQuantityByRarity($resource['rarity']);

        $baseBlockResources[$resource['id']] = [
            'resource' => $resource,
            'baseQty'  => $baseFor10Min,
        ];
    }

        // Это общий пул, который мы будем умножать (если есть инструменты),
        // и складывать для blocksCount раз.

        // Итого, для хранения итогов:
        $foundAmounts = []; // resourceId => суммарное кол-во (до события +/-20%)

        // ----
        // 4) Основной цикл по каждому блоку
        // ----
        for ($blockIndex = 0; $blockIndex < $blocksCount; $blockIndex++) {
            // Шаг 4.1: Выясняем, какие инструменты ПОТРЕБУЮТСЯ в этом блоке
            //   (то есть у каких ресурсов есть «бонусный» инструмент).
            //   Но НЕ списываем durability здесь по каждому ресурсу.

            // Словарь "название_инструмента" => [ resource_id1, resource_id2, ... ] для всех ресурсов
            $toolsNeeded = [];
            // А также массив "resource_id" => "лучшая_прибавка_бонус" (float)
            $resourceBonuses = [];

            // Пробегаем все ресурсы, проверяем, какой инструмент подходит
            foreach ($baseBlockResources as $resId => $arr) {
                $resourceName = $arr['resource']['name'];
                $toolsMapping = $this->toolManager->getToolsForResource($resourceName);
                if (empty($toolsMapping)) {
                    // Не нужен никакой инструмент
                    $resourceBonuses[$resId] = 0.0;
                    continue;
                }

                // Ищем лучший бонус
                $bestBonus = 0.0;
                $bestToolName = null;

                foreach ($toolsMapping as $toolName => $bonusValue) {
                    // Проверяем, есть ли у персонажа вообще такой инструмент
                    $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);
                    if (!$toolData) {
                        continue; // у персонажа нет такого инструмента
                    }
                    // Выбираем максимальный bonus
                    if ($bonusValue > $bestBonus) {
                        $bestBonus    = $bonusValue;
                        $bestToolName = $toolName;
                    }
                }

                if ($bestToolName) {
                    // Запомним, что для ресурса $resId нужен инструмент $bestToolName
                    $resourceBonuses[$resId] = $bestBonus;
                    // Добавим его в $toolsNeeded
                    $toolsNeeded[$bestToolName][] = $resId;
                } else {
                    // Нет подходящего инструмента — значит, bonus = 0
                    $resourceBonuses[$resId] = 0.0;
                }
            }

            // Шаг 4.2: Теперь списываем durability по 1 разу
            //   для каждого инструмента, который действительно будет использован в этом блоке.
            //
            //   Но может быть так, что инструмент сломается при списании
            //   (или не уменьшится прочность, если чего-то не хватает).
            //   Тогда для ресурсов, которые требовали этот инструмент — бонус = 0.
            $toolsActuallyUsed = []; // массив [ 'IronShovel', 'LumberjackAxe', ... ]

            foreach ($toolsNeeded as $toolName => $listOfResources) {
                // Проверяем наличие инструмента (свежий поиск, т.к. мог сломаться на предыдущих блоках)
                $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);
                if (!$toolData) {
                    // инструмента больше нет — у всех ресурсов bonus = 0
                    foreach ($listOfResources as $resId) {
                        $resourceBonuses[$resId] = 0.0;
                    }
                    continue;
                }

                // Пробуем уменьшить прочность на 1
                $ok = $this->toolManager->updateToolDurability($toolData);
                if (!$ok) {
                    // Инструмент сломался, списался, теперь его нет
                    // => зануляем бонусы
                    foreach ($listOfResources as $resId) {
                        $resourceBonuses[$resId] = 0.0;
                    }
                    continue;
                }

                // Раз всё ок — фиксируем, что мы реально израсходовали инструмент 1 раз
                // (для итогового отчёта)
                if (!isset($this->usedToolsCount[$toolName])) {
                    $this->usedToolsCount[$toolName] = 0;
                }
                $this->usedToolsCount[$toolName]++;
                $toolsActuallyUsed[] = $toolName;
            }

            // Шаг 4.3: Подсчитываем добычу за этот блок
            foreach ($baseBlockResources as $resId => $arr) {
                $baseQty  = $arr['baseQty'];
                $bonusVal = $resourceBonuses[$resId]; // может быть 0.0, если инструмент недоступен/сломался

                $resultQty = $baseQty;
                if ($bonusVal > 0) {
                    $resultQty *= (1 + $bonusVal);
                }
                // Накапливаем в $foundAmounts
                if ($resultQty > 0) {
                    $foundAmounts[$resId] = ($foundAmounts[$resId] ?? 0) + $resultQty;
                }
            }
        }

        // 5) Остаток (неполный блок), у вас по условию — без использования инструмента
        //    (т.е. никакого списания инструмента, никакого бонуса)
        if ($remainder > 0) {
            $leftFactor = $remainder / 10.0;
            foreach ($baseBlockResources as $resId => $arr) {
                $baseQty   = $arr['baseQty'];
                $addQty    = $baseQty * $leftFactor;
                if ($addQty > 0) {
                    $foundAmounts[$resId] = ($foundAmounts[$resId] ?? 0) + $addQty;
                }
            }
        }

        // 6) Теперь разово применяем ±20%, события, состояние здоровья и т.п.
        $foundResources = [];
        foreach ($foundAmounts as $resId => $amountRaw) {
            // ±20%
            $randFactor = rand(80, 120) / 100.0;
            $amt = $amountRaw * $randFactor;

            $resInfo = $this->resourceModel->find($resId);
            if (!$resInfo) {
                continue;
            }
            // События, саранча, бафы
            if ($isFishStockActive && $resInfo['name'] === 'Рыба') {
                $amt *= (1 + $fishStockEvent['effect_value'] / 100.0);
            }
            if ($isExoticFloweringActive && $resInfo['name'] === 'Цветы орхидей') {
                $amt *= (1 + $exoticFloweringEvent['effect_value'] / 100.0);
            }
            if ($isBerryBoomActive && $resInfo['name'] === 'Ягоды') {
                $amt *= (1 + $berryBoomEvent['effect_value'] / 100.0);
            }
            if ($locustExodusActive) {
                $amt *= (1 - $locustExodusEffect / 100.0);
            }

            // здоровье/усталость
            $htFactor = $this->getHealthTirednessFactor($character);
            $amt *= $htFactor;

            $finalAmt = (int)round($amt);
            if ($finalAmt < 1) {
                continue;
            }

            $foundResources[] = [
                'resource_id' => $resId,
                'amount'      => $finalAmt,
                'type'        => $resInfo['type'] ?? '',
            ];
        }

        // Засуха?
        if ($isDrynessActive && $drynessEvent) {
            $foundResources = $this->applyDrynessPenalty($foundResources, $drynessEvent);
        }

        // Возвращаем готовый список
        return $foundResources;
    }


    /**
     * Метод подбора инструмента для ОДНОГО 10-минутного блока.
     * Если находим лучший инструмент, уменьшаем прочность и возвращаем multiplier (1 + bonus).
     * Если нет инструмента, возвращаем 1.
     */
    protected function pickToolAndUpdate(array $resource, array $character): float
    {
        $tools = $this->toolManager->getToolsForResource($resource['name']);
        if (empty($tools)) {
            return 1.0;
        }

        $bestBonus = 0.0;
        $bestToolName = null;

        foreach ($tools as $toolName => $bonus) {
            $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);
            if (!$toolData) {
                continue;
            }

            if ($bonus > $bestBonus) {
                $bestBonus = $bonus;
                $bestToolName = $toolName; // Запоминаем имя инструмента, а не запись из БД
            }
        }

        if (!$bestToolName) {
            return 1.0; // Инструмент не найден
        }

        // Проверяем наличие инструмента в инвентаре
        $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($bestToolName, $character['id']);
        if (!$toolData) {
            return 1.0; // Инструмент не найден
        }

        // Уменьшаем прочность и получаем результат
        $durabilityResult = $this->toolManager->updateToolDurability($toolData);

        // Если прочность успешно уменьшена
        if ($durabilityResult) {
            // Увеличиваем счётчик использования инструмента
            if (!isset($this->usedToolsCount[$bestToolName])) {
                $this->usedToolsCount[$bestToolName] = 0;
            }
            $this->usedToolsCount[$bestToolName]++;

            return 1.0 + $bestBonus; // Инструмент найден и использован, возвращаем множитель
        }

        return 1.0; // Инструмент найден, но прочность не уменьшена (возможно, закончился)
    }

    /**
     * Функция, определяющая, какие уровни редкости доступны при данном уровне персонажа.
     */
    protected function getAllowedRarities(int $level): array
    {
        if ($level <= 1) {
            return [10];
        } elseif ($level <= 2) {
            return [9, 10];
        } elseif ($level <= 3) {
            return [8, 9, 10];
        } elseif ($level <= 4) {
            return [7, 8, 9, 10];
        } elseif ($level <= 5) {
            return [6, 7, 8, 9, 10];
        } elseif ($level <= 6) {
            return [5, 6, 7, 8, 9, 10];
        } elseif ($level <= 7) {
            return [4, 5, 6, 7, 8, 9, 10];
        } elseif ($level <= 8) {
            return [3, 4, 5, 6, 7, 8, 9, 10];
        } elseif ($level <= 9) {
            return [2, 3, 4, 5, 6, 7, 8, 9, 10];
        } else {
            // 10+ уровень => 1..10
            return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        }
    }

    /**
     * Таблица «базовое количество за 10 минут» для каждой редкости (1–10).
     */
    protected function getBaseQuantityByRarity(int $rarity): int
    {
        return match ($rarity) {
            10 => 60,
            9  => 58,
            8  => 50,
            7  => 38,
            6  => 25,
            5  => 18,
            4  => 10,
            3  => 5,
            2  => 3,
            1  => 2,
            default => 0,
        };
    }

    /**
     * Рассчитывает коэффициент здоровья/выносливости:
     * при Health=100 и Tired=100 => +25% к итогам,
     * при Health=1 и Tired=1 => -25% к итогам.
     */
    protected function getHealthTirednessFactor(array $character): float
    {
        $healthVal = ($character['health'] - 50) / 50.0;
        $tiredVal  = ($character['tired']  - 50) / 50.0;

        $sumHT = $healthVal + $tiredVal;
        $factor = 1 + (0.125 * $sumHT);

        if ($factor < 0.1) {
            $factor = 0.1;
        } elseif ($factor > 2.0) {
            $factor = 2.0;
        }

        return $factor;
    }

    /**
     * Применяет штраф «Засуха» (Dryness) к водным ресурсам (type содержит 'water', например).
     */
    protected function applyDrynessPenalty(array $foundResources, array $drynessEvent): array
    {
        $penalty = 1 - ($drynessEvent['effect_value'] / 100.0);
        foreach ($foundResources as &$res) {
            if (isset($res['type']) && str_contains($res['type'], 'water')) {
                $res['amount'] = (int) round($res['amount'] * $penalty);
                if ($res['amount'] < 1) {
                    $res['amount'] = 1;
                }
            }
        }
        unset($res);
        return $foundResources;
    }

    // -------------------------------------------------------------------------
    //                          ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // -------------------------------------------------------------------------

    /**
     * Проверка, активно ли событие (по name_english).
     */
    protected function isEventActive(string $eventNameEnglish): bool
    {
        $event = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if (!$event) {
            return false;
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $event['event_id'])
            ->where('status', 'active')
            ->first();

        return !empty($activeEvent);
    }

    protected function isDrynessEventActive(): bool
    {
        $drynessEvent = $this->eventModel->where('name_english', 'Dryness')->first();
        if ($drynessEvent) {
            $active = $this->activeEventModel
                ->where('event_id', $drynessEvent['event_id'])
                ->where('status', 'active')
                ->first();
            return !empty($active);
        }
        return false;
    }

    protected function getAvailableResources(array $character): array
    {
        $cell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$cell) {
            log_message('error', "Cell not found for character {$character['id']} (cell_number: {$character['cell_number']})");
            return [];
        }

        $biome = $this->biomeModel->find($cell['biome_id']);
        if (!$biome) {
            log_message('error', "Biome ID {$cell['biome_id']} not found for cell_number {$cell['cell_number']}");
            return [];
        }

        return $this->resourceModel
            ->like('biome_id', (string) $biome['id'], 'both')
            ->where('level_required <=', $character['level'])
            ->findAll();
    }

    protected function calculateSpentMinutes(string $startTime, string $endTime): int
    {
        $start = new DateTime($startTime);
        $end   = new DateTime($endTime);
        $diff  = $start->diff($end);

        $minutes = $diff->days * 24 * 60;
        $minutes += $diff->h * 60;
        $minutes += $diff->i;

        return $minutes;
    }

    protected function saveFoundResources(array $foundResources, array $character, array $task): void
    {
        foreach ($foundResources as $res) {
            $amount = (int) $res['amount'];
            if ($amount < 1) {
                continue;
            }

            $existingResource = $this->characterResourceModel->where([
                'id_characters' => $character['id'],
                'id_resources'  => $res['resource_id'],
            ])->first();

            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $amount;
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters'     => $character['id'],
                    'id_resources'      => $res['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity'          => $amount,
                ]);
            }
        }

        $this->updateCharacterStats($character, $foundResources, $task);
    }

    protected function updateCharacterStats(array $character, array $foundResources, array $task)
    {
        $expGain     = 0;
        $healthGain  = 0.0;
        $strength    = 0.0;
        $agility     = 0.0;
        $intellect   = 0.0;

        foreach ($foundResources as $res) {
            $strength   += 0.006;
            $agility    += 0.001;
            $intellect  += 0.001;
            $healthGain += 0.005;
        }

        $newHealth = min(100, $character['health'] + $healthGain);

        $updatedData = [
            'experience' => $character['experience'] + $expGain,
            'health'     => $newHealth,
            'strength'   => $character['strength'] + $strength,
            'agility'    => $character['agility'] + $agility,
            'intellect'  => $character['intellect'] + $intellect,
        ];

        $this->characterModel->update($character['id'], $updatedData);
    }

    protected function sendResourcesFoundReply(
        array $foundResources,
        array $character,
        int $spentMinutes,
        string $biomeName
    ): void {
        $userRow = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$userRow || empty($userRow['telegram_id'])) {
            log_message('error', "Telegram ID not found for character {$character['id']}");
            return;
        }
        $chatId = $userRow['telegram_id'];

        // Заголовок + потраченное время + биом
        $msg = "*Успешная добыча ресурсов!*\n";
        $msg .= "Время, затраченное на добычу: *{$spentMinutes}* мин.\n";
        $msg .= "Биом: *{$biomeName}*\n\n";

        // Выводим, какие инструменты были потрачены
//        if (!empty($this->usedToolsCount)) {
//            $msg .= "\n*Использованные инструменты:*\n";
//            foreach ($this->usedToolsCount as $toolName => $countUsed) {
//                // Проверяем, что имя инструмента не 'unknown_tool'
//                if ($toolName !== 'unknown_tool') {
//                    $msg .= "- {$toolName}: {$countUsed} раз(а)\n";
//                }
//            }
//        }

        // Список ресурсов
        if (empty($foundResources)) {
            $msg .= "_Не удалось найти ресурсы._\n";
        } else {
            $msg .= "*Найдены следующие ресурсы:*\n";
            foreach ($foundResources as $item) {
                $resourceData = $this->resourceModel->find($item['resource_id']);
                if (!$resourceData) {
                    continue;
                }
                $resName = $resourceData['name'] ?? "???";
                $rarity  = $resourceData['rarity'] ?? 0;
                $amount  = (int) $item['amount'];

                // Пример: "Песок: 105 шт. | редк. - 6"
                $msg .= "- *{$resName}*: {$amount} шт. | редк. — {$rarity}\n";
            }
        }

        // Выводим, какие инструменты были потрачены
        if (!empty($this->usedToolsCount)) {
            $msg .= "\n*Использованные инструменты:*\n";
            foreach ($this->usedToolsCount as $toolName => $countUsed) {
                $msg .= "- `{$toolName}`: {$countUsed} раз(а)\n";
            }
        }

        $msg .= "\n*Твои усилия были вознаграждены!*";

        // Кнопки-ответы
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События',   'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile(base_url('uploads/telegram/loot_resources_in_the_box.png')),
                'caption'    => $msg,
                'parse_mode' => 'Markdown',  // Обычная Markdown
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send gather result message: " . $e->getMessage());
        }
    }

}
