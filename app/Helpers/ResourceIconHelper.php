<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\ResourceModel;

/**
 * Идея #10 (Yupirex, 23.01.2025): динамический emoji per resource.
 *
 * Lookup кэшируется в static-property — одно SQL-чтение `resources.name + icon_text`
 * на жизнь PHP-процесса (per Telegram callback).
 *
 * Fallback: если ресурс не найден или icon_text пустой/`?` (legacy placeholder),
 * возвращает '📦' — visual совместимость с старыми сообщениями.
 */
final class ResourceIconHelper
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * Возвращает иконку для ресурса по русскому имени.
     */
    public static function for(string $resourceName): string
    {
        if (self::$cache === null) {
            self::loadCache();
        }
        $icon = self::$cache[$resourceName] ?? '';
        return ($icon === '' || $icon === '?') ? '📦' : $icon;
    }

    /**
     * Очистка кэша (для тестов).
     */
    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function loadCache(): void
    {
        self::$cache = [];
        $rows = (new ResourceModel())->select('name, icon_text')->findAll();
        foreach ($rows as $r) {
            // ResourceModel возвращает Entity (F1.4), не array.
            $name = (string) $r->name;
            $icon = (string) $r->icon_text;
            if ($name !== '') {
                self::$cache[$name] = $icon;
            }
        }
    }
}
