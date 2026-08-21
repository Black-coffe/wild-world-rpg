<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Services\Economy\CraftTradeService;
use App\Services\Economy\TradePricingService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\ResourcePoolService;
use App\Services\Player\Trade\ResourceTradeService;
use App\Services\Player\WarehouseSellBonusService;

/**
 * Story `craft-shortfall-buy-06` — единственный источник чисел докупки недостающего
 * сырья у торговца при нехватке на крафт. Экран (story 08) и сделка (story 09) обязаны
 * звать `quote()`, а не считать цену заново: контракт зафиксирован в
 * `docs/specs/craft-shortfall-buy/plan.md` §Contracts.
 *
 * Формула: `base = Σ gap_i × unitPrice_i` (только покупаемые позиции с недостачей),
 * `full = Σ need_i × unitPrice_i` (ВСЕ сырьевые позиции рецепта, покупаемые и нет —
 * это цена рецепта целиком, а не только недостачи), `share = base/full` (в деньгах,
 * без потолка), `markup = base_markup_pct + slope_markup_pct × share`,
 * `total = max(base + min_markup_gold, ceil(base × (1 + markup)))`. `markupPct` в DTO —
 * посчитан ОБРАТНО из `total`, чтобы экран не врал на округлении (см. `CraftShortfallQuote`).
 *
 * Первая версия докупает только сырьё (ADR-158: 137 отказов из 144 — по сырью, 8 — по
 * компоненту). Крафтовый компонент из `recipe['crafted_items']` попадает в `lines` с
 * `blockReason = not_on_sale` и НЕ входит ни в `base`, ни в `full` — для него неизвестна
 * цена (запрет изобретать формулу цены крафта), но игрок обязан увидеть, что докупка
 * заблокирована именно на нём.
 *
 * Цена сырья — исключительно `ResourceTradeService::unitPrice($resource, false)`, то же
 * `buy_price`, что видит магазин: персональной цены сырья (кармы/склада) не существует.
 *
 * Гейт рентабельности (по умолчанию выключен, `craft.shortfall_buy.profit_gate_enabled`)
 * сравнивает `total` с реальной выручкой персонажа за собранный предмет: `crafted_items.price`
 * рецепта (по `item_name_eng`), пропущенный через ЖИВУЮ формулу прилавка
 * `CraftTradeService::sellUnitPrice()` (карма персонажа + складской бонус, ADR-157/ADR-085) —
 * ту же, что видит экран продажи, а не выдуманный множитель. Если цену предмета определить
 * нельзя (нет `item_name_eng`, рецепт не найден в `crafted_items`, `price` не число) — гейт
 * не молчит и не пропускает сделку: он отказывает (`profit_gate`), потому что «включён» обязан
 * значить «умеет сработать», а не «сработает, только если повезёт с данными».
 */
class CraftShortfallBuyService
{
    private const KEY_ENABLED             = 'craft.shortfall_buy.enabled';
    private const KEY_BASE_MARKUP_PCT     = 'craft.shortfall_buy.base_markup_pct';
    private const KEY_SLOPE_MARKUP_PCT    = 'craft.shortfall_buy.slope_markup_pct';
    private const KEY_MIN_MARKUP_GOLD     = 'craft.shortfall_buy.min_markup_gold';
    private const KEY_PROFIT_GATE_ENABLED = 'craft.shortfall_buy.profit_gate_enabled';

    public const REASON_NOT_TRADEABLE  = 'not_tradeable';
    public const REASON_LEVEL_REQUIRED = 'level_required';
    public const REASON_NOT_ON_SALE    = 'not_on_sale';
    public const REASON_PROFIT_GATE    = 'profit_gate';
    public const REASON_KILLSWITCH_OFF = 'killswitch_off';
    public const REASON_MIN_GOLD       = 'min_gold';

    private ResourceModel          $resources;
    private ResourcePoolService    $pool;
    private ResourceTradeService   $trade;
    private GameSettingsService    $settings;
    private CraftedItemsModel      $craftedItems;
    private CraftedItemsLogModel   $craftedItemsLog;
    private CraftTradeService      $craftTrade;

    public function __construct(
        ?ResourceModel $resources = null,
        ?ResourcePoolService $pool = null,
        ?ResourceTradeService $trade = null,
        ?GameSettingsService $settings = null,
        ?CraftedItemsModel $craftedItems = null,
        ?CraftedItemsLogModel $craftedItemsLog = null,
        ?CraftTradeService $craftTrade = null
    ) {
        $this->resources       = $resources ?? new ResourceModel();
        $this->pool            = $pool ?? new ResourcePoolService();
        $this->trade           = $trade ?? new ResourceTradeService();
        $this->settings        = $settings ?? new GameSettingsService();
        $this->craftedItems    = $craftedItems ?? new CraftedItemsModel();
        $this->craftedItemsLog = $craftedItemsLog ?? new CraftedItemsLogModel();
        // Живая формула прилавка (не своя копия): гейт видит ту же выручку, что и экран продажи.
        $this->craftTrade = $craftTrade ?? new CraftTradeService(
            new TradePricingService($this->settings),
            new WarehouseSellBonusService($this->settings)
        );
    }

