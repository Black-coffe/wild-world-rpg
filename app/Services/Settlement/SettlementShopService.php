<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\OutfitModel;
use App\Models\SettlementShopItemModel;
use App\Models\WeaponModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * ADR-101 Фаза 3 (рефайн) — фракционные лавки оплотов («unique-stock»).
 *
 * Лавка оплота продаёт курируемый фикс-ассортимент СУЩЕСТВУЮЩИХ предметов (settlement_shop_items)
 * за золото. Цена зависит от фракции игрока: член своей фракции — скидка, чужой/без фракции —
 * наценка (settlements.shop.member_discount_pct / other_markup_pct). Выдача предмета = реюз
 * grant-путей {@see App\TaskHandlers\Craft\GenericCraftCompletionHandler} (characters_weapons /
 * characters_outfits / crafted_items_log).
 *
 * Dormant-first: killswitch `settlements.shop.enabled` (default OFF). Сервис — чистый домен,
 * Telegram не шлёт (тестируемо). Caption-самодостаточность (media-off) обеспечивает action-слой.
 */
final class SettlementShopService
{
    use GameSettingsReaderTrait;

    public const KEY_ENABLED          = 'settlements.shop.enabled';
    public const KEY_MEMBER_DISCOUNT  = 'settlements.shop.member_discount_pct';
    public const KEY_OTHER_MARKUP     = 'settlements.shop.other_markup_pct';

    public const DEFAULT_MEMBER_DISCOUNT = 15;
    public const DEFAULT_OTHER_MARKUP    = 25;

    private SettlementShopItemModel $shopItems;
    private WeaponModel $weapons;
    private OutfitModel $outfits;
    private CraftedItemsModel $crafted;
    private CharacterModel $characters;
    private CharacterFactionModel $factions;

    public function __construct(
        ?SettlementShopItemModel $shopItems = null,
        ?WeaponModel $weapons = null,
        ?OutfitModel $outfits = null,
        ?CraftedItemsModel $crafted = null,
        ?CharacterModel $characters = null,
        ?CharacterFactionModel $factions = null
    ) {
        $this->shopItems  = $shopItems ?? new SettlementShopItemModel();
        $this->weapons    = $weapons ?? new WeaponModel();
        $this->outfits    = $outfits ?? new OutfitModel();
        $this->crafted    = $crafted ?? new CraftedItemsModel();
        $this->characters = $characters ?? new CharacterModel();
        $this->factions   = $factions ?? new CharacterFactionModel();
    }

    /** Включены ли фракционные лавки (killswitch, default OFF). */
    public function enabled(): bool
    {
        return $this->gsBool(self::KEY_ENABLED, false);
    }

    public function memberDiscountPct(): int
    {
        $p = $this->gsInt(self::KEY_MEMBER_DISCOUNT, self::DEFAULT_MEMBER_DISCOUNT);

        return max(0, min(90, $p));
    }

    public function otherMarkupPct(): int
    {
        $p = $this->gsInt(self::KEY_OTHER_MARKUP, self::DEFAULT_OTHER_MARKUP);

        return max(0, min(500, $p));
    }

    /**
     * Финальная цена с учётом фракции: своя → скидка, чужая/без → наценка.
     */
    public function priceFor(int $basePrice, int $settlementFactionId, int $playerFactionId): int
    {
        if ($basePrice <= 0) {
            return 0;
        }
        $isMember = $playerFactionId > 0 && $playerFactionId === $settlementFactionId;
        if ($isMember) {
            $pct = $this->memberDiscountPct();

            return $pct > 0 ? max(1, (int) ceil($basePrice * (100 - $pct) / 100)) : $basePrice;
        }
        $pct = $this->otherMarkupPct();

        return $pct > 0 ? max(1, (int) ceil($basePrice * (100 + $pct) / 100)) : $basePrice;
    }

