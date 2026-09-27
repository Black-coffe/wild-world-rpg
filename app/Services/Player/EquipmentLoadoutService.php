<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\Economy\GearSaleService;
use App\Services\Onboarding\BuildLockService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;

/**
 * W2.N1-03 (ADR-190) — снаряжение: одна модель и один сервис надевания для бота и веба.
 *
 * {@see forCharacter()} — модель экрана «Экип»: есть ли Арсенал (иначе lock с путём к нему),
 * на базе ли персонаж, оружие и броня с характеристиками и отметкой «надето». {@see equip()},
 * {@see unequip()}, {@see toggle()} — все проверки прежних `ToggleEquip*Action` (Арсенал,
 * владение, количество, справочник, слот, Метка пустоши, «только на базе») в том же порядке и
 * с теми же смыслами; бот и веб только рисуют результат.
 *
 * Атомарность: смена — ОДИН `UPDATE`, который в слоте ставит `equipped = (id = выбранный)`.
 * Двух надетых в слоте после него не бывает при любой гонке: каждый такой UPDATE сам по себе
 * оставляет ровно один надетый, а InnoDB сериализует их на одних и тех же строках. Раньше это
 * были два запроса («снять всех» → «надеть этот»), и два параллельных тапа оставляли двух
 * надетых. Слот брони — эффективный (`outfits.slot`, запасной — `characters_outfits.slot`):
 * строка склада с пустым `slot` больше не выпадает из «снять остальных в слоте».
 *
 * @phpstan-type Item array{
 *     kind:string, row_id:int, name:string, name_en:string, quantity:int, equipped:bool, slot:?string,
 *     soulbound:?array{source:string, level:int, coords:string}, info:array<string, mixed>
 * }
 * @phpstan-type Lock array{title:string, required_level:int, callback:string, button:string}
 * @phpstan-type Loadout array{arsenal:bool, lock:?Lock, on_base:bool, sale_enabled:bool, weapons:list<Item>, armor:list<Item>}
 * @phpstan-type Outcome array{ok:bool, code:string, item:?Item, slot:?string}
 */
class EquipmentLoadoutService
{
    public const KIND_WEAPON = 'weapon';
    public const KIND_ARMOR  = 'armor';

    /** Коды исхода: успех. */
    public const EQUIPPED   = 'equipped';
    public const UNEQUIPPED = 'unequipped';

    /** Коды отказа — тот же набор смыслов, что у прежних handler'ов. */
    public const NO_ARSENAL_CATALOG = 'no_arsenal_catalog';
    public const NO_ARSENAL         = 'no_arsenal';
    public const NOT_FOUND          = 'not_found';
    public const NO_QUANTITY        = 'no_quantity';
    public const NO_INFO            = 'no_info';
    public const NO_SLOT            = 'no_slot';
    public const SOULBOUND          = 'soulbound';
    public const NOT_ON_BASE        = 'not_on_base';
    public const ALREADY            = 'already';

    /** Ключ Арсенала в справочнике зданий и в `Config\Buildings`. */
    public const ARSENAL = 'Arsenal';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /** @param BaseConnection<object, object>|null $db */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Модель экрана снаряжения.
     *
     * @return Loadout
     */
    public function forCharacter(int $characterId): array
    {
        $arsenal = $this->hasArsenal($characterId);

        return [
            'arsenal'      => $arsenal,
            'lock'         => $arsenal ? null : self::arsenalLock(),
            'on_base'      => $this->isOnBase($characterId),
            'sale_enabled' => (new GearSaleService())->isEnabled(),
            'weapons'      => $this->items($characterId, self::KIND_WEAPON),
            'armor'        => $this->items($characterId, self::KIND_ARMOR),
        ];
    }

    /**
     * Один предмет персонажа (для экранов деталей); null — строки нет или она чужая.
     *
     * @return Item|null
     */
    public function item(int $characterId, string $kind, int $rowId): ?array
    {
        $row = $this->first('SELECT * FROM ' . self::table($kind) . ' WHERE id = ? LIMIT 1', [$rowId]);
        if ($row === null || self::int($row['character_id'] ?? 0) !== $characterId) {
            return null;
        }
        $info = $this->catalogRow($kind, $row);

        return $info === null ? null : self::toItem($kind, $row, $info);
    }

