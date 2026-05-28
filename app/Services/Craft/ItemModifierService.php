<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Models\CharacterResourceModel;
use App\Models\ItemModifierModel;
use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * W19 (ADR-074) — зачарование экземпляров предметов: детерминированный +X% к стату.
 *
 * Foundation: item_type='weapon', stat='damage'. Per-instance (item_instance_id = characters_weapons.id).
 *
 * RNG-fence-safe: `weaponDamageMultiplier` детерминирован (0 mt_rand) и при dormant возвращает 1.0
 * БЕЗ запроса item_modifiers. `enabled()` обёрнут в try/catch → false при отсутствии game_settings
 * (тест-среда, напр. fixture-fence) → боевой путь не падает и остаётся byte-equivalent.
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

    public function bonusPct(): int
    {
        return $this->intSetting('craft.modifier.bonus_pct', 5);
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
     * Наложить/перезаписать модификатор урона на экземпляр оружия персонажа.
     * Списывает gold + ресурс атомарно. Возвращает ['ok'=>bool, 'error'=>?string, 'bonus_pct'=>int].
     *
     * @return array{ok:bool, error:?string, bonus_pct:int}
     */
    public function enchant(int $characterId, int $cwId): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'disabled', 'bonus_pct' => 0];
        }
        if ($characterId <= 0 || $cwId <= 0) {
            return ['ok' => false, 'error' => 'bad_input', 'bonus_pct' => 0];
        }

        $db = Database::connect();

        // Экипированное оружие должно принадлежать персонажу.
        $cwQ = $db->table('characters_weapons')
            ->where('id', $cwId)
            ->where('character_id', $characterId)
            ->where('equipped', 1)
            ->get();
        $cw  = $cwQ === false ? null : $cwQ->getRowArray();
        if (! is_array($cw)) {
            return ['ok' => false, 'error' => 'not_equipped', 'bonus_pct' => 0];
        }

        $goldCost = $this->goldCost();
        $resName  = $this->resourceName();
        $resQty   = $this->resourceQty();

        // Проверка золота.
        $charQ   = $db->table('characters')->select('gold')->where('id', $characterId)->get();
        $charRow = $charQ === false ? null : $charQ->getRowArray();
        $gold    = is_array($charRow) && is_numeric($charRow['gold'] ?? null) ? (int) $charRow['gold'] : 0;
        if ($gold < $goldCost) {
            return ['ok' => false, 'error' => 'no_gold', 'bonus_pct' => 0];
        }

        // Проверка ресурса (если требуется).
        $needResource = $resName !== '' && $resQty > 0;
        if ($needResource) {
            $resRow = $this->resources->getResourceForCraft($resName, $characterId);
            $have   = is_array($resRow) && is_numeric($resRow['charResQty'] ?? null) ? (int) $resRow['charResQty'] : 0;
            if ($have < $resQty) {
                return ['ok' => false, 'error' => 'no_resource', 'bonus_pct' => 0];
            }
        }

        $bonus = $this->bonusPct();

        $db->transStart();

        // Списываем золото (атомарно через выражение).
        $db->table('characters')->where('id', $characterId)->set('gold', "gold - {$goldCost}", false)->update();

        // Списываем ресурс.
        if ($needResource) {
            $okRes = $this->resources->deductResourceForCraft($resName, $characterId, $resQty);
            if ($okRes !== true) {
                $db->transRollback();
                return ['ok' => false, 'error' => 'no_resource', 'bonus_pct' => 0];
            }
        }

        // Upsert модификатора (1 на экземпляр → перезапись).
        $existing = $this->modifiers->findForInstance('weapon', $cwId);
        if (is_array($existing) && is_numeric($existing['id'] ?? null)) {
            $this->modifiers->update((int) $existing['id'], ['bonus_pct' => $bonus, 'stat' => 'damage']);
        } else {
            $this->modifiers->insert([
                'character_id'     => $characterId,
                'item_type'        => 'weapon',
                'item_instance_id' => $cwId,
                'stat'             => 'damage',
                'bonus_pct'        => $bonus,
            ]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['ok' => false, 'error' => 'tx_failed', 'bonus_pct' => 0];
        }

        return ['ok' => true, 'error' => null, 'bonus_pct' => $bonus];
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
}
