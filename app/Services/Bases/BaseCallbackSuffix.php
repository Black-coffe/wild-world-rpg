<?php

declare(strict_types=1);

namespace App\Services\Bases;

/**
 * story multibase-picker-01 — кодек суффикса базы в `callback_data`:
 * `<прежний callback>_b<claimed_cells.id>`, напр. `building_12_Warehouse_b345`, `hangar_b345`.
 *
 * Роутер суффикс не снимает: обработчик получает ПОЛНЫЙ `callback_data` и сам зовёт
 * {@see split()}. Кнопка без суффикса (старые сообщения) → `split()` даёт `null` —
 * обработчик идёт по прежнему правилу {@see BaseScopeResolver::resolve()}.
 * База из суффикса — только подсказка: проверка — {@see BaseScopeResolver::resolveForBase()}.
 */
final class BaseCallbackSuffix
{
    /** Лимит Telegram на `callback_data`, в байтах. */
    public const MAX_BYTES = 64;

    private const PATTERN = '/_b(\d+)$/';

    /**
     * @throws \LengthException если результат длиннее {@see MAX_BYTES} байт
     */
    public static function append(string $callbackData, int $baseId): string
    {
        $out = $callbackData . '_b' . $baseId;
        if (strlen($out) > self::MAX_BYTES) {
            throw new \LengthException(sprintf(
                'callback_data "%s" длиннее %d байт (%d).',
                $out,
                self::MAX_BYTES,
                strlen($out)
            ));
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int|null} [данные без суффикса, baseId|null]
     */
    public static function split(string $callbackData): array
    {
        if (preg_match(self::PATTERN, $callbackData, $m) !== 1) {
            return [$callbackData, null];
        }

        return [substr($callbackData, 0, -strlen($m[0])), (int) $m[1]];
    }
}
