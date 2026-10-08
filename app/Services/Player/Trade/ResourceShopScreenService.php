<?php

declare(strict_types=1);

namespace App\Services\Player\Trade;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;

/**
 * W2.N6 (ADR-190) — модели экранов магазина сырья для обоих клиентов: хаб, продажа по редкости,
 * карточка ресурса с ценой и пресетами, покупка, опт. Бот (`ShopAction`, `SellAction`,
 * `SellResourceAction`, `BuyResourceAction`, `BulkSellAction`) и веб (`view=shop`) только рисуют
 * то, что отдаёт сервис. Ни `chat_id`, ни Telegram сюда не попадают: персонаж — id.
 *
 * Сделки идут через {@see ResourceTradeService} — его условное списание держит двойной тап и
 * одновременный бот + веб (hotfix-trade-race). Здесь сверху только потолок количества для ввода
 * «своего числа» в вебе: `qty > max_qty` — отказ `over_max`, ничего не тронуто. Кнопки-пресеты и
 * ForceReply бота идут в `ResourceTradeService` напрямую и, как раньше, продают «сколько есть».
 *
 * Цены, проценты опта, минимум золота — из `GameSettings` и строк `resources`; своих чисел баланса
 * сервис не вводит.
 */
final class ResourceShopScreenService
{
    use \App\Services\GameSettings\GameSettingsReaderTrait;

    public const KEY_BULK_ENABLED      = 'economy.bulk_sell.enabled';
    public const KEY_BULK_PERCENTS     = 'economy.bulk_sell.percent_options';
    public const DEFAULT_BULK_PERCENTS = '10,25,50,100';
    public const KEY_BUY_MIN_GOLD      = 'economy.shop.buy_resource_min_gold';

    /** Редкости витрины: 1…10 (перечисление, не баланс). */
    public const RARITIES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    /** Пресеты количества на карточке ресурса — те же, что кнопки бота. */
    public const PRESETS = [1, 5, 10, 15, 25, 50, 100, 150, 250, 500, 1000, 5000];

    public const OK       = 'ok';
    public const BAD_QTY  = 'bad_qty';
    public const OVER_MAX = 'over_max';
    public const MISSING  = 'missing';
    public const DISABLED = 'disabled';
    public const INVALID  = 'invalid';
    public const EMPTY    = 'empty';
    public const FAILED   = 'failed';

    private ResourceTradeService $trade;
    private ResourceModel $resources;
    private CharacterResourceModel $owned;
    private CharacterModel $characters;

    public function __construct(?ResourceTradeService $trade = null)
    {
        $this->trade      = $trade ?? new ResourceTradeService();
        $this->resources  = new ResourceModel();
        $this->owned      = new CharacterResourceModel();
        $this->characters = new CharacterModel();
    }

    /**
     * Хаб магазина: четыре входа. Сырьё — нативные экраны, крафт и снаряжение — маршрут бота
     * (в вебе мост, N6b).
     *
     * @return list<array{key:string, label:string, native:bool}>
     */
    public function hubEntries(): array
    {
        return [
            ['key' => 'sell',      'label' => '💰 Продать ресы',  'native' => true],
            ['key' => 'buy',       'label' => '🛍️ Купить ресы',   'native' => true],
            ['key' => 'sellCraft', 'label' => '💰 Продать крафт', 'native' => false],
            ['key' => 'buyCraft',  'label' => '🛍️ Купить крафт',  'native' => false],
        ];
    }

    /**
     * Опт: включён ли и какие доли предлагать (пусто — не предлагать).
     *
     * @return list<int>
     */
    public function bulkPercents(): array
    {
        if (! $this->gsBool(self::KEY_BULK_ENABLED, true)) {
            return [];
        }

        return self::parsePercents($this->gsString(self::KEY_BULK_PERCENTS, self::DEFAULT_BULK_PERCENTS));
    }

    public function bulkEnabled(): bool
    {
        return $this->gsBool(self::KEY_BULK_ENABLED, true);
    }