    /** Персонаж стоит на клетке своей активной базы. */
    public function isOnBase(int $characterId): bool
    {
        $base = $this->first(
            "SELECT m.cell_number FROM claimed_cells cc JOIN map m ON m.id = cc.map_cell_id"
            . " WHERE cc.character_id = ? AND cc.status = 'active' ORDER BY cc.id LIMIT 1",
            [$characterId]
        );
        $char = $this->first('SELECT cell_number FROM characters WHERE id = ? LIMIT 1', [$characterId]);

        return $base !== null && $char !== null
            && is_numeric($base['cell_number'] ?? null)
            && self::int($base['cell_number']) === self::int($char['cell_number'] ?? null);
    }

    /** У персонажа построен Арсенал. */
    public function hasArsenal(int $characterId): bool
    {
        $arsenalId = $this->arsenalId();

        return $arsenalId !== null && $this->first(
            'SELECT 1 AS ok FROM character_buildings WHERE character_id = ? AND building_id = ? LIMIT 1',
            [$characterId, $arsenalId]
        ) !== null;
    }

    /**
     * Надеть: снаряжение слота меняется одним UPDATE (в слоте остаётся ровно этот предмет).
     *
     * @return Outcome
     */
    public function equip(int $characterId, string $kind, int $rowId): array
    {
        $check = $this->check($characterId, $kind, $rowId, false);
        if (! $check['ok'] || $check['item'] === null) {
            return $check;
        }
        $item = $check['item'];
        if ($item['equipped']) {
            return self::outcome(true, self::ALREADY, $item);
        }

        return $this->doEquip($characterId, $kind, $item);
    }

    /**
     * Снять (можно где угодно, как и раньше; броню — и без своего Арсенала).
     *
     * @return Outcome
     */
    public function unequip(int $characterId, string $kind, int $rowId): array
    {
        $check = $this->check($characterId, $kind, $rowId, true);
        if (! $check['ok'] || $check['item'] === null) {
            return $check;
        }
        $item = $check['item'];
        if (! $item['equipped']) {
            return self::outcome(true, self::ALREADY, $item);
        }

        return $this->doUnequip($characterId, $kind, $item);
    }

    /**
     * Переключатель бота: надето — снять, иначе — надеть.
     *
     * @return Outcome
     */
    public function toggle(int $characterId, string $kind, int $rowId): array
    {
        $check = $this->check($characterId, $kind, $rowId, null);
        if (! $check['ok'] || $check['item'] === null) {
            return $check;
        }

        return $check['item']['equipped']
            ? $this->doUnequip($characterId, $kind, $check['item'])
            : $this->doEquip($characterId, $kind, $check['item']);
    }

    /**
     * Текст отказа для игрока — формулировки прежних `ToggleEquip*Action` (оружие и броня
     * говорили по-разному, так и осталось); один источник для бота и веба.
     */
    public static function refusal(string $kind, string $code, string $name): string
    {
        if ($kind === self::KIND_WEAPON) {
            return match ($code) {
                self::NO_ARSENAL_CATALOG => 'Здание "Арсенал" не найдено. Обратитесь к администрации.',
                self::NO_ARSENAL         => 'У тебя нет здания «Арсенал» на базе, поэтому нельзя менять экипировку!',
                self::NO_QUANTITY        => 'У тебя нет экземпляров этого оружия (количество = 0).',
                self::NO_INFO            => 'Информация об оружии (в WeaponModel) не найдена.',
                self::SOULBOUND          => "🔒 «{$name}» — это Метка пустоши, трофей с узла. Его нельзя надеть: он и так усиливает тебя в бою с узлами, но в руки не берётся и не продаётся.",
                self::NOT_ON_BASE        => "Ты не на базе и не можешь сейчас экипировать «{$name}».",
                default                  => 'Оружие не найдено или не принадлежит вашему персонажу.',
            };
        }

        return match ($code) {
            self::NO_ARSENAL_CATALOG => 'Ты можешь менять экипировку только в здании "Арсенал".',
            self::NO_ARSENAL         => 'У тебя нет здания «Арсенал» на базе, поэтому нельзя менять экипировку!',
            self::NO_INFO            => 'Информация об экипировке не найдена в справочнике.',
            self::NO_SLOT            => "Невозможно экипировать «{$name}» — слот не задан.",
            self::SOULBOUND          => "🔒 «{$name}» — это Метка пустоши, трофей с узла. Её нельзя надеть: она и так усиливает тебя в бою с узлами, но не носится и не продаётся.",
            self::NOT_ON_BASE        => "Снаряжение хранится на базе.\nТы не на базе, не можешь надеть \"{$name}\"!",
            default                  => 'Броня не найдена в инвентаре.',
        };
    }

