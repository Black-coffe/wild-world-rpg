<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * V16 (ADR-047) — крафт-специализация персонажа (Workshop specialization).
 *
 * Персонаж выбирает ветку (weaponsmith/medic/engineer); крафт «своей» категории
 * (по `zone_name` рецепта) идёт быстрее. V16 — базовый перк (один множитель на все
 * ветки); V17 углубит (per-branch/per-tier).
 *
 * Чистый GameSettings-reader (зеркало FoodBuffService): множитель и политика respec
 * live-tunable (category=craft). Состояние — `characters.specialization` +
 * `characters.specialization_changed_at`.
 */
final class SpecializationService
{
    /**
     * Ветки специализации. zones — значения `CraftRecipes.zone_name`, попадающие
     * под бонус ветки.
     *
     * @var array<string, array{label:string, emoji:string, zones:list<string>, desc:string}>
     */
    private const BRANCHES = [
        'weaponsmith' => [
            'label' => 'Оружейник',
            'emoji' => '🔨',
            'zones' => ['оружие'],
            'desc'  => 'Крафт оружия идёт быстрее.',
        ],
        'medic' => [
            'label' => 'Медик',
            'emoji' => '💊',
            'zones' => ['медицина'],
            'desc'  => 'Крафт медицины идёт быстрее.',
        ],
        'engineer' => [
            'label' => 'Инженер',
            'emoji' => '🔧',
            'zones' => ['производство', 'защита', 'инструменты'],
            'desc'  => 'Крафт ресурсов, брони и инструментов идёт быстрее.',
        ],
    ];

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch слоя специализаций. */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('specialization.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Минимальный уровень для выбора специализации. */
    public function minLevel(): int
    {
        return max(1, $this->intSetting('specialization.min_level', 5));
    }

    /** Множитель времени крафта матч-ветки (<1.0 = быстрее). */
    public function craftTimeMultiplier(): float
    {
        $v = $this->floatSetting('specialization.craft_time_multiplier', 0.90);
        return $v > 0 ? $v : 1.0;
    }

    /** Стоимость смены ветки (золото); первый выбор — бесплатно. */
    public function respecCostGold(): int
    {
        return max(0, $this->intSetting('specialization.respec_cost_gold', 5000));
    }

    /** Кулдаун смены ветки (дней). */
    public function respecCooldownDays(): int
    {
        return max(0, $this->intSetting('specialization.respec_cooldown_days', 7));
    }

    /**
     * Определения веток (для UI).
     *
     * @return array<string, array{label:string, emoji:string, zones:list<string>, desc:string}>
     */
    public function branches(): array
    {
        return self::BRANCHES;
    }

    /** Валидный ли ключ ветки. */
    public function isValidBranch(string $branch): bool
    {
        return isset(self::BRANCHES[$branch]);
    }

    /** Человекочитаемый лейбл ветки (с эмодзи) или «не выбрана». */
    public function labelFor(?string $branch): string
    {
        if ($branch !== null && isset(self::BRANCHES[$branch])) {
            return self::BRANCHES[$branch]['emoji'] . ' ' . self::BRANCHES[$branch]['label'];
        }
        return 'не выбрана';
    }

    /** Ветка, под которую попадает зона крафта, или null. */
    public function branchForZone(string $zoneName): ?string
    {
        if ($zoneName === '') {
            return null;
        }
        foreach (self::BRANCHES as $key => $def) {
            if (in_array($zoneName, $def['zones'], true)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Ветка, под которую попадает рецепт (по его zone_name), или null.
     *
     * @param array<array-key,mixed> $recipe
     */
    public function branchForRecipe(array $recipe): ?string
    {
        $zone = $recipe['zone_name'] ?? '';
        return is_string($zone) ? $this->branchForZone($zone) : null;
    }

    /**
     * Множитель времени крафта с учётом специализации персонажа.
     * 1.0 (no-op), если: killswitch off / нет ветки у персонажа / зона рецепта не
     * маппится / ветка ≠ матч. Множитель — только когда ветка совпадает с зоной рецепта.
     *
     * @param array<array-key,mixed> $recipe
     */
    public function getCraftTimeMultiplierFor(?string $specialization, array $recipe): float
    {
        if (! $this->isEnabled()) {
            return 1.0;
        }
        if ($specialization === null || ! $this->isValidBranch($specialization)) {
            return 1.0;
        }
        $branch = $this->branchForRecipe($recipe);
        if ($branch === null) {
            return 1.0;
        }
        return $specialization === $branch ? $this->craftTimeMultiplier() : 1.0;
    }

    /**
     * Можно ли сменить ветку сейчас (кулдаун). null changed_at (первый выбор) → true.
     */
    public function canChangeNow(mixed $changedAt, ?int $nowTs = null): bool
    {
        if (! is_string($changedAt) || $changedAt === '') {
            return true;
        }
        $ts = strtotime($changedAt);
        if ($ts === false) {
            return true;
        }
        return ($nowTs ?? time()) >= $ts + $this->respecCooldownDays() * 86400;
    }

    /**
     * Когда можно будет сменить ветку (timestamp) или null, если уже можно.
     */
    public function nextChangeTs(mixed $changedAt): ?int
    {
        if (! is_string($changedAt) || $changedAt === '') {
            return null;
        }
        $ts = strtotime($changedAt);
        if ($ts === false) {
            return null;
        }
        $next = $ts + $this->respecCooldownDays() * 86400;
        return $next > time() ? $next : null;
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }
}
