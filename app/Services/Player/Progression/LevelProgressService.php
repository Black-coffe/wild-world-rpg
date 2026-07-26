<?php

declare(strict_types=1);

namespace App\Services\Player\Progression;

use App\Entities\CharacterEntity;
use App\Services\GameSettings\GameSettingsService;

/**
 * Слайс «Видимая лестница L1→L10» (замер прода 2026-07-26) — расчёт и рендер прогресса
 * персонажа к следующему уровню.
 *
 * Проблема (прод-замер новичков за 28 дней, n=104): вовлечённость есть — 33 человека
 * сделали 20+ действий, 22 крафтили, 20 ставили лагерь, — но 95 из 104 всё ещё на L1.
 * Уровень производный: level = max(1, floor((experience + strength + agility + intellect) / 4))
 * ({@see \App\TaskHandlers\HealthRegenerationHandler::calculateLevel()}), то есть до L2 нужна
 * сумма четырёх статов 8.0 — это ~80 шагов по карте (+0.03 exp +0.02 str за шаг, ×2 по ADR-138)
 * или ~13 завершённых добыч (+0.6 exp каждая). Живой пример с прода: игрок `lynxtux` — 292
 * действия, сумма 5.48, то есть 68% пути до L2, и всё это время экран показывал ему «Уровень: 1»
 * и четыре сырых числа, ни одно из которых не говорит, сколько осталось.
 *
 * Сервис даёт ЧЕСТНЫЙ прогресс: сколько набрано из скольких, сколько осталось, из чего вообще
 * складывается уровень. Read-only — НИКАКИХ мутаций статов (баланс не трогаем, ADR-138 остаётся
 * единственным рычагом скорости).
 *
 * Гейт: killswitch `progression.ladder.enabled` (default false → dormant, cardLine() = null →
 * карточка byte-identical). Тест-сим: 2-й аргумент конструктора $overrides (map key→value)
 * читается вместо GameSettings — чистый unit-тест без БД (паттерн EarlyProgressionService).
 *
 * Самодостаточен в тексте (media-off, ADR-020): строка несёт числа, а не картинку прогресса.
 *
 * @see \App\Services\Player\Progression\LevelUnlockService что откроется на следующем уровне
 * @see \App\Database\Migrations\LevelLadderGameSettings
 */
final class LevelProgressService
{
    /** Статы, сумма которых делится на 4 и даёт уровень (порядок = порядок в формуле). */
    public const STAT_FIELDS = ['experience', 'strength', 'agility', 'intellect'];

    /** Делитель формулы уровня. Меняется ТОЛЬКО вместе с HealthRegenerationHandler::calculateLevel(). */
    private const DIVISOR = 4.0;

    /** Длина текстового прогресс-бара в символах. */
    private const BAR_CELLS = 10;

    private ?GameSettingsService $settings;

    /** @var array<string, mixed>|null тест-оверрайды (минуя GameSettings). */
    private ?array $overrides;

    /**
     * @param array<string, mixed>|null $overrides
     */
    public function __construct(?GameSettingsService $settings = null, ?array $overrides = null)
    {
        $this->settings  = $settings;
        $this->overrides = $overrides;
    }

    /** Killswitch слайса: показывать лестницу. Default false → dormant. */
    public function isEnabled(): bool
    {
        return $this->toBool($this->rawSetting('progression.ladder.enabled', false));
    }

    // ── чистая арифметика лестницы (без БД, без настроек) ────────────────

    /**
     * Сумма четырёх статов формулы уровня. Нечисловые/отсутствующие поля = 0.0
     * (деградация вместо падения: карточка не обязана падать из-за кривой строки).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public static function statSum(array|CharacterEntity $character): float
    {
        $sum = 0.0;
        foreach (self::STAT_FIELDS as $field) {
            $raw = $character[$field] ?? null;
            $sum += is_numeric($raw) ? (float) $raw : 0.0;
        }

        return $sum;
    }

    /**
     * Уровень по сумме статов — ТОЧНАЯ копия формулы крона (иначе строка прогресса
     * противоречила бы числу «Уровень» в той же карточке в течение минуты до пересчёта).
     */
    public static function levelForSum(float $sum): int
    {
        return max(1, (int) floor($sum / self::DIVISOR));
    }