    /**
     * Ассортимент лавки поселения с разрешёнными именами и ценой под фракцию игрока.
     *
     * @return list<array{shop_item_id:int,type:string,item_id:int,name:string,level:int,rarity:string,base_price:int,price:int,is_member:bool}>
     */
    public function listForSettlement(int $settlementId, int $settlementFactionId, int $playerFactionId): array
    {
        $out = [];
        foreach ($this->shopItems->forSettlement($settlementId) as $row) {
            $shopItemId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $type       = is_string($row['item_type'] ?? null) ? $row['item_type'] : '';
            $itemId     = is_numeric($row['item_id'] ?? null) ? (int) $row['item_id'] : 0;
            $basePrice  = is_numeric($row['base_price'] ?? null) ? (int) $row['base_price'] : 0;
            if ($shopItemId <= 0 || $itemId <= 0 || $type === '') {
                continue;
            }
            $meta = $this->itemMeta($type, $itemId);
            if ($meta === null) {
                continue;
            }
            $out[] = [
                'shop_item_id' => $shopItemId,
                'type'         => $type,
                'item_id'      => $itemId,
                'name'         => $meta['name'],
                'level'        => $meta['level'],
                'rarity'       => $meta['rarity'],
                'base_price'   => $basePrice,
                'price'        => $this->priceFor($basePrice, $settlementFactionId, $playerFactionId),
                'is_member'    => $playerFactionId > 0 && $playerFactionId === $settlementFactionId,
            ];
        }

        return $out;
    }

    /**
     * Купить позицию ассортимента. Полная валидация (killswitch, позиция принадлежит поселению,
     * предмет существует, золото), затем списание золота + выдача предмета.
     *
     * @return array{success:bool, message:string, name?:string, price?:int, gold_left?:int}
     */
    public function purchase(int $characterId, int $shopItemId, int $settlementId, int $settlementFactionId): array
    {
        if (! $this->enabled()) {
            return ['success' => false, 'message' => 'Лавка сейчас закрыта.'];
        }

        $row = $this->shopItems->find($shopItemId);
        if (! is_array($row) || (is_numeric($row['settlement_id'] ?? null) ? (int) $row['settlement_id'] : 0) !== $settlementId) {
            return ['success' => false, 'message' => 'Этого товара здесь нет.'];
        }
        $type      = is_string($row['item_type'] ?? null) ? $row['item_type'] : '';
        $itemId    = is_numeric($row['item_id'] ?? null) ? (int) $row['item_id'] : 0;
        $basePrice = is_numeric($row['base_price'] ?? null) ? (int) $row['base_price'] : 0;
        $meta      = $itemId > 0 ? $this->itemMeta($type, $itemId) : null;
        if ($meta === null) {
            return ['success' => false, 'message' => 'Товар недоступен.'];
        }

        $playerFactionId = $this->factions->getFactionId($characterId);
        $price           = $this->priceFor($basePrice, $settlementFactionId, $playerFactionId);

        $charRaw = $this->characters->find($characterId);
        $gold    = $this->goldOf($charRaw);
        if ($gold < $price) {
            return ['success' => false, 'message' => "Не хватает золота: нужно {$price} 💰, а есть {$gold}."];
        }

        if (! $this->characters->decreaseGold($characterId, (float) $price)) {
            return ['success' => false, 'message' => 'Не удалось списать золото.'];
        }

        $this->grantItem($characterId, $type, $itemId);

        return [
            'success'   => true,
            'message'   => 'ok',
            'name'      => $meta['name'],
            'price'     => $price,
            'gold_left' => $gold - $price,
        ];
    }

