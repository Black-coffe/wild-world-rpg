<?php

declare(strict_types=1);

namespace App\Services\Economy;

use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * Продажа экипировки (оружие + броня) NPC-торговцу.
 *
 * Повод (аудит 2026-08-03, вопрос игрока «а можно как-то продать оружие и броню»).
 * Экран «💰 Продать крафт» строил категории ТОЛЬКО из `crafted_items_log`, а вся
 * экипировка — и скрафченная, и трофейная, и квестовая — пишется в
 * `characters_weapons` / `characters_outfits` мимо этой таблицы. Следствие: продать
 * оружие или броню было нельзя вообще ничем — ни у торговца, ни в лавке оплота
 * (там только purchase), ни разбором (механики нет), ни игроку (рынка нет).
 * Старая экипировка после апгрейда становилась мёртвым грузом навсегда.
 * Отсутствие sell-пути было зафиксировано в коде как известный факт —
 * см. {@see ResourceTradeService} и миграцию `Adr137SoulboundGearColumns`, где прямо
 * записан контракт: «если когда-либо появится sell/loss-путь для экипировки — он
 * ОБЯЗАН уважать `is_soulbound`». Этот сервис — тот самый путь, и контракт он держит.
 *
 * Что НЕ продаётся (инварианты, а не настройки):
 *  1. **Надетое** (`equipped = 1`). Иначе игрок остаётся с битой ссылкой в слоте.
 *     Стек флагуется целиком одной строкой, поэтому исключаем строку, а не единицу.
 *  2. **Соулбаунд-трофеи** (`is_soulbound = 1`, ADR-137, помечены 🔒). «Метка пустоши»
 *     с провенансом узла — намеренный дизайн, а не недоработка.
 *
 * Цена = `price` предмета × {@see self::KEY_PRICE_PCT}, дальше — общий торговый
 * конвейер: карма (ADR-157), складской бонус (ADR-085), инвариант спреда, суточный
 * лимит выкупа ({@see VendorDailyLimitService}). Процент нужен потому, что `price`
 * у экипировки — ценник НОВОЙ вещи (200…120 000 у оружия, 100…48 000 у брони), и
 * выкуп по нему сделал бы «скрафтил → сдал» промышленным источником золота.
 *
 * Прочность в цене НЕ участвует намеренно: износа у оружия и брони в игре нет —
 * `current_durability` только записывается при выдаче и никем не уменьшается, в БД у
 * всех строк лежит константа 100 при `durability_max` 20…250. Ровно поэтому дробь
 * «текущая / максимум» убрали с экранов экипировки. Появится реальный износ —
 * множитель по прочности встанет сюда, в {@see self::unitPrice()}.
 */
class GearSaleService
{
    public const KEY_ENABLED   = 'economy.sell_gear.enabled';
    public const KEY_PRICE_PCT = 'economy.sell_gear.price_pct';

    /** Виртуальные категории экрана «Продать крафт» (ключи без `_` — урок мёртвых `npcAct_`). */
    public const CATEGORY_WEAPON = 'gearWeapon';
    public const CATEGORY_ARMOR  = 'gearArmor';

    public const KIND_WEAPON = 'weapon';
    public const KIND_OUTFIT = 'outfit';

    private const DEFAULT_PRICE_PCT = 0.25;

    private GameSettingsService $settings;

    /**
     * Соединение подключается лениво: цена и разбор callback'ов от БД не зависят,
     * и тест этой арифметики не должен требовать поднятой базы.
     *
     * @var BaseConnection<\mysqli, \mysqli_result>|null
     */
    private ?BaseConnection $db;

    /** @param BaseConnection<\mysqli, \mysqli_result>|null $db */
    public function __construct(?GameSettingsService $settings = null, ?BaseConnection $db = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
        $this->db       = $db;
    }

