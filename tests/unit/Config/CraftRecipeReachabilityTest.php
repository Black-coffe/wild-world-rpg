<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;
use Config\CraftRecipes;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ достижимости крафта (CLAUDE.md §🎮 UX-DISCOVERABILITY).
 *
 * Повод — прод-инцидент 12.08.2026. Рецепт `MeteorShelter` («Метеоритное укрытие»)
 * с 09.05 существовал в трёх слоях сразу: запись в {@see CraftRecipes}, задача
 * `craftMeteorShelter` в `tasks`, строка в `crafted_items`. И при этом ни одно
 * меню крафта не имело к нему кнопки, а его `info_callback` не был зарегистрирован
 * в {@see CallbackRoutes}. Текст события «Метеоритный удар» обещал предмет как
 * способ вдвое срезать потери — за 3 месяца событие отработало 7 раз и задело
 * 652 персонажа, а крафтов было 0. Игрок в чате: «я везде пересмотрел в меню
 * и подменю крафта, нигде нет». Он был прав.
 *
 * Это второй случай того же класса (первый — `droneScout`, см. памятку в
 * {@see CallbackRoutes}). Класс бага стоит гейта, а не третьей памятки.
 *
 * Инвариант: КАЖДЫЙ рецепт из {@see CraftRecipes} обязан иметь дверь —
 * то есть его ключ или его callback'и обязаны встречаться в коде, которое
 * строит игроку экраны. Рецепт, о котором знает только конфиг, — мёртвый груз
 * либо (хуже) обещание, которое игра не может сдержать.
 *
 * DB-независим и Telegram-независим: чистый разбор исходников.
 *
 * @internal
 */
final class CraftRecipeReachabilityTest extends CIUnitTestCase
{
    /**
     * Директории, где живёт код, строящий игроку экраны и кнопки.
     * `Config/` сюда НЕ входит намеренно: упоминание рецепта в конфиге —
     * ровно то состояние, которое этот тест обязан считать недостаточным.
     *
     * @var list<string>
     */
    private const MENU_DIRS = [
        'app/Controllers/Telegram',
        'app/Services',
    ];

    /**
     * Рецепты, у которых двери нет намеренно, с причиной.
     * Пустой список — цель. Любая запись здесь обязана нести объяснение,
     * иначе это просто узаконенный дрейф.
     *
     * @var array<string, string>
     */
    private const INTENTIONALLY_DOORLESS = [];

    /**
     * Главный гейт: у каждого рецепта есть дверь из игрового UI.
     */
    public function testEveryRecipeIsReachableFromSomeMenu(): void
    {
        $sources = $this->menuSourcesByFile();
        $routes  = new CallbackRoutes();
        $orphans = [];

        foreach ((new CraftRecipes())->recipes as $key => $recipe) {
            if (isset(self::INTENTIONALLY_DOORLESS[$key])) {
                continue;
            }

            // 🔴 Экран рецепта не может подтверждать сам себя. Именно так выглядели
            // ОБА исторических бага: у `droneScout` класс-обработчик существовал
            // (DroneScoutCraftInfoAction) и был полон кнопок крафта — а войти в него
            // было неоткуда. Ищем дверь ИЗВНЕ: исходник собственного обработчика
            // рецепта из стога убираем.
            $haystack = $this->sourcesExcludingOwnHandler($sources, $recipe, $routes);

            if (! $this->isReachable((string) $key, $recipe, $haystack, $routes)) {
                $orphans[] = (string) $key;
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "Рецепты без двери из UI (BUILT-BUT-INVISIBLE): %s.\n"
            . "Нужна кнопка в меню крафта — либо `genericCraft_<Ключ>_<n>`, либо "
            . "зарегистрированный в CallbackRoutes info_callback, на который эта кнопка ведёт.\n"
            . "Иначе игрок физически не сможет скрафтить предмет, сколько бы меню он ни обошёл.",
            implode(', ', $orphans)
        ));
    }

    /**
     * Гейт №2: КАЖДЫЙ заявленный `info_callback` обязан резолвиться роутером.
     *
     * Здесь нет ветки «а если кнопки нет — не проверяем»: `info_callback` живёт
     * в рантайме безусловно. {@see \App\Services\Craft\CraftShortageService}
     * подставляет его целью кнопки «⬅️ Назад» на экране нехватки ресурсов
     * (и целью «🔨 <компонент>», когда не хватает крафтового ингредиента).
     * Незарегистрированный callback = «⚠️ Эта кнопка устарела» под пальцем
     * у игрока, который и так уже уперся в нехватку.
     *
     * Этот гейт поймал бы ОБА исторических инцидента (`droneScout`,
     * `meteorShelter`) в момент коммита, ещё до кнопки.
     */
    public function testDeclaredInfoCallbacksResolve(): void
    {
        $routes   = new CallbackRoutes();
        $unrouted = [];

        foreach ((new CraftRecipes())->recipes as $key => $recipe) {
            $cb = $recipe['info_callback'] ?? null;
            if (! is_string($cb) || $cb === '') {
                continue;
            }
            if (! $this->resolves($cb, $routes)) {
                $unrouted[] = sprintf('%s (info_callback=%s)', (string) $key, $cb);
            }
        }

        $this->assertSame([], $unrouted, sprintf(
            "info_callback не резолвится роутером: %s.\n"
            . "Он живой: CraftShortageService вешает его на «⬅️ Назад» экрана нехватки.\n"
            . 'Либо зарегистрируй в CallbackRoutes, либо укажи существующий экран возврата.',
            implode(', ', $unrouted)
        ));
    }