    /**
     * @param array<string,mixed>|CharacterEntity $character
     * @param array{resources?:array<string,int>,crafted_items?:array<string,int>,item_name_eng?:string} $recipe
     */
    public function quote(CharacterEntity|array $character, array $recipe, int $quantity): CraftShortfallQuote
    {
        $quantity = max(1, $quantity);
        $gold     = $this->intField($character, 'gold');

        if (! $this->gsBool(self::KEY_ENABLED, false)) {
            return $this->refusedQuote($gold, self::REASON_KILLSWITCH_OFF);
        }

        $characterId = $this->intField($character, 'id');
        $level       = $this->intField($character, 'level');

        [$lines, $base, $full, $blockingReason] = $this->buildLines(
            $characterId,
            $level,
            $quantity,
            $this->stringIntMap($recipe['resources'] ?? []),
            $this->stringIntMap($recipe['crafted_items'] ?? [])
        );

        $baseMarkupPct  = $this->gsInt(self::KEY_BASE_MARKUP_PCT, 15);
        $slopeMarkupPct = $this->gsInt(self::KEY_SLOPE_MARKUP_PCT, 35);
        $minMarkupGold  = $this->gsInt(self::KEY_MIN_MARKUP_GOLD, 1);

        $share          = $full > 0.0 ? $base / $full : 0.0;
        $markupFraction = ($baseMarkupPct + $slopeMarkupPct * $share) / 100;

        $total = $base > 0.0
            ? (int) max($base + $minMarkupGold, ceil($base * (1 + $markupFraction)))
            : 0;

        $markupPct = $base > 0.0 ? (int) round((($total - $base) / $base) * 100) : 0;

        $refusal = $blockingReason;

        if ($refusal === null && $total > 0 && $this->gsBool(self::KEY_PROFIT_GATE_ENABLED, false)) {
            $revenue = $this->recipeRevenue($character, $recipe, $characterId, $quantity);
            // `null` — цену предмета определить не удалось: гейт отказывает, а не пропускает
            // сделку молча (фича «выключена по умолчанию» обязана быть исправна при включении).
            if ($revenue === null || $total > $revenue) {
                $refusal = self::REASON_PROFIT_GATE;
            }
        }

        if ($refusal === null && $total > 0 && $gold < $total) {
            $refusal = self::REASON_MIN_GOLD;
        }

        return new CraftShortfallQuote(
            lines: $lines,
            baseCost: $base,
            fullCost: $full,
            share: $share,
            markupPct: $markupPct,
            total: $total,
            goldAfter: $gold - $total,
            available: $refusal === null,
            refusal: $refusal
        );
    }

    /**
     * Один проход по сырью рецепта + один по крафтовым компонентам. Компоненты не
     * ценятся (нет формулы цены крафта) и никогда не покупаемы — они лишь блокируют
     * докупку, если их не хватает.
     *
     * @param array<string,int> $resourcesReq имя ресурса → нужно на 1 шт.
     * @param array<string,int> $componentsReq name_eng компонента → нужно на 1 шт.
     * @return array{0:list<array{resourceId:int,name:string,need:int,have:int,gap:int,unitPrice:float,lineTotal:float,buyable:bool,blockReason:string|null}>,1:float,2:float,3:string|null}
     */
    private function buildLines(int $characterId, int $level, int $quantity, array $resourcesReq, array $componentsReq): array
    {
        $lines          = [];
        $base           = 0.0;
        $full           = 0.0;
        $blockingReason = null;

        foreach ($resourcesReq as $name => $perUnit) {
            $need      = $perUnit * $quantity;
            $resource  = $this->resources->getResourceByName($name);
            $have      = $this->pool->availableByName($characterId, $name);
            $gap       = max(0, $need - $have);
            $unitPrice = $resource !== null ? $this->trade->unitPrice($resource, false) : 0.0;

            // `full` считает ВСЮ стоимость рецепта — и то, что уже есть у игрока,
            // потому что это цена рецепта целиком, а не только недостачи.
            $full += $need * $unitPrice;

            if ($gap <= 0) {
                continue; // добирать нечего — в `lines` эта позиция не идёт
            }

            [$buyable, $reason]  = $this->resourceGate($resource, $level);
            $resourceIdRaw       = $resource['id'] ?? null;
            $resourceId          = is_numeric($resourceIdRaw) ? (int) $resourceIdRaw : 0;
            $lineTotal           = $gap * $unitPrice;

            if ($buyable) {
                $base += $lineTotal;
            } else {
                $blockingReason ??= $reason;
            }

            $lines[] = [
                'resourceId'  => $resourceId,
                'name'        => $name,
                'need'        => $need,
                'have'        => $have,
                'gap'         => $gap,
                'unitPrice'   => $unitPrice,
                'lineTotal'   => $lineTotal,
                'buyable'     => $buyable,
                'blockReason' => $reason,
            ];
        }

        foreach ($componentsReq as $itemEng => $perUnit) {
            $need = $perUnit * $quantity;
            $have = $this->craftedHave($characterId, $itemEng);
            $gap  = max(0, $need - $have);
            if ($gap <= 0) {
                continue;
            }

            $blockingReason ??= self::REASON_NOT_ON_SALE;

            $lines[] = [
                'resourceId'  => 0,
                'name'        => $this->craftedName($itemEng),
                'need'        => $need,
                'have'        => $have,
                'gap'         => $gap,
                'unitPrice'   => 0.0,
                'lineTotal'   => 0.0,
                'buyable'     => false,
                'blockReason' => self::REASON_NOT_ON_SALE,
            ];
        }

        return [$lines, $base, $full, $blockingReason];
    }

