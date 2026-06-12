<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * E17 Ф2 (ADR-117) — персональные мини-события в «Походе» для L25+ (реюз encounter-инфры ADR-089).
 *
 * Во время марша с шансом `world.march_events.chance_per_cell` (default 0 = dormant), если игрок
 * L25+ (`world.march_events.min_level`) и включён killswitch `world.march_events.enabled`, поход
 * прерывается мини-событием (pauseMarch reason=mini_event). Игрок выбирает «🔍 Осмотреть» (→ модест
 * generic-находка) или «🚶 Пройти мимо» (march_resume, без награды).
 *
 * Тройной гейт (enabled И level≥min И rng<chance) с default chance 0 → байт-идентично (fixture-fence,
 * ADR-014: ни одна march-фикстура не трогается). Находки — только generic mid-tier ресурсы (НЕ золото,
 * НЕ поясное rarity-1) → не faucet (эконом-инвариант П8; для L25+ с 10-16M это флавор, не экономика).
 */
final class MarchMiniEventService
{
    private GameSettingsService $settings;
    private ResourceModel $resources;

    /**
     * Каталог мини-событий. key → {icon, title, lore, prompt, find: [name_en => [min,max]]}.
     * Находки модест-generic (mid-tier). НЕ золото / НЕ поясное rarity-1.
     *
     * @var array<string, array{icon:string, title:string, lore:string, prompt:string, find:array<string,array{int,int}>}>
     */
    private const CATALOG = [
        'abandoned_cache' => [
            'icon'   => '🎒',
            'title'  => 'Заброшенный схрон',
            'lore'   => 'У приметного валуна — присыпанный землёй тайник. Кто-то прятал, да не вернулся.',
            'prompt' => 'Разрыть или оставить как есть?',
            'find'   => ['RareMetals' => [2, 4], 'Oil' => [1, 3]],
        ],
        'wrecked_vehicle' => [
            'icon'   => '🚗',
            'title'  => 'Остов машины',
            'lore'   => 'Ржавый кузов давно встал на обочине бытия. В моторном отсеке ещё есть чем поживиться.',
            'prompt' => 'Покопаться в железе?',
            'find'   => ['Ironstone' => [3, 6], 'Stones' => [2, 4]],
        ],
        'old_supply_drop' => [
            'icon'   => '📦',
            'title'  => 'Старый сброс припасов',
            'lore'   => 'Полусгнивший парашют, под ним — лопнувший контейнер. Большую часть растащили, но не всё.',
            'prompt' => 'Проверить, что осталось?',
            'find'   => ['Wood' => [3, 6], 'Stones' => [3, 6]],
        ],
        'buried_stash' => [
            'icon'   => '⚙️',
            'title'  => 'Закопанный тайник',
            'lore'   => 'Свежая земля горбится холмиком — кто-то закопал тут что-то ценное совсем недавно.',
            'prompt' => 'Раскопать?',
            'find'   => ['RareMetals' => [2, 3], 'Sulfur' => [2, 4]],
        ],
    ];

    public function __construct(?GameSettingsService $settings = null, ?ResourceModel $resources = null)
    {
        $this->settings  = $settings ?? new GameSettingsService();
        $this->resources = $resources ?? new ResourceModel();
    }

    /** Killswitch `world.march_events.enabled` (default false → dormant). */
    public function enabled(): bool
    {
        $v = $this->settings->get('world.march_events.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Минимальный уровень для мини-событий (default 25). */
    public function minLevel(): int
    {
        $v = $this->settings->get('world.march_events.min_level', 25);

        return is_numeric($v) ? max(1, (int) $v) : 25;
    }

    /** Шанс мини-события на клетку (0..1, default 0 = dormant). */
    public function chancePerCell(): float
    {
        $v = $this->settings->get('world.march_events.chance_per_cell', 0.0);
        if (! is_numeric($v)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $v));
    }

    /** Доступны ли мини-события игроку уровня $level (killswitch + порог уровня). */
    public function eligible(int $level): bool
    {
        return $this->enabled() && $level >= $this->minLevel();
    }

    /**
     * Прошёл ли per-cell RNG-ролл. Вызывать ТОЛЬКО после eligible() (иначе mt_rand зря).
     * chance 0 → всегда false (dormant, fixture-fence).
     */
    public function roll(): bool
    {
        $chance = $this->chancePerCell();
        if ($chance <= 0.0) {
            return false;
        }

        return mt_rand(1, 10000) / 10000.0 < $chance;
    }

    /** Случайный ключ мини-события из каталога. */
    public function pickKey(): string
    {
        $keys = array_keys(self::CATALOG);

        return $keys[array_rand($keys)];
    }

    /** Существует ли ключ в каталоге. */
    public function has(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }

    /**
     * Карточка мини-события (для prompt в pauseMarch).
     *
     * @return array{icon:string, title:string, lore:string, prompt:string}|null
     */
    public function card(string $key): ?array
    {
        $e = self::CATALOG[$key] ?? null;
        if ($e === null) {
            return null;
        }

        return ['icon' => $e['icon'], 'title' => $e['title'], 'lore' => $e['lore'], 'prompt' => $e['prompt']];
    }

    /**
     * Применить исход «осмотреть»: начислить находку. Возвращает выданное + заголовок.
     *
     * @return array{ok:bool, icon:string, title:string, awarded:array<string,int>}
     */
    public function investigate(string $key, int $characterId): array
    {
        $e = self::CATALOG[$key] ?? null;
        if ($e === null) {
            return ['ok' => false, 'icon' => '🔍', 'title' => 'Ничего', 'awarded' => []];
        }

        $awarded = [];
        foreach ($e['find'] as $nameEn => $range) {
            $amount = random_int($range[0], $range[1]); // каталог: min ≥ 1 → amount ≥ 1
            if ($this->resources->addOrIncreaseResource($characterId, $nameEn, $amount) !== false) {
                $awarded[$nameEn] = $amount;
            }
        }

        return ['ok' => true, 'icon' => $e['icon'], 'title' => $e['title'], 'awarded' => $awarded];
    }
}
