<?php

declare(strict_types=1);

namespace App\Services\Economy;

use App\Services\Player\WarehouseSellBonusService;

/**
 * Цены NPC-торговца на КРАФТОВЫЕ предметы — обе стороны прилавка, одна формула на все экраны.
 *
 * Повод (аудит 2026-08-06, хвост ADR-157): экраны лавки печатали игроку сырое число из
 * БД, а сделка считала по {@see TradePricingService}. Расхождение шло в обе стороны и
 * всегда не в пользу игрока:
 *
 *  - **Продажа.** Список категории и карточка количества показывали `crafted_items.price`,
 *    а {@see \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftConfirmAction} платил
 *    `base × карма × складской бонус` с потолком множителя 1.0 и инвариантом спреда сверху,
 *    то есть **всегда меньше показанного**, при плохой карме — вдвое.
 *  - **Покупка.** Список и карточка показывали `sales.price`, а
 *    {@see \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftConfirmAction} списывал
 *    `base × buyMultiplier`, у которого **пол 1.25**: игрок платил минимум +25% к обещанному,
 *    при низкой карме — втрое.
 *
 * Путь экипировки (ADR-165) так не делает: {@see GearSaleService::unitPrice()} считает
 * реальную цену ДО показа, и в `SellCraftItemListAction::handleGear()` про это стоит
 * прямой комментарий — «игрок не должен узнавать реальную сумму только на экране
 * подтверждения». Этот сервис приводит крафт к тому же поведению.
 *
 * Почему отдельный класс, а не вызов {@see TradePricingService} из каждого action'а —
 * урок {@see \App\Services\Player\Craft\PortableTeleportRecipe}: пока формула
 * дублируется по вызовам, совпадение экрана и сделки держится на внимательности.
 * Здесь оно по построению: пять экранов лавки спрашивают цену в одном месте.
 *
 * Отличие от {@see GearSaleService}: доли базовой цены нет. У экипировки `price` —
 * ценник НОВОЙ вещи, и выкуп по нему сделал бы крафт источником золота, поэтому там 0.25.
 * У крафта `price` уже и есть цена прилавка: инвариант «выручка ≤ стоимости входа
 * рецепта» держится подбором самого `price` (миграции `FixTeleportItemsVendorPriceExploit`,
 * `FixCraftVendorPriceExploitWorkbenchesRobotsDrones`).
 */
final class CraftTradeService
{
    private TradePricingService $pricing;
    private WarehouseSellBonusService $warehouse;

    public function __construct(
        ?TradePricingService $pricing = null,
        ?WarehouseSellBonusService $warehouse = null,
    ) {
        $this->pricing   = $pricing ?? new TradePricingService();
        $this->warehouse = $warehouse ?? new WarehouseSellBonusService();
    }

    /**
     * Сколько торговец ПЛАТИТ за штуку: карма (ADR-157) + внешние бонусы,
     * инвариант спреда сверху.
     *
     * `$extraMultiplier` — складской бонус ADR-085. Передаётся аргументом, а не
     * читается внутри, чтобы сделка могла посчитать цену внутри уже открытой
     * транзакции, не трогая БД лишний раз.
     */
    public function sellUnitPrice(float $basePrice, float $karma, float $extraMultiplier = 1.0): float
    {
        return $this->pricing->sellUnitPrice(max(0.0, $basePrice), $karma, $extraMultiplier);
    }

    /** Сколько торговец БЕРЁТ за штуку (пол множителя 1.25 — круг «купил → продал» убыточен). */
    public function buyUnitPrice(float $basePrice, float $karma): float
    {
        return $this->pricing->buyUnitPrice(max(0.0, $basePrice), $karma);
    }

    /**
     * Готовые к показу целые числа монет.
     *
     * Экраны показывают округлённое, сделка считает по float и округляет итог — так же,
     * как на пути экипировки: расхождение не превышает монеты.
     */
    public function displaySellPrice(float $basePrice, float $karma, float $extraMultiplier = 1.0): int
    {
        return (int) round($this->sellUnitPrice($basePrice, $karma, $extraMultiplier));
    }

    public function displayBuyPrice(float $basePrice, float $karma): int
    {
        return (int) round($this->buyUnitPrice($basePrice, $karma));
    }

    /** Складской множитель персонажа (1.0 без Склада или при выключенном бонусе). */
    public function warehouseMultiplier(int $characterId): float
    {
        return $this->warehouse->sellPriceMultiplier($characterId);
    }

    /**
     * Строка под ценами продажи — почему число именно такое.
     *
     * Складской бонус упоминается только когда он реально включён: killswitch
     * `economy.sell.warehouse_bonus.enabled` админ может выключить, и тогда обещание
     * «подрастает со Складом» стало бы враньём на экране (урок
     * `feedback_seed_default_trap_verify_prod_settings`).
     */
    public function sellPriceHint(float $warehouseMultiplier): string
    {
        if ($warehouseMultiplier > 1.0) {
            return '_Цена — то, что торговец даёт сейчас: она зависит от твоей торговой '
                . 'репутации, и Склад на базе уже добавляет свою долю._';
        }

        if ($this->warehouse->isEnabled()) {
            return '_Цена — то, что торговец даёт сейчас: она зависит от твоей торговой '
                . 'репутации и подрастает, если на базе стоит Склад._';
        }

        return '_Цена — то, что торговец даёт сейчас: она зависит от твоей торговой репутации._';
    }

    /** Строка под ценами покупки. */
    public function buyPriceHint(): string
    {
        return '_Цена — то, что торговец просит с тебя сейчас: он всегда берёт с наценкой, '
            . 'и чем хуже твоя торговая репутация, тем она больше._';
    }

    /**
     * Карма персонажа из массива/Entity в пригодный для формулы float.
     *
     * Живёт здесь, потому что нужна всем экранам лавки: `getUserAndCharacter()` отдаёт
     * Entity, поле приходит строкой из БД, а `(float) $mixed` не проходит phpstan L9
     * (урок `feedback_phpstan_no_mixed_to_int_cast`).
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    public function karmaOf(array|\App\Entities\CharacterEntity $character): float
    {
        $raw = $character['trading_karma'] ?? 0;

        return is_numeric($raw) ? (float) $raw : 0.0;
    }
}
