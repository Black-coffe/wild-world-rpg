<?php

declare(strict_types=1);

namespace App\Services\GameSettings;

use App\Models\GameSettingsModel;

/**
 * S5 (v0.51.187) — service для admin-tunable game balance params (ADR-024).
 *
 * Foundation framework. Принципы:
 *  - **Source of truth** = `game_settings` table (rich rationale, см. CLAUDE.md §🎛️).
 *  - **Fallback cascade**: DB row → caller-provided `$default` → ничего (null).
 *  - **Cache**: 60s TTL через `service('cache')`. Инвалидация при `set()` / `reset()`.
 *  - **Type coercion**: возвращает значение строго в типе из `value_type` колонки.
 *  - **Validation**: `set()` отбрасывает значения вне `hard_min` / `hard_max`.
 *
 * Использование callers'ами:
 * ```
 * $svc = new GameSettingsService();
 * $cost = $svc->get('repair.cost_fraction', 0.50); // float, кеш 60s
 * ```
 *
 * Admin UI (`GameSettingsController`) использует:
 *  - `all(?category)` — для рендеринга списка
 *  - `set($key, $value, $updatedBy)` — для inline edit
 *  - `reset($key, $updatedBy)` — для Reset-to-default
 */
final class GameSettingsService
{
    private const CACHE_TTL          = 60;
    private const CACHE_KEY_PREFIX   = 'game_settings_';

    private GameSettingsModel $model;

    public function __construct(?GameSettingsModel $model = null)
    {
        $this->model = $model ?? new GameSettingsModel();
    }

    /**
     * Прочитать значение настройки с типизацией. Кеш 60s.
     *
     * @template T
     * @param T $default
     * @return T|int|float|bool|string
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_KEY_PREFIX . str_replace(['.', ':', '@', '{', '}', '(', ')', '/', '\\'], '_', $key);
        $cache    = service('cache');

        if (is_object($cache) && method_exists($cache, 'get')) {
            $cached = $cache->get($cacheKey);
            if ($cached !== null) {
                return $this->coerceValueFromCacheBox($cached, $default);
            }
        }

        // Безопасная деградация: если game_settings недоступна (напр. частичная тест-БД),
        // возвращаем default — фичи остаются в безопасном dormant-состоянии, не падая.
        try {
            $row = $this->model->findByKey($key);
        } catch (\Throwable $e) {
            return $default;
        }
        if ($row === null) {
            return $default;
        }

        $value = $this->extractTypedValue($row);
        if ($value === null) {
            return $default;
        }

        if (is_object($cache) && method_exists($cache, 'save')) {
            $cache->save($cacheKey, ['v' => $value, 't' => $row['value_type'] ?? 'string'], self::CACHE_TTL);
        }

        return $value;
    }

    /**
     * Сохранить новое значение. Возвращает true при успехе, false если
     * значение вне `hard_min/max` или unknown key.
     *
     * @param int|float|bool|string $value
     */
    public function set(string $key, int|float|bool|string $value, ?string $updatedBy = null): bool
    {
        $row = $this->model->findByKey($key);
        if ($row === null) {
            return false;
        }

        $type = isset($row['value_type']) && is_string($row['value_type']) ? $row['value_type'] : 'string';

        // Validate against hard bounds.
        if (! $this->isWithinHardBounds($row, $value)) {
            return false;
        }

        $update = [
            'value_int'    => null,
            'value_float'  => null,
            'value_bool'   => null,
            'value_string' => null,
            'updated_by'   => $updatedBy,
        ];
        switch ($type) {
            case 'int':
                $update['value_int'] = (int) $value;
                break;
            case 'float':
                $update['value_float'] = (float) $value;
                break;
            case 'bool':
                $update['value_bool'] = ((bool) $value) ? 1 : 0;
                break;
            case 'string':
            default:
                $update['value_string'] = (string) $value;
                break;
        }

        $idRaw = $row['id'] ?? null;
        $id    = is_numeric($idRaw) ? (int) $idRaw : 0;
        if ($id <= 0) {
            return false;
        }

        $ok = $this->model->update($id, $update);
        if ($ok !== false) {
            $this->invalidateCache($key);
            return true;
        }
        return false;
    }

