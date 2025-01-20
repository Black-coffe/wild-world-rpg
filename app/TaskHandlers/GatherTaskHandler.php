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

    /**
     * Сохраняет, сколько инструментов мы использовали в рамках одного процесса сбора
     * (ключ: имя инструмента, значение: сколько раз применили).
     */
    protected array $usedToolsCount = [];

    private $telegram;

    public function __construct()
    {
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

        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            // При желании можно обработать или записать ошибку в лог.
        }
    }

    /**
     * Основная точка входа для обработки задачи сбора ресурсов.
     */
    public function handle($task)
    {
        $character = $this->characterModel->find($task['character_id']);
        if (!$character) {
            return;
        }

        $locustExodusActive = $this->isEventActive('LocustExodus');
        $locustExodusEffect = 0;
        if ($locustExodusActive) {
            $locustExodusEvent  = $this->eventModel->where('name_english', 'LocustExodus')->first();
            $locustExodusEffect = (float) $locustExodusEvent['effect_value'];
        }

        $resources    = $this->getAvailableResources($character);
        $spentMinutes = $this->calculateSpentMinutes($task['start_time'], $task['end_time']);

        $foundResources = $this->calculateFoundResources(
            resources: $resources,
            spentMinutes: $spentMinutes,
            character: $character,
            task: $task,
            locustExodusActive: $locustExodusActive,
            locustExodusEffect: $locustExodusEffect
        );

        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        $biome       = $currentCell ? $this->biomeModel->find($currentCell['biome_id']) : null;
        $biomeName   = $biome['name'] ?? '???';

        // Применяем возможные модификаторы биома
        $foundResources = $this->biomeResourceModifier->modifyResourcesByBiome($biome['id'] ?? null, $foundResources);

        // Сохраняем результаты
        $this->saveFoundResources($foundResources, $character, $task);
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Отправляем уведомление
        $this->sendResourcesFoundReply($foundResources, $character, $spentMinutes, $biomeName);
    }

    /**
     * Основной метод расчёта добытых ресурсов
     * (блочная логика по 10 минут + бонус от инструментов).
     */
    protected function calculateFoundResources(
        array $resources,
        int $spentMinutes,
        array $character,
        array $task,
        bool $locustExodusActive,
        float $locustExodusEffect
    ): array {
        $isFishStockActive       = $this->isEventActive('FishStock');
        $isExoticFloweringActive = $this->isEventActive('ExoticFlowering');
        $isBerryBoomActive       = $this->isEventActive('BerryBoom');
        $isDrynessActive         = $this->isDrynessEventActive();

        $fishStockEvent       = $isFishStockActive       ? $this->eventModel->where('name_english', 'FishStock')->first() : null;
        $exoticFloweringEvent = $isExoticFloweringActive ? $this->eventModel->where('name_english', 'ExoticFlowering')->first() : null;
        $berryBoomEvent       = $isBerryBoomActive       ? $this->eventModel->where('name_english', 'BerryBoom')->first() : null;
        $drynessEvent         = $isDrynessActive         ? $this->eventModel->where('name_english', 'Dryness')->first() : null;

        $allowedRarities = $this->getAllowedRarities($character['level']);

        $blocksCount = intdiv($spentMinutes, 10);
        $remainder   = $spentMinutes % 10;

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

        $foundAmounts = []; // resourceId => суммарное кол-во

        // Цикл по блокам
        for ($blockIndex = 0; $blockIndex < $blocksCount; $blockIndex++) {
            $toolsNeeded = [];
            $resourceBonuses = [];

            // Определяем, какие инструменты нужны
            foreach ($baseBlockResources as $resId => $arr) {
                $resourceName = $arr['resource']['name'];
                $toolsMapping = $this->toolManager->getToolsForResource($resourceName);

                if (empty($toolsMapping)) {
                    $resourceBonuses[$resId] = 0.0;
                    continue;
                }

                $bestBonus    = 0.0;
                $bestToolName = null;
                foreach ($toolsMapping as $toolName => $bonusValue) {
                    $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);
                    if (!$toolData) {
                        continue;
                    }
                    if ($bonusValue > $bestBonus) {
                        $bestBonus    = $bonusValue;
                        $bestToolName = $toolName;
                    }
                }

                if ($bestToolName) {
                    $resourceBonuses[$resId] = $bestBonus;
                    $toolsNeeded[$bestToolName][] = $resId;
                } else {
                    $resourceBonuses[$resId] = 0.0;
                }
            }

            // Списываем прочность
            foreach ($toolsNeeded as $toolName => $listOfResources) {
                $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);
                if (!$toolData) {
                    foreach ($listOfResources as $resId) {
                        $resourceBonuses[$resId] = 0.0;
                    }
                    continue;
                }

                $ok = $this->toolManager->updateToolDurability($toolData);
                if (!$ok) {
                    foreach ($listOfResources as $resId) {
                        $resourceBonuses[$resId] = 0.0;
                    }
                    continue;
                }

                if (!isset($this->usedToolsCount[$toolName])) {
                    $this->usedToolsCount[$toolName] = 0;
                }
                $this->usedToolsCount[$toolName]++;
            }

            // Подсчёт добычи
            foreach ($baseBlockResources as $resId => $arr) {
                $baseQty  = $arr['baseQty'];
                $bonusVal = $resourceBonuses[$resId] ?? 0.0;

                $resultQty = $baseQty;
                if ($bonusVal > 0) {
                    $resultQty *= (1 + $bonusVal);
                }

                if ($resultQty > 0) {
                    $foundAmounts[$resId] = ($foundAmounts[$resId] ?? 0) + $resultQty;
                }
            }
        }

        // Остаток (меньше 10 мин)
        if ($remainder > 0) {
            $leftFactor = $remainder / 10.0;
            foreach ($baseBlockResources as $resId => $arr) {
                $addQty = $arr['baseQty'] * $leftFactor;
                if ($addQty > 0) {
                    $foundAmounts[$resId] = ($foundAmounts[$resId] ?? 0) + $addQty;
                }
            }
        }

        // Применяем ±20% и прочие модификаторы (здоровье, события)
        $foundResources = [];
        foreach ($foundAmounts as $resId => $amountRaw) {
            $randFactor = rand(80, 120) / 100.0;
            $amt = $amountRaw * $randFactor;

            $resInfo = $this->resourceModel->find($resId);
            if (!$resInfo) {
                continue;
            }

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

            // Учет здоровья/усталости
            $htFactor = $this->getHealthTirednessFactor($character);
            $amt *= $htFactor;

            $finalAmt = (int) round($amt);
            if ($finalAmt < 1) {
                continue;
            }

            $foundResources[] = [
                'resource_id' => $resId,
                'amount'      => $finalAmt,
                'type'        => $resInfo['type'] ?? '',
            ];
        }

        // Проверка засухи
        if ($isDrynessActive && $drynessEvent) {
            $foundResources = $this->applyDrynessPenalty($foundResources, $drynessEvent);
        }

        return $foundResources;
    }

    protected function pickToolAndUpdate(array $resource, array $character): float
    {
        $tools = $this->toolManager->getToolsForResource($resource['name']);
        if (empty($tools)) {
            return 1.0;
        }

        $bestBonus    = 0.0;
        $bestToolName = null;

        foreach ($tools as $toolName => $bonus) {
            $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);
            if (!$toolData) {
                continue;
            }
            if ($bonus > $bestBonus) {
                $bestBonus    = $bonus;
                $bestToolName = $toolName;
            }
        }

        if (!$bestToolName) {
            return 1.0;
        }

        $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($bestToolName, $character['id']);
        if (!$toolData) {
            return 1.0;
        }

        $durabilityResult = $this->toolManager->updateToolDurability($toolData);
        if ($durabilityResult) {
            if (!isset($this->usedToolsCount[$bestToolName])) {
                $this->usedToolsCount[$bestToolName] = 0;
            }
            $this->usedToolsCount[$bestToolName]++;
            return 1.0 + $bestBonus;
        }

        return 1.0;
    }

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
            return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        }
    }

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
            return [];
        }

        $biome = $this->biomeModel->find($cell['biome_id']);
        if (!$biome) {
            return [];
        }

        return $this->resourceModel
            ->like('biome_id', (string)$biome['id'], 'both')
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

            $existingResource = $this->characterResourceModel
                ->where([
                    'id_characters' => $character['id'],
                    'id_resources'  => $res['resource_id'],
                ])
                ->first();

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

    /**
     * Отправляет сообщение (без картинки) об итогах сбора.
     */
    protected function sendResourcesFoundReply(
        array $foundResources,
        array $character,
        int $spentMinutes,
        string $biomeName
    ): void {
        $userRow = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$userRow || empty($userRow['telegram_id'])) {
            return;
        }
        $chatId = $userRow['telegram_id'];

        $msg = "<b>Успешная добыча ресурсов!</b>\n";
        $msg .= "Время, затраченное на добычу: <b>{$spentMinutes}</b> мин.\n";
        $msg .= "Биом: <b>" . htmlspecialchars($biomeName, ENT_QUOTES, 'UTF-8') . "</b>\n\n";

        $resourcesWithRarity = [];
        foreach ($foundResources as $item) {
            $resData = $this->resourceModel->find($item['resource_id']);
            if ($resData) {
                $resourcesWithRarity[] = [
                    'name'   => $resData['name'],
                    'amount' => $item['amount'],
                    'rarity' => $resData['rarity'],
                ];
            }
        }

        // Сортируем по убыванию редкости
        usort($resourcesWithRarity, function ($a, $b) {
            return $b['rarity'] - $a['rarity'];
        });

        if (empty($resourcesWithRarity)) {
            $msg .= "<i>Не удалось найти ресурсы.</i>\n";
        } else {
            $msg .= "<b>Найдены следующие ресурсы:</b>\n";
            foreach ($resourcesWithRarity as $resource) {
                $resourceName = htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8');
                $amount       = $resource['amount'];
                $rarity       = $resource['rarity'];
                $msg .= "➖ <b>{$resourceName}</b>: {$amount} шт. || редк. ➖ <b>{$rarity}</b>\n";
            }
        }

        if (!empty($this->usedToolsCount)) {
            $msg .= "\n<b>Использованные инструменты:</b>\n";
            foreach ($this->usedToolsCount as $toolNameEng => $countUsed) {
                $toolData = $this->craftedItemsModel->where('name_eng', $toolNameEng)->first();
                $toolNameRus = $toolData ? $toolData['name_rus'] : $toolNameEng;
                $toolNameEsc = htmlspecialchars($toolNameRus, ENT_QUOTES, 'UTF-8');
                $msg .= "- {$toolNameEsc}: {$countUsed} раз(а)\n";
            }
        }

        $msg .= "\n<b>Твои усилия были вознаграждены!</b>";

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
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            // При желании можно записать сообщение об ошибке в лог.
        }
    }
}
