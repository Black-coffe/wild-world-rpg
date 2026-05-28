<?php

declare(strict_types=1);

namespace App\Services\Player;

/**
 * W8 (vNext2) — чистая сортировка строк инвентаря/склада. Reuse-only helper для
 * ResourcesGatheredAction (добытые ресурсы) и BaseStorageListAction (склад базы).
 *
 * Stateless: режим приходит callback-параметром (`..._sort_<mode>`), DB не трогается.
 * Поля читаются безопасно (отсутствующие → 0/''), чтобы один helper обслуживал
 * и resources (name/rarity/quantity/price/weight), и base_storage (name/quantity/weight).
 */
final class InventorySortService
{
    public const MODE_RARITY = 'rarity';
    public const MODE_NAME   = 'name';
    public const MODE_QTY    = 'qty';
    public const MODE_VALUE  = 'value';
    public const MODE_WEIGHT = 'weight';
    public const MODE_RECENT = 'recent';

    /** Режимы экрана «Добытые ресурсы». */
    public const RESOURCE_MODES = [self::MODE_RARITY, self::MODE_NAME, self::MODE_QTY, self::MODE_VALUE];

    /** Режимы экрана «Склад базы». */
    public const STORAGE_MODES = [self::MODE_RECENT, self::MODE_NAME, self::MODE_QTY, self::MODE_WEIGHT];

    /**
     * Вернуть НОВЫЙ отсортированный список (исходный не мутируется).
     * MODE_RECENT — identity (caller задаёт базовый порядок, напр. updated_at DESC).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function sortRows(array $rows, string $mode): array
    {
        $sorted = $rows; // копия (PHP copy-on-write; usort ниже не мутирует вход)

        switch ($mode) {
            case self::MODE_RECENT:
                return $sorted;

            case self::MODE_NAME:
                usort($sorted, static fn (array $a, array $b): int => strcmp(self::str($a, 'name'), self::str($b, 'name')));
                return $sorted;

            case self::MODE_QTY:
                usort($sorted, static fn (array $a, array $b): int => self::int($b, 'quantity') <=> self::int($a, 'quantity'));
                return $sorted;

            case self::MODE_VALUE:
                usort($sorted, static fn (array $a, array $b): int =>
                    (self::flt($b, 'price') * self::int($b, 'quantity')) <=> (self::flt($a, 'price') * self::int($a, 'quantity')));
                return $sorted;

            case self::MODE_WEIGHT:
                usort($sorted, static fn (array $a, array $b): int =>
                    (self::flt($b, 'weight') * self::int($b, 'quantity')) <=> (self::flt($a, 'weight') * self::int($a, 'quantity')));
                return $sorted;

            case self::MODE_RARITY:
            default:
                usort($sorted, static function (array $a, array $b): int {
                    $ra = self::str($a, 'rarity');
                    $rb = self::str($b, 'rarity');
                    $cmp = (is_numeric($ra) && is_numeric($rb)) ? ((float) $ra <=> (float) $rb) : strcmp($ra, $rb);
                    return $cmp !== 0 ? $cmp : strcmp(self::str($a, 'name'), self::str($b, 'name'));
                });
                return $sorted;
        }
    }

    /**
     * Валидировать режим против allowlist; иначе вернуть $default.
     *
     * @param list<string> $allowed
     */
    public static function normalizeMode(?string $mode, array $allowed, string $default): string
    {
        return ($mode !== null && in_array($mode, $allowed, true)) ? $mode : $default;
    }

    /** @param array<string,mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): int
    {
        $v = $row[$key] ?? 0;
        return is_numeric($v) ? (int) $v : 0;
    }

    /** @param array<string,mixed> $row */
    private static function flt(array $row, string $key): float
    {
        $v = $row[$key] ?? 0;
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