    /**
     * @param array<string,mixed>|\App\Entities\ResourceEntity|null $resource
     * @return array{0:bool,1:string|null}
     */
    private function resourceGate(array|\App\Entities\ResourceEntity|null $resource, int $level): array
    {
        if ($resource === null) {
            return [false, self::REASON_NOT_TRADEABLE];
        }
        if (! ResourceTradeService::resourceIsTradeable($resource)) {
            return [false, self::REASON_NOT_TRADEABLE];
        }

        $needLevelRaw = $resource['level_required'] ?? 0;
        $needLevel    = is_numeric($needLevelRaw) ? (int) $needLevelRaw : 0;
        if ($needLevel > 0 && $level < $needLevel) {
            return [false, self::REASON_LEVEL_REQUIRED];
        }

        return [true, null];
    }

    /**
     * Реальная выручка персонажа за собранный предмет — то, с чем гейт сравнивает `total`.
     * `null` означает «цену определить не удалось» и трактуется вызывающим кодом как отказ
     * (fail-closed), а не как «пропустить проверку».
     *
     * @param array<string,mixed>|CharacterEntity $character
     * @param array<string,mixed> $recipe
     */
    private function recipeRevenue(array|CharacterEntity $character, array $recipe, int $characterId, int $quantity): ?float
    {
        $nameEngRaw = $recipe['item_name_eng'] ?? null;
        if (! is_string($nameEngRaw) || $nameEngRaw === '') {
            return null;
        }

        $row = $this->craftedRow($nameEngRaw);
        if ($row === null) {
            return null;
        }

        $priceRaw = $row['price'] ?? null;
        if (! is_numeric($priceRaw)) {
            return null;
        }

        $karma           = $this->craftTrade->karmaOf($character);
        $warehouseMult   = $this->craftTrade->warehouseMultiplier($characterId);
        $unitRevenue     = $this->craftTrade->sellUnitPrice((float) $priceRaw, $karma, $warehouseMult);

        return $unitRevenue * $quantity;
    }

    /** @return array<string,mixed>|null */
    private function craftedRow(string $itemEng): ?array
    {
        $item = $this->craftedItems->getRowByName($itemEng);
        if (! is_array($item)) {
            return null;
        }

        // `getRowByName()` не декларирует тип возврата (легаси модель), поэтому PHPStan
        // видит ключи как `array-key`, а не `string` — сужаем явным фильтром, не кастом.
        $row = [];
        foreach ($item as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    private function craftedHave(int $characterId, string $itemEng): int
    {
        $item = $this->craftedRow($itemEng);
        if ($item === null) {
            return 0;
        }
        $itemIdRaw = $item['id'] ?? null;
        $itemId    = is_numeric($itemIdRaw) ? (int) $itemIdRaw : 0;
        if ($itemId <= 0) {
            return 0;
        }

        $log     = $this->craftedItemsLog->getItemByCraftedItemIdAndCharacterId($itemId, $characterId);
        $haveRaw = is_array($log) ? ($log['quantity'] ?? 0) : 0;

        return is_numeric($haveRaw) ? (int) $haveRaw : 0;
    }

    private function craftedName(string $itemEng): string
    {
        $item    = $this->craftedRow($itemEng);
        $nameRaw = $item['name_rus'] ?? null;

        return is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : $itemEng;
    }

    private function refusedQuote(int $gold, string $reason): CraftShortfallQuote
    {
        return new CraftShortfallQuote(
            lines: [],
            baseCost: 0.0,
            fullCost: 0.0,
            share: 0.0,
            markupPct: 0,
            total: 0,
            goldAfter: $gold,
            available: false,
            refusal: $reason
        );
    }

    /**
     * @param array<string,mixed>|CharacterEntity $character
     */
    private function intField(array|CharacterEntity $character, string $key): int
    {
        $raw = $character[$key] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** @return array<string,int> */
    private function stringIntMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $qty) {
            if (! is_string($name) || ! is_numeric($qty) || (int) $qty <= 0) {
                continue;
            }
            $out[$name] = (int) $qty;
        }

        return $out;
    }

    private function gsBool(string $key, bool $default): bool
    {
        $raw = $this->settings->get($key, $default);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? ((int) $raw === 1) : $default;
    }

    private function gsInt(string $key, int $default): int
    {
        $raw = $this->settings->get($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }
}
