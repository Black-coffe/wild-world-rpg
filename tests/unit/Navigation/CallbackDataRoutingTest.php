<?php

declare(strict_types=1);

namespace Tests\Unit\Navigation;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ живых кнопок (CLAUDE.md §🎮 UX-DISCOVERABILITY).
 *
 * Роутинг callback'ов — самый нагруженный ручной реестр в проекте: ~360 статических
 * `callback_data` в коде против четырёхслойного резолва. Кнопка, чей callback ни один
 * слой не узнаёт, отвечает игроку «⚠️ Эта кнопка устарела или больше не работает».
 *
 * История класса (все пойманы игроками, не тестами):
 *  - `npcAct_` — ключ с хвостовым `_` никогда не матчил первый сегмент, все кнопки
 *    встречи с NPC были мертвы;
 *  - `droneScout`, `meteorShelter` — info-callback рецепта не зарегистрирован;
 *  - семена (`berrySeeds` и соседи) — `info_callback` живёт целью «⬅️ Назад» на экране
 *    нехватки ресурсов и не резолвился.
 *
 * Тест повторяет боевую семантику {@see \App\Controllers\Telegram\Commands\SystemCommands\CallbackqueryCommand::execute()}
 * — все 4 слоя в том же порядке. DB- и Telegram-независим.
 *
 * ⚠️ Комментарии из исходников вырезаются через `token_get_all`: в меню лежат
 * закомментированные кнопки (12 штук в Armor/WeaponsCraft2Select), и наивный regex
 * принимал их за живые.
 *
 * @internal
 */
final class CallbackDataRoutingTest extends CIUnitTestCase
{
    /** @var list<string> */
    private const SCAN_DIRS = ['app/Controllers', 'app/Services'];

    /**
     * Главный гейт: каждый статический `callback_data` резолвится роутером.
     */
    public function testEveryStaticCallbackDataResolves(): void
    {
        $routes   = new CallbackRoutes();
        $prefixes = $this->prefixDispatcherPrefixes();
        $dead     = [];

        foreach ($this->staticCallbackData() as $cb => $files) {
            if (! $this->resolvesLikeProduction($cb, $routes, $prefixes)) {
                $dead[] = sprintf('%s (%s)', $cb, implode(', ', array_slice($files, 0, 2)));
            }
        }

        $this->assertSame([], $dead, sprintf(
            "Кнопки, чей callback_data не резолвится ни одним слоем роутинга: %s.\n"
            . "Игрок нажмёт и получит «⚠️ Эта кнопка устарела или больше не работает».\n"
            . 'Зарегистрируй в Config\CallbackRoutes (exact/prefix/wildcard) или в CallbackPrefixDispatcher.',
            implode('; ', $dead)
        ));
    }

    /**
     * Ключи prefix-роутов не должны кончаться на `_`.
     *
     * `resolve()` матчит ПЕРВЫЙ сегмент `explode('_')[0]`, который подчёркивания уже
     * не содержит: ключ `npcAct_` не совпадёт с сегментом `npcAct` никогда. Прод-баг
     * 2026-06-02 — все кнопки встречи с NPC молчали. Ошибка невидима глазами, поэтому
     * держим её тестом.
     */
    public function testPrefixRouteKeysHaveNoTrailingUnderscore(): void
    {
        $bad = array_values(array_filter(
            array_keys((new CallbackRoutes())->prefixRoutes),
            static fn (string $k): bool => str_ends_with($k, '_')
        ));

        $this->assertSame([], $bad, sprintf(
            "prefixRoutes-ключи с хвостовым `_`: %s.\n"
            . 'resolve() сверяет их с первым сегментом callback_data, где `_` уже нет — матч не сработает НИКОГДА.',
            implode(', ', $bad)
        ));
    }

    /**
     * Каждый роут указывает на существующий класс. Переименовали/удалили action —
     * ловим здесь, а не «Handler-класс не найден» в проде.
     */
    public function testEveryRouteTargetClassExists(): void
    {
        $routes  = new CallbackRoutes();
        $missing = [];

        foreach (['exactRoutes', 'prefixRoutes', 'wildcardRoutes'] as $prop) {
            /** @var array<string, string> $map */
            $map = $routes->{$prop};
            foreach ($map as $key => $class) {
                if (! class_exists($class)) {
                    $missing[] = "{$prop}[{$key}] → {$class}";
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            'Роуты, указывающие на несуществующий класс: %s.',
            implode('; ', $missing)
        ));
    }

    /**
     * Повторяет 4 слоя боевого резолва в том же порядке.
     *
     * @param list<string> $prefixes
     */
    private function resolvesLikeProduction(string $cb, CallbackRoutes $routes, array $prefixes): bool
    {
        $action = explode('_', $cb)[0];

        // 1) character inline shortcut
        if ($action === 'character') {
            return true;
        }

        // 2) wildcard (move_dir_*, eventPref_*, march_*)
        foreach (array_keys($routes->wildcardRoutes) as $pattern) {
            if (str_starts_with($cb, rtrim((string) $pattern, '*'))) {
                return true;
            }
        }

        // 3) CallbackPrefixDispatcher (upgrade_building_, repairBuilding_, …)
        foreach ($prefixes as $prefix) {
            if (str_starts_with($cb, $prefix)) {
                return true;
            }
        }

        // 4) config-driven exact + prefix
        return $routes->resolve($action) !== null;
    }

    /**
     * Префиксы, которые перехватывает CallbackPrefixDispatcher — читаем прямо из его
     * исходника, чтобы гейт не разъехался с реальностью при добавлении новой ветки.
     *
     * @return list<string>
     */
    private function prefixDispatcherPrefixes(): array
    {
        $path = ROOTPATH . 'app/Services/Telegram/CallbackPrefixDispatcher.php';
        $this->assertFileExists($path, 'CallbackPrefixDispatcher не найден — гейт был бы фиктивно зелёным.');

        preg_match_all(
            "/str_starts_with\(\s*\\\$callbackData\s*,\s*'([A-Za-z0-9_]+)'\s*\)/",
            (string) file_get_contents($path),
            $m
        );

        $prefixes = array_values(array_unique($m[1]));
        $this->assertNotSame([], $prefixes, 'Не разобрали ни одного префикса — гейт дал бы ложные срабатывания.');

        return $prefixes;
    }

    /**
     * Все статические `'callback_data' => '<литерал>'` в коде экранов.
     * Комментарии вырезаны, динамические (конкатенация/интерполяция) пропущены —
     * их покрывают отдельные гейты по реестрам (рецепты, постройки).
     *
     * @return array<string, list<string>> callback => файлы
     */
    private function staticCallbackData(): array
    {
        $found = [];

        foreach (self::SCAN_DIRS as $dir) {
            $path = ROOTPATH . $dir;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = $this->stripComments((string) file_get_contents($file->getPathname()));
                if (preg_match_all("/'callback_data'\s*=>\s*'([A-Za-z0-9_]+)'\s*[,\]]/", $code, $m)) {
                    foreach ($m[1] as $cb) {
                        $found[$cb][] = $file->getBasename();
                    }
                }
            }
        }

        $this->assertNotSame([], $found, 'Не найдено ни одной кнопки — гейт был бы фиктивно зелёным.');

        foreach ($found as $cb => $files) {
            $found[$cb] = array_values(array_unique($files));
        }

        return $found;
    }

    /**
     * Вырезает комментарии, сохраняя остальной код. Закомментированная кнопка — не кнопка.
     */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