    /**
     * Lock без Арсенала: подпись, уровень постройки (из `Config\Buildings` через BuildLockService)
     * и путь к стройке.
     *
     * @return Lock
     */
    public static function arsenalLock(): array
    {
        return [
            'title'          => 'Нужен Арсенал',
            'required_level' => (new BuildLockService())->requiredLevel(self::ARSENAL),
            'callback'       => 'genericBuildInfo_Arsenal',
            'button'         => '🏗 К стройке Арсенала',
        ];
    }

    /**
     * Общие проверки до смены — порядок прежних handler'ов: Арсенал в справочнике → Арсенал у
     * персонажа → владение → (оружие) количество → справочник предмета → (броня) слот.
     *
     * Броню прежний `ToggleEquipArmorAction` снимал и без своего Арсенала (гейт был только на
     * Арсенал в справочнике), поэтому для брони свой Арсенал проверяется лишь когда смена —
     * надевание: `$unequip` false — надеть, true — снять, null — переключатель (решает строка).
     *
     * @return Outcome
     */
    private function check(int $characterId, string $kind, int $rowId, ?bool $unequip): array
    {
        if ($this->arsenalId() === null) {
            return self::outcome(false, self::NO_ARSENAL_CATALOG);
        }
        $armorMayUnequip = $kind === self::KIND_ARMOR && $unequip !== false;
        if (! $armorMayUnequip && ! $this->hasArsenal($characterId)) {
            return self::outcome(false, self::NO_ARSENAL);
        }
        $row = $this->first('SELECT * FROM ' . self::table($kind) . ' WHERE id = ? LIMIT 1', [$rowId]);
        if ($row === null || self::int($row['character_id'] ?? 0) !== $characterId) {
            return self::outcome(false, self::NOT_FOUND);
        }
        if ($armorMayUnequip && $unequip === null && empty($row['equipped']) && ! $this->hasArsenal($characterId)) {
            // Переключатель на не надетой броне — это надевание: свой Арсенал обязателен.
            return self::outcome(false, self::NO_ARSENAL);
        }
        if ($kind === self::KIND_WEAPON && self::int($row['quantity'] ?? 0) < 1) {
            return self::outcome(false, self::NO_QUANTITY);
        }
        $info = $this->catalogRow($kind, $row);
        if ($info === null) {
            return self::outcome(false, self::NO_INFO);
        }
        $item = self::toItem($kind, $row, $info);
        if ($kind === self::KIND_ARMOR && ($item['slot'] ?? '') === '') {
            return self::outcome(false, self::NO_SLOT, $item);
        }

        return self::outcome(true, 'checked', $item);
    }

    /**
     * @param Item $item
     *
     * @return Outcome
     */
    private function doEquip(int $characterId, string $kind, array $item): array
    {
        // WB9 (ADR-137): Метка пустоши не надевается — raid-only трофей, не PvP-экипировка.
        if ($item['soulbound'] !== null) {
            return self::outcome(false, self::SOULBOUND, $item);
        }
        if (! $this->isOnBase($characterId)) {
            return self::outcome(false, self::NOT_ON_BASE, $item);
        }

        if ($kind === self::KIND_WEAPON) {
            // Оружие — один слот на персонажа.
            $this->db->query(
                'UPDATE characters_weapons SET equipped = IF(id = ?, 1, 0), updated_at = NOW() WHERE character_id = ?',
                [$item['row_id'], $characterId]
            );
        } else {
            $this->db->query(
                'UPDATE characters_outfits co LEFT JOIN outfits o ON o.id = co.outfit_id'
                . ' SET co.equipped = IF(co.id = ?, 1, 0), co.updated_at = NOW()'
                . " WHERE co.character_id = ? AND COALESCE(NULLIF(o.slot, ''), co.slot) = ?",
                [$item['row_id'], $characterId, (string) $item['slot']]
            );
        }

        $item['equipped'] = true;

        return self::outcome(true, self::EQUIPPED, $item);
    }

