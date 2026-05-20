<?php

namespace App\Libraries;

use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\GameSettings\GameSettingsService;

class ToolManager
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    /**
     * S20 (v0.51.202): T3 «оживляемые» инструменты — resource-eligibility
     * (data shape) + GameSettings-ключ величины бонуса (admin-tunable balance).
     * name_eng-ключи совпадают с crafted_items.name_eng (с пробелами).
     * DiamondPickaxe тут НЕТ — он уже в legacy map (getToolsForResource).
     *
     * @var array<string,array{resources:list<string>,setting:string,default:float}>
     */
    private const T3_TOOLS = [
        'Sapper Shovel' => [
            'resources' => ['Глина', 'Песок', 'Ил', 'Травы', 'Мхи', 'Снег', 'Береговая растительность'],
            'setting'   => 'tools.sapper_shovel.gather_bonus',
            'default'   => 0.70,
        ],
        'Golden Hoe' => [
            'resources' => ['Овощи', 'Травы', 'Мхи', 'Фрукты'],
            'setting'   => 'tools.golden_hoe.gather_bonus',
            'default'   => 0.60,
        ],
    ];

    private ?GameSettingsService $gameSettings = null;

    /**
     * Мемоизация бонусов T3-инструментов на время жизни инстанса
     * (getToolsForResource зовётся per-resource×blocks за одну добычу).
     *
     * @var array<string,float>|null
     */
    private ?array $t3BonusCache = null;

    public function __construct()
    {
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function applyToolEffect($resource, $character)
    {
        $tools = $this->getToolsForResource($resource['name']);
        $maxMultiplier = 0;
        $selectedTool = null;

        // Находим инструмент с наивысшим показателем КПД
        foreach ($tools as $toolName => $increasePercent) {
            $toolData = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($toolName, $character['id']);

            if ($toolData && $increasePercent > $maxMultiplier) {
                $maxMultiplier = $increasePercent;
                $selectedTool = $toolData;
            }
        }

        // Если найден выбранный инструмент, применяем его эффект и обновляем прочность
        if ($selectedTool) {
            $toolMultiplier = 1 + $maxMultiplier; // увеличиваем множитель на основе процента увеличения
            $this->updateToolDurability($selectedTool);
        } else {
            // Если не найден инструмент, устанавливаем множитель на единицу
            $toolMultiplier = 1;
        }

        return $toolMultiplier;
    }

    public function getToolsForResource($resourceName)
    {
        $toolMapping = [
            'Древесина' => ['LumberjackAxe' => 0.30],
            'Смола деревьев' => ['LumberjackAxe' => 0.10],
            'Кора деревьев' => ['LumberjackAxe' => 0.20],
            'Кактус' => ['LumberjackAxe' => 0.10, 'FoldingKnife' => 0.14],
            'Лианы' => ['LumberjackAxe' => 0.15, 'FoldingKnife' => 0.30],
            'Камни' => ['StonePickaxe' => 0.30, 'IronPickaxe' => 0.56, 'DiamondPickaxe' => 1.8],
            'Минералы' => ['StonePickaxe' => 0.05, 'IronPickaxe' => 0.16, 'DiamondPickaxe' => 0.6],
            'Кристаллы' => ['StonePickaxe' => 0.05, 'IronPickaxe' => 0.16, 'DiamondPickaxe' => 0.6],
            'Глина' => ['StonePickaxe' => 0.15, 'IronShovel' => 0.23, 'IronPickaxe' => 0.36, 'DiamondPickaxe' => 2.2],
            'Базальт' => ['StonePickaxe' => 0.15, 'IronPickaxe' => 0.26, 'DiamondPickaxe' => 1.1],
            'Янтарь' => ['StonePickaxe' => 0.05, 'IronPickaxe' => 0.16, 'DiamondPickaxe' => 0.8],
            'Редкие руды' => ['StonePickaxe' => 0.03, 'IronPickaxe' => 0.06, 'DiamondPickaxe' => 0.2],
            'Обсидиан' => ['StonePickaxe' => 0.03, 'IronPickaxe' => 0.06, 'DiamondPickaxe' => 0.2],
            'Солнечные камни' => ['StonePickaxe' => 0.03, 'IronPickaxe' => 0.06, 'DiamondPickaxe' => 0.2],
            'Сера' => ['StonePickaxe' => 0.10, 'IronPickaxe' => 0.16, 'DiamondPickaxe' => 0.22],
            'Лед' => ['StonePickaxe' => 0.23, 'IronPickaxe' => 0.56, 'DiamondPickaxe' => 1.8],
            'Травы' => ['IronShovel' => 0.30, 'Hoe' => 0.09, 'FoldingKnife' => 0.14],
            'Ил' => ['IronShovel' => 0.10],
            'Береговая растительность' => ['IronShovel' => 0.17],
            'Мхи' => ['IronShovel' => 0.15, 'Hoe' => 0.09, 'FoldingKnife' => 0.14],
            'Песок' => ['IronShovel' => 0.22],
            'Снег' => ['IronShovel' => 0.08],
            'Рыба' => ['FishingRod' => 0.30],
            'Водоросли' => ['FishingRod' => 0.09, 'FoldingKnife' => 0.09],
            'Овощи' => ['Hoe' => 0.30],
            'Водные растения' => ['FoldingKnife' => 0.10],
            'Алоэ' => ['FoldingKnife' => 0.14],
            'Фрукты' => ['FoldingKnife' => 0.10],
        ];
        $map = $toolMapping[$resourceName] ?? [];

        // S20 (v0.51.202): overlay T3-инструментов с admin-tunable бонусом из
        // GameSettings (constitutional). Legacy map выше не трогаем; добавляем
        // T3-инструмент к ресурсу, если ресурс в его resource-set. pickBestTool
        // выберет инструмент с наибольшим бонусом из имеющихся у игрока.
        foreach (self::T3_TOOLS as $toolName => $cfg) {
            if (in_array($resourceName, $cfg['resources'], true)) {
                $bonus = $this->t3Bonus($toolName);
                if ($bonus > 0) {
                    $map[$toolName] = $bonus;
                }
            }
        }

        return $map;
    }

    /**
     * S20: бонус T3-инструмента из GameSettings (мемоизация на инстанс).
     */
    private function t3Bonus(string $toolName): float
    {
        if ($this->t3BonusCache === null) {
            $this->t3BonusCache = [];
            if ($this->gameSettings === null) {
                $this->gameSettings = new GameSettingsService();
            }
            foreach (self::T3_TOOLS as $name => $cfg) {
                $val = $this->gameSettings->get($cfg['setting'], $cfg['default']);
                $this->t3BonusCache[$name] = is_numeric($val) ? (float) $val : $cfg['default'];
            }
        }
        return $this->t3BonusCache[$toolName] ?? 0.0;
    }

    public function updateToolDurability($toolData)
    {
        $newDurability = $toolData['durability_count'] - 1;
        if ($newDurability > 0) {
            $this->craftedItemsLogModel->update($toolData['id'], ['durability_count' => $newDurability]);
            return true; // Успешно обновили прочность
        } else {
            // Если прочность инструмента достигает нуля
            if ($toolData['quantity'] > 1) {
                // Получаем полные данные инструмента для восстановления первоначальной прочности
                $initialToolData = $this->craftedItemsModel->find($toolData['crafted_item_id']);
                $this->craftedItemsLogModel->update($toolData['id'], [
                    'durability_count' => $initialToolData['durability_count'],
                    'quantity' => $toolData['quantity'] - 1
                ]);
                return true;
            } else {
                // Удаляем запись, если инструментов больше нет
                $this->craftedItemsLogModel->delete($toolData['id']);
                return false; // Инструмент закончился
            }
        }
    }
}
