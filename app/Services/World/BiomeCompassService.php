<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;
use Throwable;

/**
 * Компас биомов (2026-07-22) — отвечает на вопрос игрока «а где найти вулканы?».
 *
 * Живой сигнал из чата 22.07.2026. В игре было «что означает значок 🌋» (легенда) и «в
 * вулканах есть нефть» (гайд/советы), но НИГДЕ — «где эти вулканы находятся». Единственным
 * каналом ответа был чат с владельцем. При этом география объективно существует и её можно
 * назвать честно: вулканических клеток ~4 тыс. из миллиона (0.4%), и 92% из них — в
 * северо-восточной четверти острова.
 *
 * Что считаем и почему именно так:
 *  - Карта статична (`map` = KEEP в WipeManifest, мир не перегенерируется), поэтому
 *    раскладка биомов по крупной сетке считается ОДНИМ агрегатным запросом и кэшируется.
 *    Иначе честный «ближайший вулкан» стоил бы скана ~1 млн строк на каждое открытие легенды.
 *  - Сетка крупная ({@see self::BLOCK} клеток в блоке) СПЕЦИАЛЬНО: это компас, а не GPS.
 *    Игрок получает сторону света и порядок расстояния, а не координаты конкретной клетки —
 *    разведка остаётся разведкой (туман войны не раскрывается).
 *  - Биом, занимающий заметную долю мира (лес, реки, тундра), направления не получает:
 *    сказать про лес «иди на юго-восток» — соврать, он повсюду. Так и пишем.
 *
 * Ось мира: север = Y меньше (Y=0 север, Y=999 юг — {@see NodeLevelCurve}), восток = X больше
 * ({@see \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction::DIRECTIONS}).
 */
class BiomeCompassService
{
    /** Сторона блока агрегации в клетках. Крупно — намеренно: компас, не GPS. */
    public const BLOCK = 100;

    /** Кэш раскладки биомов по блокам (карта статична — сутки с запасом). */
    private const CACHE_KEY = 'biome_compass_grid_v2';
    private const CACHE_TTL = 86400;

    /** Доля мира, с которой биом считается «повсюду» и направления не получает. */
    private const UBIQUITOUS_SHARE = 0.12;

    /** Доля мира, ниже которой биом помечается редким. */
    private const RARE_SHARE       = 0.01;
    private const UNCOMMON_SHARE   = 0.05;

    /**
     * Блок учитывается как «где искать», если в нём хотя бы такая доля от самого плотного
     * блока этого биома. Отсекает одиночные клетки-выбросы, из-за которых компас указывал бы
     * на случайную точку вместо настоящего скопления.
     */
    private const SIGNIFICANT_BLOCK_SHARE = 0.2;

    /** Killswitch: подсказки в легенде можно погасить без редеплоя. */
    public function enabled(): bool
    {
        try {
            $v = (new GameSettingsService())->get('world.biome_compass.enabled', true);
        } catch (Throwable) {
            return false;
        }

        return is_bool($v) ? $v : (is_numeric($v) && (int) $v === 1);
    }

    /**
     * Подсказка по каждому биому относительно позиции игрока.
     *
     * @return array<int, string> biome_id → человекочитаемая подсказка («редкий · северо-восток,
     *                            далеко» / «встречается повсюду»). Биомы без данных отсутствуют.
     */
    public function hintsFor(int $playerX, int $playerY): array
    {
        $grid = $this->grid();
        if ($grid === []) {
            return [];
        }

        $totalKnown = 0;
        foreach ($grid as $blocks) {
            foreach ($blocks as $count) {
                $totalKnown += $count;
            }
        }
        if ($totalKnown === 0) {
            return [];
        }

        $hints = [];
        foreach ($grid as $biomeId => $blocks) {
            $cells = array_sum($blocks);
            if ($cells === 0) {
                continue;
            }

            $share  = $cells / $totalKnown;
            $rarity = match (true) {
                $share < self::RARE_SHARE     => 'редкий',
                $share < self::UNCOMMON_SHARE => 'нечастый',
                default                       => '',
            };

            // Биом-фон: он под ногами почти везде, направление было бы ложью.
            if ($share >= self::UBIQUITOUS_SHARE) {
                $hints[$biomeId] = 'встречается повсюду';
                continue;
            }

            $target = $this->nearestSignificantBlock($blocks, $playerX, $playerY);
            if ($target === null) {
                $hints[$biomeId] = $rarity !== '' ? $rarity : 'встречается местами';
                continue;
            }

            [$tx, $ty, $distance] = $target;
            $where = $this->direction($tx - $playerX, $ty - $playerY);
            $band  = $this->distanceBand($distance);

            $hint = $where === 'здесь'
                ? 'ты уже рядом'
                : $where . ', ' . $band;

            $hints[$biomeId] = $rarity !== '' ? $rarity . ' · ' . $hint : $hint;
        }

        return $hints;
    }

