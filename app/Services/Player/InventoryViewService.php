<?php

declare(strict_types=1);

namespace App\Services\Player;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;

/**
 * W2.N1-02 (ADR-190) — модель инвентаря: всё, что несёт персонаж, одним ядром для двух клиентов.
 *
 * Бот (`ResourcesGatheredAction`, `CraftedResourcesAction`) берёт отсюда сырые списки
 * {@see gathered()} и {@see crafted()} и рисует их своими экранами с сортировкой
 * `InventorySortService`; веб (`site/_play/native_inventory`) — единый список
 * {@see forCharacter()} с категориями. Одни и те же строки БД, одни и те же количества.
 *
 * Категории: добытые ресурсы — одна полка, крафт — по `crafted_items.type` (карта
 * {@see CRAFTED_TYPES}; неизвестный тип уходит в «Прочие», как в боте). Еда из крафта нигде не
 * применяется (bugs-info-0923-02) — модель помечает её, оба клиента пишут это честно.
 *
 * @phpstan-type Item array{kind:string, name:string, quantity:int, rarity:?string, price:float, category:string, food_unused:bool}
 * @phpstan-type Category array{key:string, emoji:string, title:string, count:int}
 * @phpstan-type Inventory array{items:list<Item>, categories:list<Category>, total_value:float}
 */
class InventoryViewService
{
    public const KIND_RESOURCE = 'resource';
    public const KIND_CRAFTED  = 'crafted';

    /** Полка добытых ресурсов. */
    public const CATEGORY_RESOURCES = 'resources';

    /** Полка крафта с типом вне карты. */
    public const CATEGORY_OTHER = 'other';

    /**
     * `crafted_items.type` → ключ вкладки и подпись. 🔴 Карта обязана покрывать ВСЕ значения
     * `crafted_items.type` (аудит 12.08.2026: в проде 18 типов), иначе предмет уходит в «Прочие».
     * Порядок = порядок полок в боте.
     *
     * @var array<string, array{key:string, emoji:string, title:string}>
     */
    public const CRAFTED_TYPES = [
        'component'    => ['key' => 'component',    'emoji' => '📐', 'title' => 'Компоненты'],
        'drug'         => ['key' => 'drug',         'emoji' => '💊', 'title' => 'Лекарства'],
        'food'         => ['key' => 'food',         'emoji' => '🍲', 'title' => 'Еда'],
        'tool'         => ['key' => 'tool',         'emoji' => '🛠️', 'title' => 'Инструменты'],
        'weapon'       => ['key' => 'weapon',       'emoji' => '⚔️', 'title' => 'Оружие'],
        'clothing'     => ['key' => 'clothing',     'emoji' => '👕', 'title' => 'Одежда'],
        'accessory'    => ['key' => 'accessory',    'emoji' => '💍', 'title' => 'Украшения'],
        'defense'      => ['key' => 'defense',      'emoji' => '🛡', 'title' => 'Защита от событий'],
        'building'     => ['key' => 'building',     'emoji' => '🏠', 'title' => 'Постройки'],
        'workbench'    => ['key' => 'workbench',    'emoji' => '🔬', 'title' => 'Верстаки'],
        'robots'       => ['key' => 'robots',       'emoji' => '🤖', 'title' => 'Роботы'],
        'drones'       => ['key' => 'drones',       'emoji' => '🛸', 'title' => 'Дроны'],
        'transport'    => ['key' => 'transport',    'emoji' => '🚚', 'title' => 'Транспорт'],
        'teleport'     => ['key' => 'teleport',     'emoji' => '🌀', 'title' => 'Телепорты'],
        'utility'      => ['key' => 'utility',      'emoji' => '🔧', 'title' => 'Полезные штуки'],
        'decorative'   => ['key' => 'decorative',   'emoji' => '🎨', 'title' => 'Декор'],
        'magical item' => ['key' => 'magical',      'emoji' => '🔮', 'title' => 'Магические предметы'],
        'military'     => ['key' => 'military',     'emoji' => '🛡️', 'title' => 'Военное'],
    ];

    /** @var array{key:string, emoji:string, title:string} */
    public const OTHER = ['key' => self::CATEGORY_OTHER, 'emoji' => '🔸', 'title' => 'Прочие предметы'];

    /** @var array{key:string, emoji:string, title:string} */
    public const RESOURCES = ['key' => self::CATEGORY_RESOURCES, 'emoji' => '📦', 'title' => 'Добытые ресурсы'];

    /** Пометка еды из крафта: применить её негде (Аптечка и Провизия читают только `drug`). */
    public const FOOD_MARKER = 'не применяется, выводится из обращения';

