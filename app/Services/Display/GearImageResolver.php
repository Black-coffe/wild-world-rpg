<?php

declare(strict_types=1);

namespace App\Services\Display;

/**
 * Резолвер картинок экипировки (броня / оружие) для экранов осмотра.
 *
 * Прод-инцидент 2026-07-10 (char 1106, 31 ошибка за 4 минуты): `GearArmorDetailAction`
 * держал карту `name_en → файл` и слепо возвращал путь `craft/standard/<файл>`, не
 * проверяя существование. `Request::encodeFile()` делает `fopen` → при отсутствии файла
 * бросает исключение и экран осмотра брони падал целиком.
 *
 * Масштаб был не «один файл»: из 20 позиций карты брони 16 не лежали в `standard/`
 * (5 T3-броней живут в `professional/`, 11 позиций не имели картинки вовсе).
 * Работали ровно 4 стартовые тряпки — поэтому баг годами не всплывал: почти вся
 * аудитория на L1. Кнопка осмотра брони стала находимой (v0.51.514) → мина сработала.
 *
 * С 2026-07-10 недостающим 11 броням нарисован арт (ADR-022), и все 24 брони отдают
 * собственную картинку. Резолвер от этого не стал необязательным: он держит инвариант
 * «нет файла → заглушка/текст, а не падение» для будущего контента.
 *
 * Инварианты:
 *  - Файловая система авторитетнее карты: путь возвращается, только если файл существует.
 *  - Картинка — enhancement (MEDIA-OFF, ADR-020). Нет файла → `null`, и вызывающий шлёт
 *    самодостаточный текст, а не падает.
 *  - Порядок каталогов начинается со `standard/` → для позиций, которые работали раньше,
 *    результат byte-identical (коллизий имён между каталогами нет).
 */
final class GearImageResolver
{
    /** Каталоги поиска в порядке приоритета. `standard` первым → сохраняет прежнее поведение. */
    private const CRAFT_DIRS = ['standard', 'professional', 'general'];

    private const CRAFT_BASE = 'uploads/telegram/craft/';

    public const DEFAULT_ARMOR  = 'default_armor.jpg';
    public const DEFAULT_WEAPON = 'default_weapon.jpg';

    /**
     * Относительные пути-кандидаты в порядке приоритета. Pure — тестируется без ФС.
     *
     * @return list<string>
     */
    public static function relativeCandidates(string $filename): array
    {
        if ($filename === '') {
            return [];
        }

        $candidates = [];
        foreach (self::CRAFT_DIRS as $dir) {
            $candidates[] = self::CRAFT_BASE . $dir . '/' . $filename;
        }

        return $candidates;
    }

    /**
     * Абсолютный путь первого существующего кандидата или null.
     */
    public static function locate(string $filename): ?string
    {
        foreach (self::relativeCandidates($filename) as $rel) {
            $abs = FCPATH . $rel;
            if (is_file($abs)) {
                return $abs;
            }
        }

        return null;
    }

    /**
     * Путь картинки брони: фракционная (V14/ADR-046) → карта → заглушка → null.
     *
     * @param array<string,string> $map name_en → имя файла
     */
    public static function armorImage(string $nameEn, array $map): ?string
    {
        $factionRel = OutfitDisplayHelper::factionArmorImageRel($nameEn);
        if ($factionRel !== null && is_file(FCPATH . $factionRel)) {
            return FCPATH . $factionRel;
        }

        return self::resolveFromMap($nameEn, $map, self::DEFAULT_ARMOR);
    }

    /**
     * Путь картинки оружия: карта → заглушка → null.
     *
     * @param array<string,string> $map name_en → имя файла
     */
    public static function weaponImage(string $nameEn, array $map): ?string
    {
        return self::resolveFromMap($nameEn, $map, self::DEFAULT_WEAPON);
    }

    /**
     * @param array<string,string> $map
     */
    private static function resolveFromMap(string $nameEn, array $map, string $fallback): ?string
    {
        $filename = $map[$nameEn] ?? null;
        if ($filename !== null && $filename !== '') {
            $path = self::locate($filename);
            if ($path !== null) {
                return $path;
            }
        }

        // Предмет есть в карте, но файла нет (или предмета в карте нет вовсе) → заглушка.
        return self::locate($fallback);
    }
}