    /**
     * Ближайшее к игроку заметное скопление биома: центр блока + расстояние в клетках.
     * null — если у биома нет ни одного блока, прошедшего порог значимости.
     *
     * @param array<string, int> $blocks ключ «bx:by» → число клеток биома в блоке
     * @return array{0:int,1:int,2:float}|null
     */
    private function nearestSignificantBlock(array $blocks, int $playerX, int $playerY): ?array
    {
        if ($blocks === []) {
            return null;
        }
        $max = max($blocks);
        if ($max <= 0) {
            return null;
        }
        $threshold = $max * self::SIGNIFICANT_BLOCK_SHARE;

        $best     = null;
        $bestDist = null;
        foreach ($blocks as $key => $count) {
            if ($count < $threshold) {
                continue;
            }
            [$bx, $by] = array_map('intval', explode(':', $key));
            // Центр блока — представитель скопления (координаты конкретной клетки не выдаём).
            $cx   = $bx * self::BLOCK + intdiv(self::BLOCK, 2);
            $cy   = $by * self::BLOCK + intdiv(self::BLOCK, 2);
            $dist = sqrt((($cx - $playerX) ** 2) + (($cy - $playerY) ** 2));

            if ($bestDist === null || $dist < $bestDist) {
                $bestDist = $dist;
                $best     = [$cx, $cy];
            }
        }

        if ($best === null || $bestDist === null) {
            return null;
        }

        return [$best[0], $best[1], $bestDist];
    }

    /** Сторона света по смещению. Север = -Y, восток = +X. */
    private function direction(int $dx, int $dy): string
    {
        // Внутри своего блока — направление бессмысленно, биом фактически под ногами.
        $near = intdiv(self::BLOCK, 2);
        if (abs($dx) <= $near && abs($dy) <= $near) {
            return 'здесь';
        }

        // Ось считается «доминирующей», если вторая заметно меньше — иначе диагональ.
        $ns = '';
        $ew = '';
        if (abs($dy) > $near) {
            $ns = $dy < 0 ? 'север' : 'юг';
        }
        if (abs($dx) > $near) {
            $ew = $dx > 0 ? 'восток' : 'запад';
        }

        if ($ns !== '' && $ew !== '') {
            // Сильно вытянутое смещение — называем одну сторону, а не диагональ.
            if (abs($dx) > abs($dy) * 3) {
                return $ew;
            }
            if (abs($dy) > abs($dx) * 3) {
                return $ns;
            }

            return $ns . 'о-' . $ew;
        }

        return $ns !== '' ? $ns : ($ew !== '' ? $ew : 'здесь');
    }

    /** Порядок расстояния словами — чисел не даём (это компас, не маршрут). */
    private function distanceBand(float $distance): string
    {
        return match (true) {
            $distance < 150.0 => 'недалеко',
            $distance < 350.0 => 'приличный путь',
            $distance < 600.0 => 'далеко',
            default           => 'очень далеко',
        };
    }

    /**
     * Раскладка «биом → блок → сколько клеток», один агрегатный запрос, кэш на сутки.
     *
     * @return array<int, array<string, int>>
     */
    private function grid(): array
    {
        $cache = service('cache');
        $hasCache = is_object($cache) && method_exists($cache, 'get') && method_exists($cache, 'save');

        if ($hasCache) {
            $cached = $cache->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->normalise($cached);
            }
        }

        try {
            $db  = Database::connect();
            $blk = self::BLOCK;
            $sql = 'SELECT biome_id, FLOOR(coordinate_x / ' . $blk . ') AS bx, '
                . 'FLOOR(coordinate_y / ' . $blk . ') AS by_, COUNT(*) AS cnt '
                . 'FROM ' . $db->prefixTable('map') . ' '
                . 'WHERE biome_id IS NOT NULL '
                . 'GROUP BY biome_id, bx, by_';
            $result = $db->query($sql);
            if (! is_object($result) || ! method_exists($result, 'getResultArray')) {
                return [];
            }
            $rows = $result->getResultArray();
        } catch (Throwable) {
            // Карта недоступна (частичная тест-БД и т.п.) — легенда обязана отрендериться
            // и без подсказок, а не упасть целиком.
            return [];
        }

        $grid = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $biomeId = $this->intOf($row['biome_id'] ?? null);
            $cnt     = $this->intOf($row['cnt'] ?? null);
            if ($biomeId === 0 || $cnt === 0) {
                continue;
            }
            $bx = $this->intOf($row['bx'] ?? null);
            $by = $this->intOf($row['by_'] ?? null);

            $grid[$biomeId][$bx . ':' . $by] = $cnt;
        }

        if ($hasCache) {
            $cache->save(self::CACHE_KEY, $grid, self::CACHE_TTL);
        }

        return $grid;
    }

    /**
     * Приводит содержимое кэша к ожидаемой форме: кэш — это внешние данные (может остаться
     * от прошлой версии структуры), доверять ему на слово нельзя.
     *
     * @param array<mixed, mixed> $cached
     * @return array<int, array<string, int>>
     */
    private function normalise(array $cached): array
    {
        $grid = [];
        foreach ($cached as $biomeId => $blocks) {
            if (! is_numeric($biomeId) || ! is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $key => $count) {
                if (! is_string($key) || ! is_numeric($count)) {
                    continue;
                }
                $grid[(int) $biomeId][$key] = (int) $count;
            }
        }

        return $grid;
    }

    /** Узкое приведение значения из БД/кэша к int: не число — ноль (строка отбрасывается). */
    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
