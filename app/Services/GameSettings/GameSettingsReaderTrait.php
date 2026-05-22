<?php

declare(strict_types=1);

namespace App\Services\GameSettings;

/**
 * N5 (ADR-040) — типобезопасное чтение GameSettings для callers (action-handler'ы и т.п.).
 *
 * GameSettingsService::get() возвращает union (int|float|bool|string), а PHPStan L9
 * запрещает (int)$mixed cast (memory feedback_phpstan_no_mixed_to_int_cast). Трейт
 * нарроит значение через is_numeric/is_bool/is_string ПЕРЕД приведением — единый
 * безопасный путь, без дублирования в каждом handler'е. Service лениво инициализируется.
 */
trait GameSettingsReaderTrait
{
    private ?GameSettingsService $gsReader = null;

    protected function gs(): GameSettingsService
    {
        return $this->gsReader ??= new GameSettingsService();
    }

    protected function gsInt(string $key, int $default): int
    {
        $raw = $this->gs()->get($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    protected function gsFloat(string $key, float $default): float
    {
        $raw = $this->gs()->get($key, $default);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    protected function gsBool(string $key, bool $default): bool
    {
        $raw = $this->gs()->get($key, $default);
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }

        return $default;
    }

    protected function gsString(string $key, string $default): string
    {
        $raw = $this->gs()->get($key, $default);

        return is_string($raw) ? $raw : $default;
    }
}
