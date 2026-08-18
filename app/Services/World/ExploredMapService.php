<?php

declare(strict_types=1);

namespace App\Services\World;

use Config\Database;

/**
 * Личная карта исследованного — «что я открыл».
 *
 * Зачем. Вопрос игрока (Анжела, 18.08.2026): «нет ли ресурса, показывающего открытую
 * карту?» Ответ до этого дня — нет: `explored_cells` пишется на каждый шаг, но нигде
 * не показывается. Экран «🗺 Обзор» рисует ЦЕЛЫЙ мир 1000×1000 с точкой игрока, туман
 * войны там не отражён вовсе; публичная `/map` показывает биомы и караваны, но личные
 * слои намеренно скрыты. Единственный код, умевший рисовать исследованное
 * (`MiniMapService`, окно 37×37), не имел ни одного вызывающего — мёртвая ветка.
 *
 * Что делает этот сервис:
 *  - {@see summary()} — числа для текста (медиа-off: весь смысл обязан быть в тексте);
 *  - {@see renderPng()} — картинка: прямоугольник по границам исследованного, открытые
 *    клетки в цвете биома, закрытые внутри рамки — тёмные, позиция игрока — красная.
 *
 * Почему рисуем bbox, а не весь мир. Открытая область у живого игрока — сотни-тысячи
 * клеток из миллиона: на карте мира это несколько пикселей в углу, то есть картинка,
 * которая ничего не показывает. Прямоугольник по своим границам масштабируется под
 * размер холста и читается.
 *
 * `explored_cells.map_cell_id` хранит `map.cell_number` (не `map.id`, вопреки FK) —
 * так пишет `ExploredCellsModel::revealAround`, по нему и джойним.
 */
final class ExploredMapService
{
    /** Сторона мира в клетках. */
    public const WORLD_SIDE = 1000;

    /** Максимальная сторона картинки в пикселях. */
    private const CANVAS_MAX = 900;

    /** Предел строк на выборку — страховка от рендера гигантской области. */
    private const ROW_LIMIT = 400000;

    /**
     * Числа для caption'а: сколько открыто, где границы, какие биомы попались.
     *
     * @return array{
     *     explored: int,
     *     percent: float,
     *     min_x: int, max_x: int, min_y: int, max_y: int,
     *     width: int, height: int,
     *     biomes: list<array{name: string, cells: int}>
     * }
     */
    public function summary(int $characterId): array
    {
        $empty = [
            'explored' => 0,
            'percent'  => 0.0,
            'min_x'    => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0,
            'width'    => 0, 'height' => 0,
            'biomes'   => [],
        ];

        if ($characterId <= 0) {
            return $empty;
        }

        $db  = Database::connect();
        $agg = $db->query(
            'SELECT COUNT(*) AS c,
                    MIN(m.coordinate_x) AS min_x, MAX(m.coordinate_x) AS max_x,
                    MIN(m.coordinate_y) AS min_y, MAX(m.coordinate_y) AS max_y
             FROM explored_cells e
             INNER JOIN map m ON m.cell_number = e.map_cell_id
             WHERE e.character_id = ?',
            [$characterId]
        );

        $row = $agg instanceof \CodeIgniter\Database\BaseResult ? $agg->getRowArray() : null;
        if (! is_array($row)) {
            return $empty;
        }

        $explored = $this->asInt($row['c'] ?? null);
        if ($explored <= 0) {
            return $empty;
        }

        $minX = $this->asInt($row['min_x'] ?? null);
        $maxX = $this->asInt($row['max_x'] ?? null);
        $minY = $this->asInt($row['min_y'] ?? null);
        $maxY = $this->asInt($row['max_y'] ?? null);

        $biomes    = [];
        $biomeStat = $db->query(
            'SELECT b.name AS name, COUNT(*) AS c
             FROM explored_cells e
             INNER JOIN map m ON m.cell_number = e.map_cell_id
             INNER JOIN biomes b ON b.id = m.biome_id
             WHERE e.character_id = ?
             GROUP BY b.name
             ORDER BY c DESC',
            [$characterId]
        );
        if ($biomeStat instanceof \CodeIgniter\Database\BaseResult) {
            foreach ($biomeStat->getResultArray() as $b) {
                $name = $b['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $biomes[] = ['name' => $name, 'cells' => $this->asInt($b['c'] ?? null)];
                }
            }
        }

        $total = self::WORLD_SIDE * self::WORLD_SIDE;

        return [
            'explored' => $explored,
            'percent'  => round($explored * 100 / $total, 4),
            'min_x'    => $minX,
            'max_x'    => $maxX,
            'min_y'    => $minY,
            'max_y'    => $maxY,
            'width'    => $maxX - $minX + 1,
            'height'   => $maxY - $minY + 1,
            'biomes'   => $biomes,
        ];
    }