    /**
     * @param Item $item
     *
     * @return Outcome
     */
    private function doUnequip(int $characterId, string $kind, array $item): array
    {
        $this->db->query(
            'UPDATE ' . self::table($kind) . ' SET equipped = 0, updated_at = NOW() WHERE id = ? AND character_id = ?',
            [$item['row_id'], $characterId]
        );
        $item['equipped'] = false;

        return self::outcome(true, self::UNEQUIPPED, $item);
    }

    /**
     * Предметы персонажа в порядке строк склада; строка без записи в справочнике пропускается
     * (как в прежних списках).
     *
     * @return list<Item>
     */
    private function items(int $characterId, string $kind): array
    {
        $res  = $this->db->query('SELECT * FROM ' . self::table($kind) . ' WHERE character_id = ? ORDER BY id', [$characterId]);
        $rows = $res instanceof ResultInterface ? $res->getResultArray() : [];
        $ids  = [];
        foreach ($rows as $r) {
            $ids[] = self::int($r[$kind === self::KIND_WEAPON ? 'weapon_id' : 'outfit_id'] ?? 0);
        }
        $catalog = [];
        if ($ids !== []) {
            $table = $kind === self::KIND_WEAPON ? 'weapons' : 'outfits';
            $cres  = $this->db->query('SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($cres instanceof ResultInterface ? $cres->getResultArray() : [] as $c) {
                $catalog[self::int($c['id'] ?? 0)] = self::assoc($c);
            }
        }
        $items = [];
        foreach ($rows as $r) {
            $info = $catalog[self::int($r[$kind === self::KIND_WEAPON ? 'weapon_id' : 'outfit_id'] ?? 0)] ?? null;
            if ($info !== null) {
                $items[] = self::toItem($kind, self::assoc($r), $info);
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function catalogRow(string $kind, array $row): ?array
    {
        return $kind === self::KIND_WEAPON
            ? $this->first('SELECT * FROM weapons WHERE id = ? LIMIT 1', [self::int($row['weapon_id'] ?? 0)])
            : $this->first('SELECT * FROM outfits WHERE id = ? LIMIT 1', [self::int($row['outfit_id'] ?? 0)]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $info
     *
     * @return Item
     */
    private static function toItem(string $kind, array $row, array $info): array
    {
        $soulbound = null;
        if (! empty($row['is_soulbound'])) {
            $soulbound = [
                'source' => is_scalar($row['soulbound_source'] ?? null) ? (string) $row['soulbound_source'] : 'Узел',
                'level'  => self::int($row['soulbound_level'] ?? 0),
                'coords' => is_scalar($row['soulbound_coords'] ?? null) ? (string) $row['soulbound_coords'] : '',
            ];
        }
        $slot = null;
        if ($kind === self::KIND_ARMOR) {
            $slot = is_string($info['slot'] ?? null) && $info['slot'] !== '' ? $info['slot']
                : (is_string($row['slot'] ?? null) && $row['slot'] !== '' ? $row['slot'] : null);
        }

        return [
            'kind'      => $kind,
            'row_id'    => self::int($row['id'] ?? 0),
            'name'      => is_string($info['name'] ?? null) ? $info['name'] : '???',
            'name_en'   => is_string($info['name_en'] ?? null) ? $info['name_en'] : '',
            'quantity'  => self::int($row['quantity'] ?? 0),
            'equipped'  => ! empty($row['equipped']),
            'slot'      => $slot,
            'soulbound' => $soulbound,
            'info'      => $info,
        ];
    }

    private function arsenalId(): ?int
    {
        $row = $this->first('SELECT id FROM buildings WHERE name_en = ? ORDER BY id LIMIT 1', [self::ARSENAL]);

        return $row === null ? null : self::int($row['id'] ?? 0);
    }

    private static function table(string $kind): string
    {
        return $kind === self::KIND_WEAPON ? 'characters_weapons' : 'characters_outfits';
    }

    /**
     * @param Item|null $item
     *
     * @return Outcome
     */
    private static function outcome(bool $ok, string $code, ?array $item = null): array
    {
        return ['ok' => $ok, 'code' => $code, 'item' => $item, 'slot' => $item['slot'] ?? null];
    }

    /**
     * @param list<int|string> $binds
     *
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $binds): ?array
    {
        $res = $this->db->query($sql, $binds);
        $row = $res instanceof ResultInterface ? $res->getRowArray() : null;

        return is_array($row) ? self::assoc($row) : null;
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function assoc(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