    /** @return BaseConnection<\mysqli, \mysqli_result> */
    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /** Killswitch: выключенная фича прячет категории и отклоняет сделку. */
    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::KEY_ENABLED, true);
    }

    /** Доля базовой цены, которую торговец платит за экипировку. */
    public function pricePct(): float
    {
        $value = $this->settings->get(self::KEY_PRICE_PCT, self::DEFAULT_PRICE_PCT);
        if (! is_numeric($value)) {
            return self::DEFAULT_PRICE_PCT;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    /** Категория экрана → вид предмета, либо null если категория не про экипировку. */
    public function kindForCategory(string $category): ?string
    {
        return match ($category) {
            self::CATEGORY_WEAPON => self::KIND_WEAPON,
            self::CATEGORY_ARMOR  => self::KIND_OUTFIT,
            default               => null,
        };
    }

    /** Однобуквенный код вида для callback_data (лимит Telegram — 64 байта). */
    public function codeForKind(string $kind): string
    {
        return $kind === self::KIND_WEAPON ? 'w' : 'a';
    }

    /** Код из callback_data → вид предмета, либо null если код чужой. */
    public function kindForCode(string $code): ?string
    {
        return match ($code) {
            'w'     => self::KIND_WEAPON,
            'a'     => self::KIND_OUTFIT,
            default => null,
        };
    }

    /**
     * Продаваемые предметы персонажа: не надетые, не соулбаунд, с ненулевым количеством.
     *
     * @return list<array{row_id:int,item_id:int,name:string,rarity:string,quantity:int,base_price:float}>
     */
    public function sellable(int $characterId, string $kind): array
    {
        [$charTable, $linkField, $defTable] = $this->tablesFor($kind);

        $query = $this->db()->query(
            "SELECT g.id AS row_id, g.quantity, g.{$linkField} AS item_id,
                    d.name, d.rarity, d.price
             FROM {$charTable} g
             JOIN {$defTable} d ON d.id = g.{$linkField}
             WHERE g.character_id = ?
               AND COALESCE(g.equipped, 0) = 0
               AND COALESCE(g.is_soulbound, 0) = 0
               AND g.quantity > 0
             ORDER BY d.price DESC, d.name ASC",
            [$characterId]
        );

        if (! $query instanceof BaseResult) {
            return [];
        }

        $out = [];
        foreach ($query->getResultArray() as $row) {
            $out[] = $this->normalizeRow($row);
        }

        return $out;
    }

    /** Есть ли у персонажа хоть один продаваемый предмет этого вида. */
    public function hasSellable(int $characterId, string $kind): bool
    {
        return $this->sellable($characterId, $kind) !== [];
    }

    /**
     * Один продаваемый предмет по id строки инвентаря — с теми же фильтрами, что и список.
     *
     * Фильтры повторяются намеренно: список и клик обязаны видеть одно и то же, иначе
     * прямой callback обходит правило (урок `feedback_db_name_is_not_config_key`).
     *
     * @return array{row_id:int,item_id:int,name:string,rarity:string,quantity:int,base_price:float}|null
     */
    public function findSellable(int $characterId, string $kind, int $rowId): ?array
    {
        [$charTable, $linkField, $defTable] = $this->tablesFor($kind);

        $query = $this->db()->query(
            "SELECT g.id AS row_id, g.quantity, g.{$linkField} AS item_id,
                    d.name, d.rarity, d.price
             FROM {$charTable} g
             JOIN {$defTable} d ON d.id = g.{$linkField}
             WHERE g.id = ?
               AND g.character_id = ?
               AND COALESCE(g.equipped, 0) = 0
               AND COALESCE(g.is_soulbound, 0) = 0
               AND g.quantity > 0",
            [$rowId, $characterId]
        );

        if (! $query instanceof BaseResult) {
            return null;
        }
        $row = $query->getRowArray();

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * Цена одной единицы экипировки для торговца.
     *
     * Процент применяется ДО общего конвейера, поэтому карма и складской бонус
     * работают ровно как на крафте, а инвариант спреда (продажа дешевле покупки)
     * остаётся неотключаемым.
     */
    public function unitPrice(float $basePrice, float $karma, float $extraMultiplier = 1.0, ?TradePricingService $pricing = null): float
    {
        $pricing ??= new TradePricingService();

        return $pricing->sellUnitPrice(max(0.0, $basePrice) * $this->pricePct(), $karma, $extraMultiplier);
    }

    /**
     * Имена таблиц под вид предмета.
     *
     * @return array{0:string,1:string,2:string} [таблица инвентаря, поле-ссылка, таблица определений]
     */
    public function tablesFor(string $kind): array
    {
        return $kind === self::KIND_WEAPON
            ? ['characters_weapons', 'weapon_id', 'weapons']
            : ['characters_outfits', 'outfit_id', 'outfits'];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{row_id:int,item_id:int,name:string,rarity:string,quantity:int,base_price:float}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'row_id'     => $this->intOf($row['row_id'] ?? null),
            'item_id'    => $this->intOf($row['item_id'] ?? null),
            'name'       => $this->stringOf($row['name'] ?? null, '???'),
            'rarity'     => $this->stringOf($row['rarity'] ?? null, ''),
            'quantity'   => $this->intOf($row['quantity'] ?? null),
            'base_price' => $this->floatOf($row['price'] ?? null),
        ];
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function stringOf(mixed $value, string $default): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
