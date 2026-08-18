<?php

declare(strict_types=1);

namespace App\Services\World;

/**
 * Цвета биомов для растровых карт — одна точка правды.
 *
 * Те же девять цветов, которыми нарисованы попиксельные снимки мира
 * (`world_map_1000x1000.png`) и которые дублируются в публичной карте
 * `app/Views/site/map.php`. Раньше палитра жила приватным полем внутри
 * `MiniMapService`, до которого ни один экран не доходил, — и любая новая карта
 * начиналась с копипасты хексов.
 */
final class BiomePalette
{
    /**
     * biome_id → [R, G, B].
     *
     * @var array<int, array{int<0, 255>, int<0, 255>, int<0, 255>}>
     */
    public const COLORS = [
        1 => [0x00, 0x88, 0x74], // Лес
        2 => [0x00, 0x32, 0x39], // Горы
        3 => [0xFF, 0xFF, 0xFF], // Тундра
        4 => [0x39, 0xDB, 0x97], // Реки
        5 => [0xD9, 0xD2, 0x29], // Тропические джунгли
        6 => [0xDA, 0xC9, 0x9D], // Поля
        7 => [0x4E, 0x42, 0x11], // Пещеры
        8 => [0xCC, 0x00, 0x00], // Вулкан
        9 => [0x82, 0x64, 0x2B], // Пустыни
    ];

    /**
     * Цвет неизвестного/незаполненного биома.
     *
     * @var array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    public const FALLBACK = [0x80, 0x80, 0x80];

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    public static function for(int $biomeId): array
    {
        return self::COLORS[$biomeId] ?? self::FALLBACK;
    }
}
