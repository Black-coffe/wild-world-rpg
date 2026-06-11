<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Models\CharacterResourceModel;
use App\Models\ItemModifierModel;
use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * W19 (ADR-074) + W20 (ADR-075) — модернизация экземпляров предметов (player-facing «Модернизация»):
 * детерминированный накапливаемый +X% к стату. Оружие (item_type='weapon', stat='damage') и
 * броня (item_type='outfit', stat='armor'). Per-instance (item_instance_id = characters_weapons.id /
 * characters_outfits.id).
 *
 * W20 тиры: `bonus_pct` хранит НАКОПЛЕННОЕ (5→10→…→cap); шаг = `craft.modifier.bonus_pct`, потолок =
 * `craft.modifier.max_bonus_pct`; цена ×тир. Combat-путь читает bonus_pct обобщённо → тиры fence-нейтральны.
 *
 * RNG-fence-safe: `weaponDamageMultiplier`/`armorMultiplier` детерминированы (0 mt_rand) и при dormant
 * возвращают 1.0 БЕЗ запроса item_modifiers. `enabled()` обёрнут в try/catch → false при отсутствии
 * game_settings (тест-среда, напр. fixture-fence) → боевой путь не падает и остаётся byte-equivalent.
 */
final class ItemModifierService
{
    private GameSettingsService $settings;
    private ItemModifierModel $modifiers;
    private CharacterResourceModel $resources;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?ItemModifierModel $modifiers = null,
        ?CharacterResourceModel $resources = null
    ) {
        $this->settings  = $settings  ?? new GameSettingsService();
        $this->modifiers = $modifiers ?? new ItemModifierModel();
        $this->resources = $resources ?? new CharacterResourceModel();
    }

    /**
     * Killswitch. Default OFF = dormant. try/catch → false если game_settings недоступна
     * (тест-среда), чтобы боевой путь (getEquippedWeapon) не падал и оставался fence-safe.
     */
    public function enabled(): bool
    {
        try {
            $v = $this->settings->get('craft.modifier.enabled', false);
        } catch (\Throwable $e) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function goldCost(): int
    {
        return $this->intSetting('craft.modifier.gold_cost', 2000);
    }

    public function resourceName(): string
    {
        $v = $this->settings->get('craft.modifier.resource_name', 'Минералы');
        return is_string($v) ? trim($v) : 'Минералы';
    }

    public function resourceQty(): int
    {
        return $this->intSetting('craft.modifier.resource_qty', 5);
    }

    /** W20: шаг бонуса за одну модернизацию (тир). */
    public function bonusPct(): int
    {
        return max(1, $this->intSetting('craft.modifier.bonus_pct', 5));
    }

    /** Алиас bonusPct() — шаг за тир (W20 терминология). */
    public function bonusStep(): int
    {
        return $this->bonusPct();
    }

    /** W20: потолок накопленной модернизации (cap). */
    public function maxBonusPct(): int
    {
        return $this->intSetting('craft.modifier.max_bonus_pct', 25);
    }

    // ── E14 (ADR-114) — грандмастер-band (L40+, расширенный cap + редкий компонент-синк) ──

    /** Killswitch грандмастер-band. fence-safe (try/catch → false). default OFF. */
    public function grandmasterEnabled(): bool
    {
        try {
            $v = $this->settings->get('craft.modifier.grandmaster_enabled', false);
        } catch (\Throwable $e) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Минимальный уровень для грандмастер-тиров (гейт L40). */
    public function grandmasterLevel(): int
    {
        return $this->intSetting('craft.modifier.grandmaster_level', 40);
    }

    /** Расширенный потолок для L40+ (грандмастер). */
    public function grandmasterMaxBonusPct(): int
    {
        return $this->intSetting('craft.modifier.grandmaster_max_bonus_pct', 50);
    }

    /** Редкий компонент-синк для грандмастер-тиров. */
    public function grandmasterResourceName(): string
    {
        $v = $this->settings->get('craft.modifier.grandmaster_resource_name', 'Древние реликвии');
        return is_string($v) ? trim($v) : 'Древние реликвии';
    }

    /** Базовое кол-во редкого компонента за грандмастер-тир (×тир). */
    public function grandmasterResourceQty(): int
    {
        return $this->intSetting('craft.modifier.grandmaster_resource_qty', 2);
    }

    /** Множитель золото-цены грандмастер-тиров. */
    public function grandmasterGoldMult(): float
    {
        return $this->floatSetting('craft.modifier.grandmaster_gold_mult', 2.0);
    }

    /**
     * Эффективный потолок модернизации с учётом уровня: L40+ при killswitch ON → grandmaster cap,
     * иначе стандартный. При dormant возвращает стандартный для любого level → byte-identical.
     */
    public function effectiveCap(int $level): int
    {
        if ($this->grandmasterEnabled() && $level >= $this->grandmasterLevel()) {
            return max($this->maxBonusPct(), $this->grandmasterMaxBonusPct());
        }
        return $this->maxBonusPct();
    }

    /**
     * Стоимость одного тира: грандмастер-тир (новый bonus > стандартного cap) платит редким
     * компонентом + золото×grandmaster_gold_mult; обычный тир — Минералы + золото×тир.
     *
     * @return array{gold:int, resourceName:string, resourceQty:int, grandmaster:bool}
     */
    public function tierCost(int $newBonus, int $tier): array
    {
        if ($newBonus > $this->maxBonusPct()) {
            return [
                'gold'         => (int) round($this->goldCost() * $tier * $this->grandmasterGoldMult()),
                'resourceName' => $this->grandmasterResourceName(),
                'resourceQty'  => $this->grandmasterResourceQty() * $tier,
                'grandmaster'  => true,
            ];
        }
        return [
            'gold'         => $this->goldCost() * $tier,
            'resourceName' => $this->resourceName(),
            'resourceQty'  => $this->resourceQty() * $tier,
            'grandmaster'  => false,
        ];
    }

    /**
     * Боевой множитель урона оружия по экземпляру (characters_weapons.id).
     * 1.0 если cwId≤0 ИЛИ killswitch off (БЕЗ запроса item_modifiers) → fence-safe.
     */
    public function weaponDamageMultiplier(int $cwId): float
    {
        if ($cwId <= 0 || ! $this->enabled()) {
            return 1.0;
        }
        $pct = $this->modifierBonusPct('weapon', $cwId, 'damage');
        return 1.0 + ($pct / 100.0);
    }

    /**
     * W20: боевой множитель брони по экземпляру outfit (characters_outfits.id).
     * Бустит armor_value (PvE) и resistances (PvP). 1.0 при dormant/нет модификатора → fence-safe.
     */
    public function armorMultiplier(int $coId): float
    {
        if ($coId <= 0 || ! $this->enabled()) {
            return 1.0;
        }
        $pct = $this->modifierBonusPct('outfit', $coId, 'armor');
        return 1.0 + ($pct / 100.0);
    }

    /**
     * W20/E14: превью следующего тира для UI. $level — уровень персонажа (для грандмастер-cap).
     * Стоимость и компонент берутся из tierCost (грандмастер-тир → редкий компонент + золото×mult).
     *
     * @return array{current:int, next:int, tier:int, gold:int, resourceName:string, resourceQty:int, grandmaster:bool, atMax:bool, cap:int}
     */
    public function previewFor(string $itemType, int $instanceId, int $level = 0): array
    {
        $stat    = $itemType === 'weapon' ? 'damage' : 'armor';
        $current = $this->modifierBonusPct($itemType, $instanceId, $stat);
        $step    = $this->bonusStep();
        $cap     = $this->effectiveCap($level);
        $atMax   = $current >= $cap;
        $next    = $atMax ? $current : min($cap, $current + $step);
        $tier    = (int) ($next / $step);
        $cost    = $this->tierCost($next, max(1, $tier));
        return [
            'current'      => $current,
            'next'         => $next,
            'tier'         => $tier,
            'gold'         => $cost['gold'],
            'resourceName' => $cost['resourceName'],
            'resourceQty'  => $cost['resourceQty'],
            'grandmaster'  => $cost['grandmaster'],
            'atMax'        => $atMax,
            'cap'          => $cap,
        ];
    }

    /**
     * Текущий bonus_pct модификатора экземпляра (0 если нет / killswitch off).
     */
    public function modifierBonusPct(string $itemType, int $instanceId, string $stat): int
    {
        if ($instanceId <= 0 || ! $this->enabled()) {
            return 0;
        }
        $row = $this->modifiers->findForInstance($itemType, $instanceId);
        if ($row === null || ($row['stat'] ?? null) !== $stat) {
            return 0;
        }
        $raw = $row['bonus_pct'] ?? null;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Тир-таблица → [table, stat] по item_type. Неизвестный тип → [null, ''].
     *
     * @return array{0:?string, 1:string}
     */
    private function resolveType(string $itemType): array
    {
        if ($itemType === 'weapon') {
            return ['characters_weapons', 'damage'];
        }
        if ($itemType === 'outfit') {
            return ['characters_outfits', 'armor'];
        }
        return [null, ''];
    }

    /**
     * W20: модернизировать экземпляр на 1 тир (+step, cap). Оружие или броня. Цена ×тир.
     * Списывает gold + ресурс атомарно, инкрементит bonus_pct (не перезапись — накопление до cap).
     *
     * $level — уровень персонажа (E14: для грандмастер-cap; 0 = стандартный band).
     *
     * @return array{ok:bool, error:?string, bonus_pct:int, tier:int, grandmaster?:bool}
     */
    public function enchant(int $characterId, string $itemType, int $instanceId, int $level = 0): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'disabled', 'bonus_pct' => 0, 'tier' => 0];
        }
        [$table, $stat] = $this->resolveType($itemType);
        if ($table === null || $characterId <= 0 || $instanceId <= 0) {
            return ['ok' => false, 'error' => 'bad_input', 'bonus_pct' => 0, 'tier' => 0];
        }

        $db = Database::connect();

        // Экземпляр должен принадлежать персонажу и быть экипирован.
        $q   = $db->table($table)
            ->where('id', $instanceId)
            ->where('character_id', $characterId)
            ->where('equipped', 1)
            ->get();
        $row = $q === false ? null : $q->getRowArray();
        if (! is_array($row)) {
            return ['ok' => false, 'error' => 'not_equipped', 'bonus_pct' => 0, 'tier' => 0];
        }

        // Тир-расчёт: накопление до cap (E14: cap зависит от уровня — грандмастер для L40+).
        $current = $this->modifierBonusPct($itemType, $instanceId, $stat);
        $step    = $this->bonusStep();
        $cap     = $this->effectiveCap($level);
        if ($current >= $cap) {
            return ['ok' => false, 'error' => 'max_tier', 'bonus_pct' => $current, 'tier' => (int) ($current / $step)];
        }
        $newBonus = min($cap, $current + $step);
        $tier     = (int) ($newBonus / $step);

        // E14: грандмастер-тир (>стандартный cap) → редкий компонент + золото×mult.
        $cost     = $this->tierCost($newBonus, $tier);
        $goldCost = $cost['gold'];
        $resName  = $cost['resourceName'];
        $resQty   = $cost['resourceQty'];

        // Проверка золота.
        $charQ   = $db->table('characters')->select('gold')->where('id', $characterId)->get();
        $charRow = $charQ === false ? null : $charQ->getRowArray();
        $gold    = is_array($charRow) && is_numeric($charRow['gold'] ?? null) ? (int) $charRow['gold'] : 0;
        if ($gold < $goldCost) {
            return ['ok' => false, 'error' => 'no_gold', 'bonus_pct' => $current, 'tier' => $tier];
        }

        // Проверка ресурса (если требуется).
        $needResource = $resName !== '' && $resQty > 0;
        if ($needResource) {
            $resRow = $this->resources->getResourceForCraft($resName, $characterId);
            $have   = is_array($resRow) && is_numeric($resRow['charResQty'] ?? null) ? (int) $resRow['charResQty'] : 0;
            if ($have < $resQty) {
                return ['ok' => false, 'error' => 'no_resource', 'bonus_pct' => $current, 'tier' => $tier];
            }
        }

        $db->transStart();

        $db->table('characters')->where('id', $characterId)->set('gold', "gold - {$goldCost}", false)->update();

        if ($needResource) {
            $okRes = $this->resources->deductResourceForCraft($resName, $characterId, $resQty);
            if ($okRes !== true) {
                $db->transRollback();
                return ['ok' => false, 'error' => 'no_resource', 'bonus_pct' => $current, 'tier' => $tier];
            }
        }

        // Upsert: инкремент bonus_pct до нового тира (1 строка на экземпляр).
        $existing = $this->modifiers->findForInstance($itemType, $instanceId);
        if (is_array($existing) && is_numeric($existing['id'] ?? null)) {
            $this->modifiers->update((int) $existing['id'], ['bonus_pct' => $newBonus, 'stat' => $stat]);
        } else {
            $this->modifiers->insert([
                'character_id'     => $characterId,
                'item_type'        => $itemType,
                'item_instance_id' => $instanceId,
                'stat'             => $stat,
                'bonus_pct'        => $newBonus,
            ]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['ok' => false, 'error' => 'tx_failed', 'bonus_pct' => $current, 'tier' => $tier];
        }

        return ['ok' => true, 'error' => null, 'bonus_pct' => $newBonus, 'tier' => $tier, 'grandmaster' => $cost['grandmaster']];
    }

    private function intSetting(string $key, int $default): int
    {
        try {
            $v = $this->settings->get($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
        return is_numeric($v) ? (int) $v : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        try {
            $v = $this->settings->get($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
        return is_numeric($v) ? (float) $v : $default;
    }
}