    /** Путь к еде, которая работает; подписи сверены с кнопками `💊 Аптечка` → `🍲 Провизия`. */
    public const FOOD_PATH_LINE = '↳ Еда и питьё, которые работают: 💊 Аптечка → 🍲 Провизия';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /** @param BaseConnection<object, object>|null $db */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Добытые ресурсы персонажа: `quantity`, `name`, `rarity`, `price` (строки для
     * `InventorySortService`), в порядке строк склада.
     *
     * @return list<array<string, mixed>>
     */
    public function gathered(int $characterId): array
    {
        return $this->rows(
            'SELECT character_resources.quantity, resources.name, resources.rarity, resources.price'
            . ' FROM character_resources JOIN resources ON resources.id = character_resources.id_resources'
            . ' WHERE character_resources.id_characters = ?',
            $characterId
        );
    }

    /**
     * Крафтовые предметы персонажа: `quantity`, `name_rus`, `type`, `price` и `name` (= name_rus,
     * для сортировщика), свежие первыми.
     *
     * @return list<array<string, mixed>>
     */
    public function crafted(int $characterId): array
    {
        $rows = $this->rows(
            'SELECT crafted_items_log.quantity, crafted_items.name_rus, crafted_items.type, crafted_items.price'
            . ' FROM crafted_items_log JOIN crafted_items ON crafted_items.id = crafted_items_log.crafted_item_id'
            . ' WHERE crafted_items_log.character_id = ? ORDER BY crafted_items_log.id DESC',
            $characterId
        );
        foreach ($rows as $i => $r) {
            $rows[$i]['name'] = $r['name_rus'] ?? '';
        }

        return $rows;
    }

    /**
     * Единый список всего, что несёт персонаж.
     *
     * @return Inventory
     */
    public function forCharacter(int $characterId): array
    {
        return self::build($this->gathered($characterId), $this->crafted($characterId));
    }

    /**
     * Сборка модели из сырых списков — чистая функция (тестируется без БД).
     *
     * @param array<mixed> $gathered строки {@see gathered()}
     * @param array<mixed> $crafted  строки {@see crafted()}
     *
     * @return Inventory
     */
    public static function build(array $gathered, array $crafted): array
    {
        $items = [];
        $total = 0.0;
        foreach ($gathered as $r) {
            if (! is_array($r)) {
                continue;
            }
            $item    = self::item(self::KIND_RESOURCE, $r, self::CATEGORY_RESOURCES);
            $items[] = $item;
            $total  += $item['price'] * $item['quantity'];
        }
        foreach ($crafted as $r) {
            if (! is_array($r)) {
                continue;
            }
            $type    = is_string($r['type'] ?? null) ? $r['type'] : '';
            $items[] = self::item(self::KIND_CRAFTED, $r, self::categoryOf($type));
        }

        // Полки — в порядке бота: ресурсы, типы крафта по карте, «Прочие» последними.
        $counts = [];
        foreach ($items as $item) {
            $counts[$item['category']] = ($counts[$item['category']] ?? 0) + 1;
        }
        $categories = [];
        foreach (array_merge([self::RESOURCES], array_values(self::CRAFTED_TYPES), [self::OTHER]) as $meta) {
            if (isset($counts[$meta['key']])) {
                $categories[] = $meta + ['count' => $counts[$meta['key']]];
            }
        }

        return ['items' => $items, 'categories' => $categories, 'total_value' => $total];
    }

    /** Ключ вкладки для `crafted_items.type`. */
    public static function categoryOf(string $type): string
    {
        return self::CRAFTED_TYPES[$type]['key'] ?? self::CATEGORY_OTHER;
    }

    /**
     * @param array<mixed> $r
     *
     * @return Item
     */
    private static function item(string $kind, array $r, string $category): array
    {
        $name   = $kind === self::KIND_CRAFTED ? ($r['name_rus'] ?? '') : ($r['name'] ?? '');
        $rarity = $r['rarity'] ?? null;

        return [
            'kind'        => $kind,
            'name'        => is_string($name) ? $name : '',
            'quantity'    => is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0,
            'rarity'      => $kind === self::KIND_RESOURCE && is_scalar($rarity) ? (string) $rarity : null,
            'price'       => is_numeric($r['price'] ?? null) ? (float) $r['price'] : 0.0,
            'category'    => $category,
            'food_unused' => $kind === self::KIND_CRAFTED && ($r['type'] ?? null) === 'food',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int $characterId): array
    {
        $res  = $this->db->query($sql, [$characterId]);
        $rows = [];
        foreach ($res instanceof ResultInterface ? $res->getResultArray() : [] as $r) {
            $row = [];
            foreach ($r as $k => $v) {
                $row[(string) $k] = $v;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
