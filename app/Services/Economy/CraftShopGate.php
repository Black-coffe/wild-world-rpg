<?php

declare(strict_types=1);

namespace App\Services\Economy;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * Условия входа в лавку «🛍️ Купить крафт» — одним местом на все четыре экрана.
 *
 * Гейта два: золото не ниже `economy.shop.buy_craft_min_gold` и построенный Склад.
 * До этого класса они жили копиями в `BuyCraftAction`, `BuyCraftItemListAction`,
 * `BuyCraftItemAction` и `BuyCraftConfirmAction`, причём порог золота читался из
 * живой настройки ТОЛЬКО во входном экране — три остальных держали константу 1000.
 * Опусти админ порог до 200 — игрок с 500 золота проходил первый экран и упирался
 * во второй, где ему называли цифру, которой в игре уже нет.
 *
 * Та же болезнь «два источника правды», что вскрылась на цене лавки
 * ({@see CraftTradeService}): пока условие дублируется по вызовам, совпадение экранов
 * держится на внимательности правящего, а не на построении.
 *
 * Склад — самый узкий гейт: на 2026-08-06 он есть у 18 персонажей из 675. Поэтому
 * отказ обязан объяснять, что строить, а не просто закрывать дверь.
 */
class CraftShopGate
{
    use GameSettingsReaderTrait;

    public const KEY_MIN_GOLD = 'economy.shop.buy_craft_min_gold';

    private const DEFAULT_MIN_GOLD = 1000;

    private BuildingModel $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;

    public function __construct(
        ?BuildingModel $buildingModel = null,
        ?CharacterBuildingModel $characterBuildingModel = null,
        ?\App\Services\GameSettings\GameSettingsService $settings = null
    ) {
        $this->buildingModel          = $buildingModel ?? new BuildingModel();
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();

        // Порог золота обязан проверяться тестом на ЖИВОМ значении, а не на дефолте:
        // ровно расхождение живой настройки с константой и было чинимым багом.
        if ($settings !== null) {
            $this->gsReader = $settings;
        }
    }

    /** Порог золота для входа в лавку — живая настройка, а не константа. */
    public function minGold(): int
    {
        return $this->gsInt(self::KEY_MIN_GOLD, self::DEFAULT_MIN_GOLD);
    }

    /**
     * Проверка обоих гейтов.
     *
     * @param array<string, mixed> $character строка персонажа
     *
     * @return array{reason: string, text: string}|null null — путь открыт
     */
    public function check(array $character): ?array
    {
        $goldRaw = $character['gold'] ?? 0;
        $gold    = is_numeric($goldRaw) ? (int) $goldRaw : 0;
        $minGold = $this->minGold();

        if ($gold < $minGold) {
            return [
                'reason' => 'min_gold_threshold',
                'text'   => "Для торговли необходимо иметь не менее {$minGold} золотых монет.\n\n"
                    . "_У тебя сейчас {$gold}. Продай торговцу крафт или сырьё — и возвращайся._",
            ];
        }

        $warehouse = $this->buildingModel->where('name_en', 'Warehouse')->first();
        if (! $warehouse) {
            return [
                'reason' => 'warehouse_definition_missing',
                'text'   => 'Ошибка: постройка "Склад" не найдена в базе данных.',
            ];
        }

        $warehouseId = is_array($warehouse) && is_numeric($warehouse['id'] ?? null) ? (int) $warehouse['id'] : 0;
        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        $built = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $warehouseId)
            ->first();

        if (! $built) {
            return [
                'reason' => 'no_warehouse',
                'text'   => "Для покупки крафтовых предметов нужен *Склад* — торговцу некуда сгружать товар.\n\n"
                    . "_Путь: нижнее меню «🏠 База» → «🏗 Строить» → «🏚️ Склад». Нужен разбитый лагерь и "
                    . "находиться надо на его клетке. Продавать торговцу можно и без Склада._",
            ];
        }

        return null;
    }
}
