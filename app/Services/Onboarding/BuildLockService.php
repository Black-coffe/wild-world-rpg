<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Buildings;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — прогрессивное раскопытие построек (спина-слайс 2).
 *
 * Проблема (audit-агент 3): `BuildListAction` показывает L1-новичку ВСЕ 15 зданий, включая
 * Арсенал L15 / Робомастерскую L10 / Телепорт L12, БЕЗ lock-state — гейт уровня срабатывает
 * только ВНУТРИ preview («уровень слишком низкий»), нарушая UX-Discoverability (CLAUDE.md) и
 * засоряя новичка. Слайс 2 расширяет уже-образцовый lock-button паттерн (как Фракция L10,
 * `ChooseFactionLockedAction`) на список построек: ниже-уровневые здания → «🔒 … (с lvl X)»
 * + alert с prerequisite, вместо кнопки-обманки, которая отдаст ошибку.
 *
 * Гейт — суб-killswitch `onboarding.cold_open_v2.build_locks` (default OFF → dormant =
 * BuildListAction рендерит как раньше, byte-identical). ОТДЕЛЬНЫЙ от мастер-флага (1a) и
 * greeting-флага (1b): каждый слайс спины активируется независимо после своего Tier-3.
 *
 * Источник уровней — {@see \Config\Buildings} `level_required` (единый источник, тот же, что
 * у preview-гейта `GenericBuildingInfoAction` → нет дрейфа). read-only.
 */
class BuildLockService
{
    use GameSettingsReaderTrait;

    /** Суб-killswitch S4-2: lock-кнопки уровневых построек в списке. Default OFF → dormant. */
    public function enabled(): bool
    {
        return $this->gsBool('onboarding.cold_open_v2.build_locks', false);
    }

    /** Минимальный уровень постройки по ключу Config\Buildings (1, если ключ неизвестен). */
    public function requiredLevel(string $key): int
    {
        $recipe = $this->recipe($key);
        $lvl = is_array($recipe) && isset($recipe['level_required']) && is_numeric($recipe['level_required'])
            ? (int) $recipe['level_required']
            : 1;

        return max(1, $lvl);
    }

    /** Заблокирована ли постройка для игрока (ТОЛЬКО при killswitch ON И уровень ниже нужного). */
    public function isLocked(string $key, int $level): bool
    {
        return $this->enabled() && $this->requiredLevel($key) > $level;
    }

    /** Подпись lock-кнопки: «🔒 <отображаемое имя> (с lvl X)». */
    public function lockLabel(string $key, string $displayName): string
    {
        return "🔒 {$displayName} (с lvl {$this->requiredLevel($key)})";
    }

    /**
     * Alert-текст по клику на lock-кнопку (≤200 симв, Markdown НЕ рендерится → плоский текст):
     * нужный уровень + сколько осталось + краткая польза постройки + как качаться.
     */
    public function lockAlert(string $key, int $level): string
    {
        $recipe = $this->recipe($key);
        if ($recipe === null) {
            return 'Здание недоступно.';
        }

        $req  = $this->requiredLevel($key);
        $name = isset($recipe['name_rus']) && is_string($recipe['name_rus']) ? $recipe['name_rus'] : $key;
        $info = isset($recipe['info_text']) && is_string($recipe['info_text']) ? $recipe['info_text'] : '';
        $left = max(0, $req - $level);

        // Краткий тизер пользы — первое предложение info_text, обрезанное.
        $teaser = trim(explode('.', $info)[0]);
        if (mb_strlen($teaser) > 70) {
            $teaser = mb_substr($teaser, 0, 67) . '…';
        }

        $msg = "🔒 {$name} — нужен ур. {$req} (у тебя {$level}, осталось {$left}).";
        if ($teaser !== '') {
            $msg .= " {$teaser}.";
        }
        $msg .= ' Растёт за разведку, добычу, крафт, бой.';

        return mb_substr($msg, 0, 200);
    }

    // ── Overridable seam (тест подменяет конфиг при желании) ─────────────────

    /**
     * Рецепт постройки из Config\Buildings (или null).
     *
     * @return array<string, mixed>|null
     */
    protected function recipe(string $key): ?array
    {
        return config(Buildings::class)->get($key);
    }
}
