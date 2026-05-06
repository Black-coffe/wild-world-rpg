<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemModel;
use App\Models\CraftedItemsLogModel;
use App\Repositories\CI4CharacterRepository;
use App\Repositories\Contracts\CharacterRepositoryInterface;
use Psr\Log\LoggerInterface;

class RewardService
{
    private LoggerInterface $logger;
    private CharacterModel $characterModel;
    private CharacterResourceModel $characterResourceModel;
    private ResourceModel $resourceModel;
    private CraftedItemModel $craftedItemModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private CharacterRepositoryInterface $characterRepo;

    /**
     * F2.6 wire-in: $characterRepo с дефолтом на CI4CharacterRepository.
     * Существующие caller'ы (`new RewardService($logger)`) продолжают
     * работать без изменений. Тесты могут подсунуть InMemory или mock.
     */
    public function __construct(
        LoggerInterface $logger,
        ?CharacterRepositoryInterface $characterRepo = null
    ) {
        $this->logger                  = $logger;
        $this->characterModel          = new CharacterModel();
        $this->characterResourceModel  = new CharacterResourceModel();
        $this->resourceModel           = new ResourceModel();
        $this->craftedItemModel        = new CraftedItemModel();
        $this->craftedItemsLogModel    = new CraftedItemsLogModel();
        $this->characterRepo           = $characterRepo ?? new CI4CharacterRepository();
    }

    /**
     * Выдаёт награды с учётом новых правил:
     * - сравниваем уровень winner и loser
     * - в зависимости от этого выдаём разные статы, золото, ресурсы и крафтовые предметы
     */
    public function grantRewards(BattleCharacter $winner, BattleCharacter $loser): array
    {
        // Проверяем, кто слабее по уровню:
        $npcStrongerOrEqual = ($loser->level >= $winner->level);

        // 1. Генерируем прибавку к статам и золоту
        if ($npcStrongerOrEqual) {
            // NPC сильнее или равен игроку
            // Опыт +0.01..0.10, Ловкость +0.01..0.10, Интеллект +0.01..0.10, Сила +0.01..0.15
            // Золото 1000..10000
            $expGain       = $this->randomFloat(0.01, 0.10);
            $agiGain       = $this->randomFloat(0.01, 0.10);
            $intGain       = $this->randomFloat(0.01, 0.10);
            $strGain       = $this->randomFloat(0.01, 0.15);
            $goldGained    = mt_rand(1000, 10000);

        } else {
            // NPC слабее
            // Только Сила: +0.01..0.05, Золото: 100..1000
            $expGain       = 0;  // Опыт не даём (по вашему описанию «больше ничего»)
            $agiGain       = 0;
            $intGain       = 0;
            $strGain       = $this->randomFloat(0.01, 0.05);
            $goldGained    = mt_rand(100, 1000);
        }

        // 2. Обновляем статы игрока.
        //    F2.6 wire-in: gold обновляется атомарно через CharacterRepository
        //    (raw SQL `gold = gold + ?`), исключает TOCTOU при двойных
        //    callback-кликах. Остальные статы (exp/agi/int/str) — через
        //    update(), пока read-modify-write; race-эффект на них меньше,
        //    мигрируются позже расширением Repository.
        $this->characterRepo->adjustGold($winner->id, (float) $goldGained);

        $newExp       = $winner->experience + $expGain;
        $newStrength  = $winner->strength + $strGain;
        $newAgility   = $winner->agility + $agiGain;
        $newIntellect = $winner->intellect + $intGain;

        $this->characterModel->update($winner->id, [
            'experience' => $newExp,
            'strength'   => $newStrength,
            'agility'    => $newAgility,
            'intellect'  => $newIntellect,
        ]);

        // 3. Выдаём ресурсы
        //    Если NPC был сильнее/равен => от 1..5 видов, rarity 2..6, по 1..5 штук
        //    Иначе => 1..2 видов, rarity 6..10, 1..2 штуки
        $resourcesGiven = [];
        if ($npcStrongerOrEqual) {
            $countResourceTypes = mt_rand(1, 5); // от 1 до 5 видов
            $resourcesGiven = $this->getRandomResources($countResourceTypes, 2, 6, 1, 5);
        } else {
            $countResourceTypes = mt_rand(1, 2); // от 1 до 2 видов
            $resourcesGiven = $this->getRandomResources($countResourceTypes, 6, 10, 1, 2);
        }

        // Сохраняем в character_resources
        foreach ($resourcesGiven as $res) {
            $this->characterResourceModel->addOrIncreaseResource(
                $winner->id,
                $res['id'],
                $res['quantity']
            );
        }

        // 4. Крафтовые предметы
        //    Если NPC сильнее/равен => даём 1..5 разных дорогих предметов, каждый по 1..5 шт.
        //    Если слабее => 50% шанс дать 1 «недорогой» предмет, 1 шт
        $craftedItemsGiven = [];
        if ($npcStrongerOrEqual) {
            $countItems = mt_rand(1, 5);
            $craftedItemsGiven = $this->getRandomCraftedItems($countItems, true); // «дорогие»
        } else {
            // 50% шанс
            if (mt_rand(1, 100) <= 50) {
                // Дадим 1 предмет
                $oneItem = $this->getRandomCraftedItems(1, false); // «недорогой»
                $craftedItemsGiven = $oneItem; // возможно массив на 1 элемент
            }
        }

        // Сохраняем предметы в crafted_items_log
        // v0.51.31 fix (Bug #9): UPSERT замість blind INSERT — інакше PvE reward
        // створює дубль stack для items які вже є у inventory. Reported у
        // Bugs-info: "появилось отдельное успокоительное" (346 ед. + 1 ед. окремо).
        foreach ($craftedItemsGiven as $ci) {
            $existing = $this->craftedItemsLogModel
                ->where('character_id', $winner->id)
                ->where('crafted_item_id', $ci['id'])
                ->first();
            if ($existing) {
                $this->craftedItemsLogModel->update($existing['id'], [
                    'quantity' => (int) $existing['quantity'] + (int) $ci['quantity'],
                ]);
            } else {
                $this->craftedItemsLogModel->insert([
                    'character_id'      => $winner->id,
                    'task_id'           => 1,
                    'crafted_item_id'   => $ci['id'],
                    'type'              => $ci['type'],
                    'direction_craft'   => $ci['direction_craft'],
                    'crafting_location' => 'all',
                    'durability_count'  => 100,
                    'quantity'          => $ci['quantity'],
                ]);
            }
        }

        // 5. Формируем итог для вывода
        //    (учтём, что опыт возвращаем как число, золото и т.д.)
        $firstResourceName  = (!empty($resourcesGiven)) ? $resourcesGiven[0]['name'] : null;
        $firstCraftItemName = (!empty($craftedItemsGiven)) ? $craftedItemsGiven[0]['name_rus'] : null;

        return [
            // Новые показатели (сколько фактически добавилось)
            'exp'         => $expGain,
            'gold'        => $goldGained,
            'strength'    => $strGain,
            'agility'     => $agiGain,
            'intellect'   => $intGain,

            // Для простоты вернём только «первый» ресурс/предмет в награде,
            // а при желании можно вернуть массив
            'resource'    => $firstResourceName,
            'craftedItem' => $firstCraftItemName,
        ];
    }

