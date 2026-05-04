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
use App\Services\Player\Gather\GatherEventModifierService;
use App\Services\Player\Gather\GatherFormulaService;
use App\Services\Player\Gather\ToolDurabilityProcessor;

class GatherTaskHandler extends Controller
{
    private GatherFormulaService $formulaService;
    private ToolDurabilityProcessor $toolDurability;
    private GatherEventModifierService $eventService;

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
        // F2.7 first slice: чистые формулы (rarities/baseQty/healthFactor/spentMinutes)
        // вынесены в GatherFormulaService.
        $this->formulaService         = new GatherFormulaService();
        // F2.7b: фикс N+1 на инструментах. Один batch + cache вместо
        // ~920 SQL/добычу.
        $this->toolDurability         = new ToolDurabilityProcessor(
            $this->craftedItemsLogModel,
            $this->craftedItemsModel,
            $this->toolManager
        );
        // F2.7c: батч событий. ~12 SQL → 2 SQL на handle.
        $this->eventService           = new GatherEventModifierService(
            $this->eventModel,
            $this->activeEventModel
        );

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

        // F2.7c: один batch на все 5 relevant событий вместо ~12 индивидуальных SQL.
        $loadedEvents = $this->eventService->loadActiveEvents();

        $resources    = $this->getAvailableResources($character);
        $spentMinutes = $this->calculateSpentMinutes($task['start_time'], $task['end_time']);

        $foundResources = $this->calculateFoundResources(
            resources: $resources,
            spentMinutes: $spentMinutes,
            character: $character,
            task: $task,
            loadedEvents: $loadedEvents
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
        array $loadedEvents
    ): array {
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

        // F2.7b: один batch — все инструменты персонажа, релевантные хотя бы
        // одному ресурсу. Заменяет ~920 SQL на ~2 + рефреши после расхода.
        $resourceNames = [];
        foreach ($baseBlockResources as $arr) {
            $resourceNames[] = $arr['resource']['name'];
        }
        $availableTools = $this->toolDurability->loadAvailableTools(
            (int) $character['id'],
            $resourceNames
        );

        $foundAmounts = []; // resourceId => суммарное кол-во

        // Цикл по блокам
        for ($blockIndex = 0; $blockIndex < $blocksCount; $blockIndex++) {
            $toolsNeeded = [];
            $resourceBonuses = [];

            // Определяем лучший инструмент per resource из cached map.
            foreach ($baseBlockResources as $resId => $arr) {
                $resourceName = $arr['resource']['name'];
                $best = $this->toolDurability->pickBestTool($resourceName, $availableTools);

                if ($best === null) {
                    $resourceBonuses[$resId] = 0.0;
                    continue;
                }

                $resourceBonuses[$resId]      = $best['bonus'];
                $toolsNeeded[$best['name']][] = $resId;
            }

            // Списываем прочность; cache самообновляется.
            foreach ($toolsNeeded as $toolName => $listOfResources) {
                $ok = $this->toolDurability->consumeAndRefresh(
                    $toolName,
                    $availableTools,
                    (int) $character['id']
                );
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

            // F2.7c: per-resource модификаторы (FishStock/ExoticFlowering/
            // BerryBoom/LocustExodus) консолидированы в eventService.
            $amt = $this->eventService->applyResourceModifiers(
                $amt,
                (string) ($resInfo['name'] ?? ''),
                $loadedEvents
            );

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

        // F2.7c: проверка засухи делегирована eventService.
        $foundResources = $this->eventService->applyDrynessPenalty($foundResources, $loadedEvents);

        return $foundResources;
    }

    // F2.7 first slice: getAllowedRarities / getBaseQuantityByRarity /
    // getHealthTirednessFactor вынесены в App\Services\Player\Gather\GatherFormulaService.
    // Делегирующие методы оставлены для backward-compat внутреннего вызова
    // calculateFoundResources в этом классе.
    protected function getAllowedRarities(int $level): array
    {
        return $this->formulaService->getAllowedRarities($level);
    }

    protected function getBaseQuantityByRarity(int $rarity): int
    {
        return $this->formulaService->getBaseQuantityByRarity($rarity);
    }

    protected function getHealthTirednessFactor(array $character): float
    {
        return $this->formulaService->getHealthTirednessFactor($character);
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
        // F2.7 first slice: вынесено в GatherFormulaService.
        return $this->formulaService->calculateSpentMinutes($startTime, $endTime);
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
            $strength   += 0.0006;
            $agility    += 0.0001;
            $intellect  += 0.0001;
            $healthGain += 0.0005;
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
            log_message('error', "sendResourcesFoundReply: No telegram_id for character_id={$character['id']}");
            return;
        }
        $chatId = $userRow['telegram_id'];

        $msg = "<b>Успешная добыча ресурсов!</b>\n";
        $msg .= "Время, затраченное на добычу: <b>{$spentMinutes}</b> мин.\n";
        $msg .= "Биом: <b>" . htmlspecialchars($biomeName, ENT_QUOTES, 'UTF-8') . "</b>\n\n";

        $resourcesWithRarity = [];
        foreach ($foundResources as $item) {
            $resourceData = $this->resourceModel->find($item['resource_id']);
            if ($resourceData) {
                $resourcesWithRarity[] = [
                    'name'   => $resourceData['name'],
                    'amount' => $item['amount'],
                    'rarity' => $resourceData['rarity'],
                ];
            }
        }

        // Сортируем по убыванию редкости
        usort($resourcesWithRarity, function ($a, $b) {
            return $b['rarity'] - $a['rarity'];
        });

        // Карта эмодзи для редкости
        $rarityEmojis = [
            1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣',
            6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟'
        ];

        if (empty($resourcesWithRarity)) {
            $msg .= "<i>Не удалось найти ресурсы.</i>\n";
        } else {
            $msg .= "<b>Найдены следующие ресурсы:</b>\n";

            // Группируем ресурсы по редкости
            $groupedResources = [];
            foreach ($resourcesWithRarity as $resource) {
                $rarity = $resource['rarity'];
                if (!isset($groupedResources[$rarity])) {
                    $groupedResources[$rarity] = [];
                }
                $groupedResources[$rarity][] = $resource;
            }

            // Выводим ресурсы, сгруппированные по редкости
            foreach ($groupedResources as $rarity => $resources) {
                $rarityEmoji = $rarityEmojis[$rarity] ?? $rarity;
                $msg .= "\n<b>{$rarityEmoji} Редкость...</b>\n";
                foreach ($resources as $resource) {
                    $resourceName = htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8');
                    $amount       = $resource['amount'];
                    $msg .= "— <b>{$resourceName}</b>: {$amount} шт.\n";
                }
            }
        }

        // Инструменты (с переводом на русский)
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
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile(base_url('uploads/telegram/loot_resources_in_the_box.png')),
                'caption'    => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Longman\TelegramBot\Exception\TelegramException $e) {
            log_message('error', "Failed to send gather result message to chat_id={$chatId}: " . $e->getMessage());
        }
    }

}