    /**
     * Сбросить значение к `default_value_text`. Парсит текст в нужный тип.
     */
    public function reset(string $key, ?string $updatedBy = null): bool
    {
        $row = $this->model->findByKey($key);
        if ($row === null) {
            return false;
        }
        $defaultText = isset($row['default_value_text']) && is_string($row['default_value_text'])
            ? $row['default_value_text']
            : '';
        $type        = isset($row['value_type']) && is_string($row['value_type']) ? $row['value_type'] : 'string';
        $parsed      = $this->parseTextToType($defaultText, $type);
        if ($parsed === null) {
            return false;
        }
        return $this->set($key, $parsed, $updatedBy);
    }

    /**
     * Все записи (опционально фильтр по категории).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $category = null): array
    {
        if ($category !== null) {
            return $this->model->findByCategory($category);
        }
        $rows = $this->model->orderBy('category', 'ASC')->orderBy('setting_key', 'ASC')->findAll();
        $out  = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized = [];
                foreach ($row as $k => $v) {
                    $normalized[(string) $k] = $v;
                }
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return $this->model->listCategories();
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function extractTypedValue(array $row): int|float|bool|string|null
    {
        $type = isset($row['value_type']) && is_string($row['value_type']) ? $row['value_type'] : 'string';
        switch ($type) {
            case 'int':
                $v = $row['value_int'] ?? null;
                return is_numeric($v) ? (int) $v : null;
            case 'float':
                $v = $row['value_float'] ?? null;
                return is_numeric($v) ? (float) $v : null;
            case 'bool':
                $v = $row['value_bool'] ?? null;
                if ($v === null) {
                    return null;
                }
                return is_numeric($v) ? ((int) $v === 1) : (bool) $v;
            case 'string':
            default:
                $v = $row['value_string'] ?? null;
                return is_string($v) ? $v : null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param int|float|bool|string $value
     */
    private function isWithinHardBounds(array $row, int|float|bool|string $value): bool
    {
        $type = isset($row['value_type']) && is_string($row['value_type']) ? $row['value_type'] : 'string';
        if ($type === 'bool' || $type === 'string') {
            return true; // bounds не релевантны для не-числовых
        }
        $min = $row['hard_min'] ?? null;
        $max = $row['hard_max'] ?? null;
        if (is_string($min) && is_numeric($min) && (float) $value < (float) $min) {
            return false;
        }
        if (is_string($max) && is_numeric($max) && (float) $value > (float) $max) {
            return false;
        }
        return true;
    }

    private function parseTextToType(string $text, string $type): int|float|bool|string|null
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        switch ($type) {
            case 'int':
                return is_numeric($text) ? (int) $text : null;
            case 'float':
                return is_numeric($text) ? (float) $text : null;
            case 'bool':
                $lower = strtolower($text);
                if ($lower === 'true' || $lower === '1' || $lower === 'yes') {
                    return true;
                }
                if ($lower === 'false' || $lower === '0' || $lower === 'no') {
                    return false;
                }
                return null;
            case 'string':
            default:
                return $text;
        }
    }

    /**
     * Кеш хранит ['v' => value, 't' => type]. Возвращает value с проверкой.
     *
     * @template T
     * @param T $default
     * @return T|int|float|bool|string
     */
    private function coerceValueFromCacheBox(mixed $box, mixed $default): mixed
    {
        if (! is_array($box) || ! array_key_exists('v', $box)) {
            return $default;
        }
        $v = $box['v'];
        if (is_int($v) || is_float($v) || is_bool($v) || is_string($v)) {
            return $v;
        }
        return $default;
    }

    private function invalidateCache(string $key): void
    {
        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'delete')) {
            $cache->delete(self::CACHE_KEY_PREFIX . str_replace(['.', ':', '@', '{', '}', '(', ')', '/', '\\'], '_', $key));
        }
    }
}