    /**
     * Экран «продать»: сколько видов ресурсов и на какую сумму, плюс доли опта по всему рюкзаку.
     * Сумма — по `sell_price` без округления итога, как всегда считал бот.
     *
     * @return array{types:int, total_value:float, rarities:list<int>, bulk:list<int>}
     */
    public function sellHubModel(int $characterId): array
    {
        $rows  = $this->owned->where('id_characters', $characterId)->findAll();
        $total = 0.0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $resource = $this->resources->find($row['id_resources']);
            if ($resource) {
                $price = is_numeric($resource['sell_price'] ?? null) ? (float) $resource['sell_price'] : 0.0;
                $qty   = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
                $total += $price * $qty;
            }
        }

        return [
            'types'       => count($rows),
            'total_value' => $total,
            'rarities'    => self::RARITIES,
            'bulk'        => $total > 0 ? $this->bulkPercents() : [],
        ];
    }

    /**
     * Ресурсы игрока одной редкости: имя, сколько есть, на какую сумму (формулой сделки).
     * `known=false` — ресурсов такой редкости в игре нет вовсе.
     *
     * @return array{rarity:int, known:bool, rows:list<array{resource_id:int, name:string, quantity:int, total:int}>, bulk:list<int>}
     */
    public function sellRarityModel(int $characterId, int $rarity): array
    {
        $catalog = $this->resources->where('rarity', $rarity)->findAll();
        if ($catalog === []) {
            return ['rarity' => $rarity, 'known' => false, 'rows' => [], 'bulk' => []];
        }

        $owned = $this->owned
            ->where('id_characters', $characterId)
            ->whereIn('id_resources', array_column($catalog, 'id'))
            ->findAll();

        $rows     = [];
        $sellable = false;
        foreach ($owned as $cr) {
            $res = is_array($cr) ? $this->resources->find($cr['id_resources']) : null;
            if (! is_array($cr) || ! $res) {
                continue;
            }
            $qty = is_numeric($cr['quantity'] ?? null) ? (int) $cr['quantity'] : 0;
            if ($qty <= 0) {
                continue;
            }
            // Опт продаёт только ресурсы с ценой > 0 — флаг для долей ниже (ADR-096).
            $price = $res['sell_price'] ?? null;
            if (is_numeric($price) && (float) $price > 0) {
                $sellable = true;
            }
            $rows[] = [
                'resource_id' => is_numeric($res['id'] ?? null) ? (int) $res['id'] : 0,
                'name'        => is_scalar($res['name'] ?? null) ? (string) $res['name'] : '',
                'quantity'    => $qty,
                'total'       => $this->trade->totalFor($qty, $this->trade->unitPrice($res, true)),
            ];
        }

        return [
            'rarity' => $rarity,
            'known'  => true,
            'rows'   => $rows,
            'bulk'   => $rows !== [] && $sellable ? $this->bulkPercents() : [],
        ];
    }

    /**
     * Карточка продажи: цена за 1 шт., итоги пресетов и потолок — сколько есть у игрока.
     *
     * @return array{resource_id:int, name:string, rarity:int, unit_price:float, unit_text:string, max_qty:int, presets:list<array{qty:int, total:int}>}|null
     */
    public function sellCardModel(int $characterId, int $resourceId): ?array
    {
        $resource = $this->resources->find($resourceId);
        if (! $resource) {
            return null;
        }

        return $this->card($resource, true) + ['max_qty' => $this->ownedQty($characterId, $resourceId)];
    }

    /**
     * Вход в покупку: сколько золота и хватает ли его на минимум торговли.
     *
     * @return array{gold:float, min_gold:int, allowed:bool, rarities:list<int>}
     */
    public function buyHubModel(int $characterId): array
    {
        $gold = $this->goldOf($characterId);
        $min  = $this->gsInt(self::KEY_BUY_MIN_GOLD, 10);

        return ['gold' => $gold, 'min_gold' => $min, 'allowed' => (int) $gold >= $min, 'rarities' => self::RARITIES];
    }

    /**
     * Витрина редкости для покупки: только торгуемое (семена с `is_tradeable=0` сюда не попадают).
     * `price_text` — цена строкой ровно так, как её всегда печатал бот.
     *
     * @return array{rarity:int, rows:list<array{resource_id:int, name:string, price_text:string}>}
     */
    public function buyRarityModel(int $rarity): array
    {
        $rows = [];
        foreach ($this->resources->where('rarity', $rarity)->where('is_tradeable', 1)->findAll() as $res) {
            $price  = $res['buy_price'] ?? null;
            $rows[] = [
                'resource_id' => is_numeric($res['id'] ?? null) ? (int) $res['id'] : 0,
                'name'        => is_scalar($res['name'] ?? null) ? (string) $res['name'] : '',
                'price_text'  => is_scalar($price) ? (string) $price : '',
            ];
        }

        return ['rarity' => $rarity, 'rows' => $rows];
    }

    /**
     * Карточка покупки: цена, пресеты, «не хватает N» с экрана нехватки и потолок — сколько
     * игрок может оплатить свежим золотом.
     *
     * @return array{resource_id:int, name:string, rarity:int, unit_price:float, unit_text:string, max_qty:int, presets:list<array{qty:int, total:int}>, need:array{qty:int, total:int}|null, gold:float}|null
     */
    public function buyCardModel(int $characterId, int $resourceId, int $needQty = 0): ?array
    {
        $resource = $this->resources->find($resourceId);
        if (! $resource) {
            return null;
        }

        $card = $this->card($resource, false);
        $gold = $this->goldOf($characterId);
        $max  = $card['unit_price'] > 0 ? (int) floor(floor($gold) / $card['unit_price']) : 0;

        return $card + [
            'max_qty' => max(0, $max),
            'need'    => $needQty > 0 ? ['qty' => $needQty, 'total' => $this->trade->totalFor($needQty, $card['unit_price'])] : null,
            'gold'    => $gold,
        ];
    }

    /**
     * Превью опта (без мутаций). `code`: `disabled` | `invalid` (доля не из списка, редкость вне
     * 1…10) | `empty` (нечего продавать) | `ok`.
     *
     * @return array{code:string, percent:int, rarity:int|null, types:int, qty:int, gold:int}
     */
    public function bulkPreviewModel(int $characterId, ?int $rarity, int $percent): array
    {
        $base = ['percent' => $percent, 'rarity' => $rarity, 'types' => 0, 'qty' => 0, 'gold' => 0];
        $gate = $this->bulkGate($rarity, $percent);
        if ($gate !== self::OK) {
            return ['code' => $gate] + $base;
        }

        $p = $this->trade->bulkSellPreview(['id' => $characterId], $percent, $rarity);
        if ($p['typesCount'] <= 0 || $p['totalQty'] <= 0) {
            return ['code' => self::EMPTY] + $base;
        }

        return ['code' => self::OK, 'types' => $p['typesCount'], 'qty' => $p['totalQty'], 'gold' => $p['totalGold']] + $base;
    }

    /**
     * Выполнить опт. Те же ворота, что у превью; сделка — `bulkSellResources()`.
     *
     * @return array{code:string, message:string, types:int, qty:int, gold:int, lines:list<array{name:string, qty:int}>}
     */
    public function bulkSell(int $characterId, ?int $rarity, int $percent): array
    {
        $none = ['types' => 0, 'qty' => 0, 'gold' => 0, 'lines' => []];
        $gate = $this->bulkGate($rarity, $percent);
        if ($gate !== self::OK) {
            return ['code' => $gate, 'message' => ''] + $none;
        }

        $r = $this->trade->bulkSellResources(['id' => $characterId], $percent, $rarity);
        if (! $r['success']) {
            return ['code' => self::FAILED, 'message' => $r['message']] + $none;
        }

        return [
            'code' => self::OK, 'message' => $r['message'],
            'types' => $r['typesSold'], 'qty' => $r['totalQty'], 'gold' => $r['totalGold'], 'lines' => $r['lines'],
        ];
    }

    /**
     * Продажа «своим числом» с потолком: больше, чем есть, — отказ, ничего не тронуто.
     *
     * @return array{code:string, message:string, qty:int, amount:int}
     */
    public function sell(int $characterId, int $resourceId, int $qty): array
    {
        $max = $this->ownedQty($characterId, $resourceId);
        if ($qty <= 0) {
            return ['code' => self::BAD_QTY, 'message' => 'Укажи количество больше нуля.', 'qty' => 0, 'amount' => 0];
        }
        if ($qty > $max) {
            return [
                'code' => self::OVER_MAX, 'qty' => 0, 'amount' => 0,
                'message' => $max > 0 ? "У тебя только {$max} шт. — больше продать нельзя." : 'Этого ресурса у тебя уже нет.',
            ];
        }

        $r = $this->trade->sellResource(['id' => $characterId], $resourceId, $qty);

        return [
            'code'    => $r['success'] ? self::OK : self::FAILED,
            'message' => $r['message'],
            'qty'     => $r['qty'] ?? 0,
            'amount'  => $r['amount'] ?? 0,
        ];
    }

    /**
     * Покупка «своим числом» с потолком: больше, чем оплачивает свежее золото, — отказ.
     *
     * @return array{code:string, message:string, qty:int, cost:int}
     */
    public function buy(int $characterId, int $resourceId, int $qty): array
    {
        $card = $this->buyCardModel($characterId, $resourceId);
        if ($card === null) {
            return ['code' => self::MISSING, 'message' => 'Ресурс не найден в базе.', 'qty' => 0, 'cost' => 0];
        }
        if ($qty <= 0) {
            return ['code' => self::BAD_QTY, 'message' => 'Укажи количество больше нуля.', 'qty' => 0, 'cost' => 0];
        }
        if ($qty > $card['max_qty']) {
            return [
                'code' => self::OVER_MAX, 'qty' => 0, 'cost' => 0,
                'message' => "Золота хватает на {$card['max_qty']} шт. — больше купить нельзя.",
            ];
        }

        $character = $this->characters->asArray()->find($characterId);
        if (! is_array($character)) {
            return ['code' => self::MISSING, 'message' => 'Персонаж не найден.', 'qty' => 0, 'cost' => 0];
        }
        /** @var array<string,mixed> $character */
        $r = $this->trade->buyResource($character, $resourceId, $qty);

        return [
            'code'    => $r['success'] ? self::OK : self::FAILED,
            'message' => $r['message'],
            'qty'     => $r['qty'] ?? 0,
            'cost'    => $r['cost'] ?? 0,
        ];
    }

    /**
     * CSV процентов из GameSettings → отсортированный уникальный список (1…100).
     * Пустой/мусорный список → [10, 25, 50]. Чистый.
     *
     * @return list<int>
     */
    public static function parsePercents(string $csv): array
    {
        $vals = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $p = (int) $part;
                if ($p >= 1 && $p <= 100) {
                    $vals[$p] = true;
                }
            }
        }
        $list = array_keys($vals);
        sort($list);

        return $list !== [] ? $list : [10, 25, 50];
    }

    private function bulkGate(?int $rarity, int $percent): string
    {
        if (! $this->bulkEnabled()) {
            return self::DISABLED;
        }
        $percents = self::parsePercents($this->gsString(self::KEY_BULK_PERCENTS, self::DEFAULT_BULK_PERCENTS));
        if (! in_array($percent, $percents, true) || ($rarity !== null && ($rarity < 1 || $rarity > 10))) {
            return self::INVALID;
        }

        return self::OK;
    }

    /**
     * @param array<string,mixed>|\App\Entities\ResourceEntity $resource
     * @return array{resource_id:int, name:string, rarity:int, unit_price:float, unit_text:string, presets:list<array{qty:int, total:int}>}
     */
    private function card(array|\App\Entities\ResourceEntity $resource, bool $selling): array
    {
        $unit    = $this->trade->unitPrice($resource, $selling);
        $presets = [];
        foreach (self::PRESETS as $qty) {
            $presets[] = ['qty' => $qty, 'total' => $this->trade->totalFor($qty, $unit)];
        }

        return [
            'resource_id' => is_numeric($resource['id'] ?? null) ? (int) $resource['id'] : 0,
            'name'        => is_scalar($resource['name'] ?? null) ? (string) $resource['name'] : '',
            'rarity'      => is_numeric($resource['rarity'] ?? null) ? (int) $resource['rarity'] : 0,
            'unit_price'  => $unit,
            'unit_text'   => $this->trade->formatUnitPrice($unit),
            'presets'     => $presets,
        ];
    }

    private function ownedQty(int $characterId, int $resourceId): int
    {
        $row = $this->owned->where('id_characters', $characterId)->where('id_resources', $resourceId)->first();
        $qty = is_array($row) ? ($row['quantity'] ?? null) : null;

        return is_numeric($qty) ? max(0, (int) $qty) : 0;
    }

    private function goldOf(int $characterId): float
    {
        $row  = $this->characters->asArray()->select('gold')->find($characterId);
        $gold = is_array($row) ? ($row['gold'] ?? null) : null;

        return is_numeric($gold) ? (float) $gold : 0.0;
    }
}
