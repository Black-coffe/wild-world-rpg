<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Services\GameSettings\GameSettingsService;

/**
 * S28 (ADR-032) — сезонная ротация крафта.
 *
 * Активный сезон — детерминированная функция от anchor-даты и цикла (GameSettings),
 * без DB-состояния. Cron нужен только для анонса перехода (SeasonalTransitionHandler).
 *
 * Порядок: Зима→Весна→Лето→Осень. anchor подобран так, что на запуске активна Зима
 * (живой pilot). `seasonal.enabled=false` → активного сезона нет (фича off).
 *
 * NB: craft-сезон ≠ endgame leaderboard-season (SeasonResetService). Разные домены.
 */
final class SeasonalCraftService
{
    /** @var list<string> порядок сезонов в цикле (index 0..n-1). */
    public const SEASON_ORDER = ['winter', 'spring', 'summer', 'autumn'];

    /** @var array<string,string> ru-метки сезонов. */
    public const SEASON_LABELS = [
        'winter' => 'Зима выживания',
        'spring' => 'Весеннее пробуждение',
        'summer' => 'Летняя жара',
        'autumn' => 'Осенняя жатва',
    ];

    /**
     * Рецепты по сезонам (recipe-keys из Config\CraftRecipes). Зима — pilot S28,
     * Весна — V1 (vNext); summer/autumn — V2/V3.
     *
     * @var array<string, list<string>>
     */
    public const SEASON_RECIPES = [
        'winter' => [
            'WinterHerbalBrew',
            'WinterHoneyMead',
            'WinterWarmingBalm',
            'WinterCampStew',
            'WinterPreserves',
        ],
        'spring' => [
            'SpringFirstHerbTea',
            'SpringBirchSap',
            'SpringPrimroseInfusion',
            'SpringWildGreens',
            'SpringShootsDecoction',
        ],
        'summer' => [],
        'autumn' => [],
    ];

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * Ключ активного сезона ('winter'|...), либо '' если фича выключена /
     * индекс вне определённых сезонов.
     */
    public function getActiveSeasonKey(?int $nowTs = null): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $dur   = max(1, $this->intSetting('seasonal.season_duration_days', 21));
        $count = max(1, $this->intSetting('seasonal.season_count', 4));
        $cycle = $dur * $count;

        $anchor = strtotime($this->strSetting('seasonal.cycle_anchor_date', '2026-05-20') . ' 00:00:00');
        if ($anchor === false) {
            $anchor = (int) strtotime('2026-05-20 00:00:00');
        }

        $now  = $nowTs ?? time();
        $days = (int) floor(($now - $anchor) / 86400);
        $pos  = (($days % $cycle) + $cycle) % $cycle; // 0..cycle-1, безопасно для отрицательных
        $idx  = intdiv($pos, $dur);                   // 0..count-1

        return self::SEASON_ORDER[$idx] ?? '';
    }

    public function isSeasonActive(string $seasonKey, ?int $nowTs = null): bool
    {
        return $seasonKey !== '' && $this->getActiveSeasonKey($nowTs) === $seasonKey;
    }

    public function getActiveSeasonLabel(?int $nowTs = null): string
    {
        return $this->getSeasonLabel($this->getActiveSeasonKey($nowTs));
    }

    public function getSeasonLabel(string $seasonKey): string
    {
        return self::SEASON_LABELS[$seasonKey] ?? '';
    }

    /**
     * Сколько дней (включая сегодня) осталось в текущем сезоне. 0 если нет сезона.
     */
    public function daysRemainingInSeason(?int $nowTs = null): int
    {
        if ($this->getActiveSeasonKey($nowTs) === '') {
            return 0;
        }
        $dur    = max(1, $this->intSetting('seasonal.season_duration_days', 21));
        $count  = max(1, $this->intSetting('seasonal.season_count', 4));
        $cycle  = $dur * $count;
        $anchor = strtotime($this->strSetting('seasonal.cycle_anchor_date', '2026-05-20') . ' 00:00:00');
        if ($anchor === false) {
            $anchor = (int) strtotime('2026-05-20 00:00:00');
        }
        $now  = $nowTs ?? time();
        $days = (int) floor(($now - $anchor) / 86400);
        $pos  = (($days % $cycle) + $cycle) % $cycle;
        $into = $pos % $dur; // 0-based день внутри сезона
        return $dur - $into;
    }

    /**
     * Recipe-keys для активного сезона (для меню). Пустой массив если нет сезона.
     *
     * @return list<string>
     */
    public function getRecipeKeysForActiveSeason(?int $nowTs = null): array
    {
        return $this->getRecipeKeysForSeason($this->getActiveSeasonKey($nowTs));
    }

    /**
     * @return list<string>
     */
    public function getRecipeKeysForSeason(string $seasonKey): array
    {
        return self::SEASON_RECIPES[$seasonKey] ?? [];
    }

    public function isEnabled(): bool
    {
        $v = $this->settings->get('seasonal.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function recipeCountPerSeason(): int
    {
        return max(0, $this->intSetting('seasonal.recipe_unlock_count_per_season', 5));
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private function strSetting(string $key, string $default): string
    {
        $v = $this->settings->get($key, $default);
        return is_string($v) && $v !== '' ? $v : $default;
    }
}