    /**
     * Рисует карту исследованного и возвращает путь к PNG (или null, если рисовать
     * нечего либо GD недоступен).
     *
     * Имя файла стабильное (по персонажу) — файл перезаписывается, временная папка
     * не растёт бесконечно.
     */
    public function renderPng(int $characterId, ?int $playerX = null, ?int $playerY = null): ?string
    {
        if ($characterId <= 0 || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $summary = $this->summary($characterId);
        if ($summary['explored'] <= 0) {
            return null;
        }

        // Поля вокруг открытой области: без них край выглядит обрезанным.
        $pad  = 2;
        $minX = max(1, $summary['min_x'] - $pad);
        $maxX = min(self::WORLD_SIDE, $summary['max_x'] + $pad);
        $minY = max(1, $summary['min_y'] - $pad);
        $maxY = min(self::WORLD_SIDE, $summary['max_y'] + $pad);

        $cols = $maxX - $minX + 1;
        $rows = $maxY - $minY + 1;

        $cellPx = (int) floor(self::CANVAS_MAX / max($cols, $rows));
        $cellPx = max(1, min(12, $cellPx));

        $canvasW = max(1, $cols * $cellPx);
        $canvasH = max(1, $rows * $cellPx);

        $im = @imagecreatetruecolor($canvasW, $canvasH);
        if ($im === false) {
            return null;
        }

        $unknown  = (int) imagecolorallocate($im, 0x1C, 0x1C, 0x1C);
        $unopened = (int) imagecolorallocate($im, 0x3A, 0x3A, 0x3A);
        $player   = (int) imagecolorallocate($im, 0xFF, 0x00, 0x00);

        // Фон — «мира не видно»; поверх него рамка исследованной области чуть светлее:
        // видно, что там ещё мир, просто не пройденный.
        imagefilledrectangle($im, 0, 0, $canvasW - 1, $canvasH - 1, $unknown);
        imagefilledrectangle($im, 0, 0, $canvasW - 1, $canvasH - 1, $unopened);

        $db    = Database::connect();
        $query = $db->query(
            'SELECT m.coordinate_x AS x, m.coordinate_y AS y, m.biome_id AS biome_id
             FROM explored_cells e
             INNER JOIN map m ON m.cell_number = e.map_cell_id
             WHERE e.character_id = ?
             LIMIT ' . self::ROW_LIMIT,
            [$characterId]
        );

        if (! $query instanceof \CodeIgniter\Database\BaseResult) {
            imagedestroy($im);
            return null;
        }

        $allocated = [];
        foreach ($query->getResultArray() as $cell) {
            $x = $this->asInt($cell['x'] ?? null);
            $y = $this->asInt($cell['y'] ?? null);
            if ($x < $minX || $x > $maxX || $y < $minY || $y > $maxY) {
                continue;
            }

            $biomeId = $this->asInt($cell['biome_id'] ?? null);
            if (! isset($allocated[$biomeId])) {
                [$r, $g, $b] = BiomePalette::for($biomeId);
                $allocated[$biomeId] = (int) imagecolorallocate($im, $r, $g, $b);
            }

            $px = ($x - $minX) * $cellPx;
            $py = ($y - $minY) * $cellPx;
            imagefilledrectangle($im, $px, $py, $px + $cellPx - 1, $py + $cellPx - 1, $allocated[$biomeId]);
        }

        // Позиция игрока — поверх всего, с рамкой в клетку, чтобы точка не терялась.
        if ($playerX !== null && $playerY !== null
            && $playerX >= $minX && $playerX <= $maxX
            && $playerY >= $minY && $playerY <= $maxY
        ) {
            $px = ($playerX - $minX) * $cellPx;
            $py = ($playerY - $minY) * $cellPx;
            $mark = max(1, $cellPx);
            imagefilledrectangle($im, $px, $py, $px + $mark - 1, $py + $mark - 1, $player);
            imagerectangle(
                $im,
                max(0, $px - $cellPx),
                max(0, $py - $cellPx),
                min($canvasW - 1, $px + 2 * $cellPx - 1),
                min($canvasH - 1, $py + 2 * $cellPx - 1),
                $player
            );
        }

        $dir = WRITEPATH . 'tmp_map/';
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . 'explored_' . $characterId . '.png';
        if (! imagepng($im, $file)) {
            imagedestroy($im);
            return null;
        }
        imagedestroy($im);

        return is_file($file) ? $file : null;
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
