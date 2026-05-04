<?php

namespace App\Services\Telegram;

/**
 * F2.5 — wildcard-роутер для callback_data Telegram.
 *
 * Сейчас `CallbackqueryCommand::getActionHandler` имеет mapping из 205
 * элементов (см. lore/refactor/Architecture.md P0 item 5). Это работает,
 * но:
 *   - на каждое новое UI-действие нужно вписывать руками;
 *   - паттерны типа `move_dir_north`, `move_dir_south`, `move_dir_east`,
 *     `move_dir_west` — четыре одинаковых строки в mapping;
 *   - тесты нет, изменения risky.
 *
 * Этот класс заменяет switch/array-mapping wildcard-роутером.
 *
 * Поддержка двух типов паттернов:
 *   - **точное совпадение**: `'character'` ↔ `CharacterAction::class`
 *   - **wildcard**: `'move_dir_*'` ↔ `MoveCharacterToDirectionAction::class`
 *     `*` матчит любой суффикс (один или несколько символов).
 *
 * Паттерны проверяются в порядке регистрации; **первый совпавший**
 * выигрывает. Поэтому регистрируйте более специфичные паттерны раньше
 * общих (`'move_dir_north'` до `'move_dir_*'`).
 *
 * **Не подключён** к `CallbackqueryCommand` в этом коммите. План:
 *   1. Описать routes в `app/Config/CallbackRoutes.php` (создаётся вместе
 *      с интеграцией).
 *   2. `CallbackqueryCommand::getActionHandler` использует Router
 *      вместо inline-mapping.
 *   3. Удалить inline-mapping (~205 строк).
 */
class CallbackRouter
{
    /**
     * @var list<array{pattern:string, handler:string, isWildcard:bool}>
     */
    private array $routes = [];

    /**
     * Зарегистрировать маршрут.
     *
     * @param string $pattern   `'character'` или `'move_dir_*'`.
     * @param string $handler   class-string action-handler'а.
     */
    public function register(string $pattern, string $handler): self
    {
        $this->routes[] = [
            'pattern'    => $pattern,
            'handler'    => $handler,
            'isWildcard' => str_contains($pattern, '*'),
        ];
        return $this;
    }

    /**
     * Зарегистрировать сразу несколько маршрутов из карты.
     *
     * @param array<string,string> $map pattern → class-string
     */
    public function registerMany(array $map): self
    {
        foreach ($map as $pattern => $handler) {
            $this->register($pattern, $handler);
        }
        return $this;
    }

    /**
     * Найти handler-class для конкретного callback_data.
     *
     * @return string|null class-string action-handler'а, или null если
     *                     ни один паттерн не совпал.
     */
    public function resolve(string $callbackData): ?string
    {
        foreach ($this->routes as $route) {
            if ($route['isWildcard']) {
                if ($this->matchesWildcard($route['pattern'], $callbackData)) {
                    return $route['handler'];
                }
            } else {
                if ($route['pattern'] === $callbackData) {
                    return $route['handler'];
                }
            }
        }
        return null;
    }

    /**
     * Проверка `'move_dir_*'` против `'move_dir_north'`.
     * `*` — это «любые символы, включая ничего».
     */
    private function matchesWildcard(string $pattern, string $callbackData): bool
    {
        // Превращаем pattern в regex: экранируем всё, заменяем `*` на `.*`.
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
        return (bool) preg_match($regex, $callbackData);
    }

    /**
     * @return list<array{pattern:string, handler:string, isWildcard:bool}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