    /**
     * Генерирует float число в диапазоне [min, max], округлённое до 2 знаков.
     */
    private function randomFloat(float $min, float $max): float
    {
        $val = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        return round($val, 2);
    }

    /**
     * Получает N случайных ресурсов из БД с учётом заданной редкости (минимум..максимум),
     * и случайным количеством (quantityMin..quantityMax).
     */
    private function getRandomResources(
        int $count,
        int $rarityMin,
        int $rarityMax,
        int $quantityMin,
        int $quantityMax
    ): array {
        $results = [];

        // Получаем список ресурсов, подходящих по редкости
        $all = $this->resourceModel
            ->where('rarity >=', $rarityMin)
            ->where('rarity <=', $rarityMax)
            ->findAll();

        // Если нет подходящих, возвращаем пустой массив
        if (empty($all)) {
            return [];
        }

        // Перемешиваем
        shuffle($all);

        // Берём первые $count (или меньше, если нет столько)
        $selected = array_slice($all, 0, $count);

        // Добавляем рандомное количество к каждому
        foreach ($selected as $res) {
            $res['quantity'] = mt_rand($quantityMin, $quantityMax);
            $results[] = $res;
        }

        return $results;
    }

    /**
     * Получает N случайных крафтовых предметов — «дорогих» или «дешёвых».
     * По-простому считаем, что в таблице `crafted_items` есть поле `price`.
     * - expensive=TRUE => where('price > 5000')
     * - otherwise => where('price <= 5000')
     */
    private function getRandomCraftedItems(int $count, bool $expensive): array
    {
        $query = $this->craftedItemModel;
        if ($expensive) {
            // «Максимально дорогие»
            $query = $query->where('price >=', 5000); // Порог подбирайте сами
        } else {
            // «Не очень дорогие»
            $query = $query->where('price <', 5000);
        }

        $all = $query->findAll();
        if (empty($all)) {
            return [];
        }

        shuffle($all);

        // Возьмём до $count штук
        $selected = array_slice($all, 0, $count);

        // Для сильного NPC сказано: каждый предмет 1..5 шт.
        // Для слабого NPC: ровно 1 шт (но можно при желании варьировать).
        $items = [];
        foreach ($selected as $item) {
            // Если «expensive» (сильный NPC) => quantity 1..5
            // Иначе «дешёвый» => quantity = 1
            $quantity = $expensive ? mt_rand(1, 5) : 1;

            $item['quantity'] = $quantity;
            $items[] = $item;
        }

        return $items;
    }

}