    /**
     * Рецепт достижим, если о нём знает код экранов: либо кнопка крафта
     * собирается по его ключу (в т.ч. динамически — `genericCraft_{$key}_1`),
     * либо его info_callback и зарегистрирован, и упомянут в меню.
     *
     * @param array<string,mixed> $recipe
     */
    private function isReachable(string $key, array $recipe, string $haystack, CallbackRoutes $routes): bool
    {
        // 1) Прямая кнопка крафта литералом: `genericCraft_MeteorShelter_1`.
        if (str_contains($haystack, 'genericCraft_' . $key . '_')) {
            return true;
        }

        // 2) Ключ рецепта упомянут в коде экранов — покрывает динамические меню,
        //    которые собирают callback_data строкой ("genericCraft_{$meta['recipe']}_1"
        //    в теплице, сезонный и T3-каталоги). Ключ там присутствует как значение
        //    в реестре меню, поэтому подстроки достаточно.
        if ($this->mentionsToken($haystack, $key)) {
            return true;
        }

        // 3) Живой info_callback: и зарегистрирован, и есть кнопка.
        $cb = $recipe['info_callback'] ?? null;
        if (is_string($cb) && $cb !== '' && $this->callbackIsWired($cb, $haystack) && $this->resolves($cb, $routes)) {
            return true;
        }

        return false;
    }

    /**
     * Callback реально висит на кнопке (литералом или как хвост динамической сборки).
     */
    private function callbackIsWired(string $cb, string $haystack): bool
    {
        return $this->mentionsToken($haystack, $cb);
    }

    /**
     * Упоминание токена как отдельного строкового литерала — в одинарных
     * или двойных кавычках. Без кавычек не ищем, чтобы `Soil` не «нашёлся»
     * внутри `SoilEnricher` и не сделал гейт бесполезно-зелёным.
     */
    private function mentionsToken(string $haystack, string $token): bool
    {
        return str_contains($haystack, "'" . $token . "'")
            || str_contains($haystack, '"' . $token . '"');
    }

    /**
     * Повторяет боевую семантику роутинга: {@see CallbackqueryCommand} режет
     * callback_data по `_` и резолвит ПЕРВЫЙ сегмент через exact → prefix,
     * плюс wildcard-ветка по полной строке.
     */
    private function resolves(string $cb, CallbackRoutes $routes): bool
    {
        $segment = explode('_', $cb)[0];

        if ($routes->resolve($segment) !== null) {
            return true;
        }

        foreach (array_keys($routes->wildcardRoutes) as $pattern) {
            if (str_starts_with($cb, rtrim((string) $pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Исходники кода экранов, по файлам: нормализованный путь => содержимое.
     *
     * @return array<string, string>
     */
    private function menuSourcesByFile(): array
    {
        $files = [];

        foreach (self::MENU_DIRS as $dir) {
            $path = ROOTPATH . $dir;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $real = $file->getRealPath();
                    if ($real !== false) {
                        $files[str_replace('\\', '/', $real)] = (string) file_get_contents($real);
                    }
                }
            }
        }

        $this->assertNotSame([], $files, 'Не найдено ни одного исходника меню — гейт был бы фиктивно зелёным.');

        return $files;
    }

    /**
     * Стог исходников без файла собственного обработчика рецепта.
     *
     * @param array<string, string> $sources
     * @param array<string, mixed>  $recipe
     */
    private function sourcesExcludingOwnHandler(array $sources, array $recipe, CallbackRoutes $routes): string
    {
        $own = null;
        $cb  = $recipe['info_callback'] ?? null;

        if (is_string($cb) && $cb !== '') {
            $handler = $routes->resolve(explode('_', $cb)[0]);
            if (is_string($handler)) {
                $own = str_replace('\\', '/', $handler) . '.php';
            }
        }

        $buffer = '';
        foreach ($sources as $path => $contents) {
            if ($own !== null && str_ends_with($path, ltrim(strrchr($own, '/') ?: $own, '/'))
                && str_contains($contents, 'class ' . basename($own, '.php'))) {
                continue; // это и есть собственный экран рецепта
            }
            $buffer .= $contents;
        }

        return $buffer;
    }
}