    /**
     * Метаданные предмета (имя/уровень/редкость) по типу+id.
     *
     * @return array{name:string, level:int, rarity:string}|null
     */
    private function itemMeta(string $type, int $itemId): ?array
    {
        if ($type === 'weapon') {
            $r = $this->weapons->find($itemId);
            if (! is_array($r)) {
                return null;
            }

            return [
                'name'   => is_string($r['name'] ?? null) ? $r['name'] : 'Оружие',
                'level'  => is_numeric($r['required_level'] ?? null) ? (int) $r['required_level'] : 0,
                'rarity' => is_string($r['rarity'] ?? null) ? $r['rarity'] : '',
            ];
        }
        if ($type === 'outfit') {
            $r = $this->outfits->find($itemId);
            if (! is_array($r)) {
                return null;
            }

            return [
                'name'   => is_string($r['name'] ?? null) ? $r['name'] : 'Снаряжение',
                'level'  => is_numeric($r['required_level'] ?? null) ? (int) $r['required_level'] : 0,
                'rarity' => is_string($r['rarity'] ?? null) ? $r['rarity'] : '',
            ];
        }
        if ($type === 'crafted') {
            $r = $this->crafted->find($itemId);
            if (! is_array($r)) {
                return null;
            }

            return [
                'name'   => is_string($r['name_rus'] ?? null) ? $r['name_rus'] : 'Предмет',
                'level'  => is_numeric($r['required_level'] ?? null) ? (int) $r['required_level'] : 0,
                'rarity' => '',
            ];
        }

        return null;
    }

    /**
     * Выдать игроку предмет (qty 1). Зеркалит grant-пути GenericCraftCompletionHandler.
     */
    private function grantItem(int $characterId, string $type, int $itemId): void
    {
        if ($type === 'weapon') {
            $model = new CharactersWeaponsModel();
            $row   = $model->where('character_id', $characterId)->where('weapon_id', $itemId)->first();
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $qty = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
                $model->update((int) $row['id'], ['quantity' => $qty + 1]);
            } else {
                $model->insert([
                    'character_id'       => $characterId,
                    'weapon_id'          => $itemId,
                    'quantity'           => 1,
                    'current_durability' => 100,
                    'equipped'           => 0,
                    'slot'               => 'hand',
                ]);
            }

            return;
        }

        if ($type === 'outfit') {
            $model    = new CharactersOutfitsModel();
            $outfit   = $this->outfits->find($itemId);
            $slotRaw  = is_array($outfit) && is_string($outfit['slot'] ?? null) ? $outfit['slot'] : 'body';
            $row      = $model->where('character_id', $characterId)->where('outfit_id', $itemId)->first();
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $qty = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
                $model->update((int) $row['id'], ['quantity' => $qty + 1]);
            } else {
                $model->insert([
                    'character_id'       => $characterId,
                    'outfit_id'          => $itemId,
                    'quantity'           => 1,
                    'current_durability' => 100,
                    'equipped'           => 0,
                    'slot'               => $slotRaw,
                ]);
            }

            return;
        }

        if ($type === 'crafted') {
            $item = $this->crafted->find($itemId);
            if (! is_array($item)) {
                return;
            }
            $logModel = new CraftedItemsLogModel();
            $row      = $logModel->where('character_id', $characterId)->where('crafted_item_id', $itemId)->first();
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $qty = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
                $logModel->update((int) $row['id'], ['quantity' => $qty + 1]);
            } else {
                $logModel->insert([
                    'character_id'      => $characterId,
                    'task_id'          => null,
                    'crafted_item_id'  => $itemId,
                    'type'             => $item['type'] ?? null,
                    'direction_craft'  => $item['direction_craft'] ?? null,
                    'crafting_location' => $item['crafting_location'] ?? null,
                    'durability_count' => $item['durability_count'] ?? null,
                    'durability_time'  => null,
                    'quantity'         => 1,
                ]);
            }
        }
    }

    /** Золото персонажа (Entity или array). */
    private function goldOf(mixed $char): int
    {
        if ($char instanceof \App\Entities\CharacterEntity) {
            $g = $char['gold'] ?? null;

            return is_numeric($g) ? (int) $g : 0;
        }
        if (is_array($char)) {
            $g = $char['gold'] ?? null;

            return is_numeric($g) ? (int) $g : 0;
        }

        return 0;
    }
}