    /**
     * Границы полосы «текущий уровень → следующий» в единицах суммы статов.
     *
     * L1 — особый случай: floor(sum/4) даёт 0 при sum<4, но уровень поднят до 1 (max),
     * поэтому вся полоса L1→L2 считается от НУЛЯ до 8.0. Иначе бар прыгал бы назад
     * на sum=4.0 (97% → 0%) — прогресс обязан быть монотонным.
     *
     * @return array{0: float, 1: float} [начало полосы, сумма для следующего уровня]
     */
    public static function spanFor(int $level): array
    {
        $need = ($level + 1) * self::DIVISOR;
        $base = $level <= 1 ? 0.0 : $level * self::DIVISOR;

        return [$base, $need];
    }

    /**
     * Прогресс к следующему уровню в процентах (0..99). 100% недостижимы: на полной
     * полосе уровень уже поднялся бы — потолок 99 честнее, чем «100%, а уровень прежний».
     */
    public static function percentToNext(float $sum): int
    {
        $level         = self::levelForSum($sum);
        [$base, $need] = self::spanFor($level);
        $span          = $need - $base;
        if ($span <= 0.0) {
            return 0;
        }
        $pct = (int) floor((($sum - $base) / $span) * 100);

        return max(0, min(99, $pct));
    }

    /** Сколько суммы статов осталось набрать до следующего уровня (никогда < 0). */
    public static function remainingToNext(float $sum): float
    {
        [, $need] = self::spanFor(self::levelForSum($sum));

        return max(0.0, $need - $sum);
    }

    /** Текстовый бар из BAR_CELLS ячеек. Самодостаточен рядом с числом процентов. */
    public static function bar(int $percent): string
    {
        $filled = (int) floor(max(0, min(100, $percent)) / (100 / self::BAR_CELLS));
        $filled = max(0, min(self::BAR_CELLS, $filled));

        return str_repeat('▰', $filled) . str_repeat('▱', self::BAR_CELLS - $filled);
    }

    // ── рендер ──────────────────────────────────────────────────────────

    /**
     * Строка лестницы для карточки Персонажа, или null при выключенном killswitch
     * (dormant = карточка byte-identical).
     *
     * Формат (Markdown legacy — парные `*`, без `_`: memory feedback_legacy_markdown_no_backslash_escape):
     *   🪜 *До уровня 2:* ▰▰▰▰▰▰▱▱▱▱ 68% — набрано 5.5 из 8.0
     *
     * Вторая строка («из чего складывается уровень» + что растит быстрее) показывается только
     * новичку в ранней полосе ADR-138 — там XP за добычу реально начисляется, и подсказка ПРАВДА.
     * Ветерану (level >= progression.early.level_cap) добыча опыта не даёт → обещание было бы ложью.
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function cardLine(array|CharacterEntity $character): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $sum     = self::statSum($character);
        $level   = self::levelForSum($sum);
        $percent = self::percentToNext($sum);
        [, $need] = self::spanFor($level);

        $line = sprintf(
            '🪜 *До уровня %d:* %s %d%% — набрано %.1f из %.1f',
            $level + 1,
            self::bar($percent),
            $percent,
            $sum,
            $need
        );

        $hint = $this->hintLine($level);

        return $hint === null ? $line : $line . "\n" . $hint;
    }

    /**
     * Подсказка «из чего складывается уровень» — только для новичка в ранней полосе,
     * где XP за добычу действительно начисляется (иначе совет был бы ложным).
     */
    private function hintLine(int $level): ?string
    {
        $early = new EarlyProgressionService($this->settings, $this->overrides);
        if (! $early->isEarly($level) || $early->gatherXpEarned($level) <= 0.0) {
            return null;
        }

        return '   ↳ уровень = (опыт + сила + ловкость + интеллект) ÷ 4; '
            . 'больше всего за раз даёт завершённая добыча ресурсов';
    }

    // ── internals ───────────────────────────────────────────────────────

    private function rawSetting(string $key, mixed $default): mixed
    {
        if ($this->overrides !== null) {
            return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : $default;
        }
        $this->settings ??= new GameSettingsService();

        return $this->settings->get($key, $default);
    }

    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
