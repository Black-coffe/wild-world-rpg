<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;
use Config\WorldEvents;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ обещаний мировых событий.
 *
 * Событие может пообещать игроку защиту (`protection_item`) — и это обещание
 * игра обязана уметь сдержать. Инцидент 12.08.2026: «Метеоритный удар» ссылался
 * на `MeteorShelter`, рецепт существовал в конфиге и в БД, но скрафтить предмет
 * было нельзя ни из одного меню. 3 месяца, 652 задетых персонажа, 0 крафтов.
 *
 * Гейт держит цепочку целиком: событие → предмет → рецепт → задача.
 * Достижимость самого рецепта из меню проверяет {@see CraftRecipeReachabilityTest};
 * здесь — что предмет вообще существует как рецепт и что у события есть чем его
 * применить.
 *
 * DB-независим (разбор конфигов и исходников стратегий).
 *
 * @internal
 */
final class WorldEventsPromiseTest extends CIUnitTestCase
{
    /**
     * Каждый `protection_item` события — существующий рецепт крафта.
     *
     * Без этого текст события обещает предмет, которого в игре нет.
     */
    public function testEveryProtectionItemHasRecipe(): void
    {
        $recipes = (new CraftRecipes())->recipes;
        $broken  = [];

        foreach ((new WorldEvents())->events as $eventKey => $cfg) {
            $item = $cfg['protection_item'] ?? null;
            if (! is_string($item) || $item === '') {
                continue;
            }
            if (! array_key_exists($item, $recipes)) {
                $broken[] = sprintf('%s → protection_item=%s', (string) $eventKey, $item);
            }
        }

        $this->assertSame([], $broken, sprintf(
            "События обещают защитный предмет, которого нет в Config\CraftRecipes: %s.\n"
            . 'Текст события пообещает игроку то, чего он не сможет получить.',
            implode('; ', $broken)
        ));
    }

    /**
     * У каждого рецепта-защиты объявлена задача крафта — иначе предмет нельзя
     * не только найти, но и изготовить.
     */
    public function testProtectionRecipesDeclareCraftTask(): void
    {
        $recipes = (new CraftRecipes())->recipes;
        $broken  = [];

        foreach ((new WorldEvents())->events as $eventKey => $cfg) {
            $item = $cfg['protection_item'] ?? null;
            if (! is_string($item) || ! isset($recipes[$item])) {
                continue; // покрыто предыдущим тестом
            }
            $task = $recipes[$item]['task_name'] ?? null;
            if (! is_string($task) || $task === '') {
                $broken[] = sprintf('%s → %s (нет task_name)', (string) $eventKey, $item);
            }
        }

        $this->assertSame([], $broken, sprintf(
            'Защитные предметы без задачи крафта: %s.',
            implode('; ', $broken)
        ));
    }

    /**
     * Каждый `effect_kind` события имеет стратегию-обработчик.
     *
     * Событие с неизвестным видом эффекта запускается, шлёт игрокам анонс —
     * и ничего не делает: анонс без последствий.
     */
    public function testEveryEffectKindHasStrategy(): void
    {
        $strategies = $this->effectStrategySources();
        $orphans    = [];

        foreach ((new WorldEvents())->events as $eventKey => $cfg) {
            $kind = $cfg['effect_kind'] ?? null;
            if (! is_string($kind) || $kind === '') {
                $orphans[] = (string) $eventKey . ' (effect_kind пуст)';
                continue;
            }
            if (! str_contains($strategies, "'" . $kind . "'")) {
                $orphans[] = sprintf('%s → effect_kind=%s', (string) $eventKey, $kind);
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "События с видом эффекта, который не обслуживает ни одна стратегия: %s.\n"
            . 'Событие объявится игрокам и не сделает ничего.',
            implode('; ', $orphans)
        ));
    }

    /**
     * Склеенные исходники стратегий эффектов.
     */
    private function effectStrategySources(): string
    {
        $dir = ROOTPATH . 'app/Services/Events';
        $this->assertDirectoryExists($dir, 'Каталог стратегий событий не найден — гейт был бы фиктивно зелёным.');

        $buffer   = '';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $buffer .= (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertNotSame('', $buffer, 'Не найдено ни одной стратегии — гейт был бы фиктивно зелёным.');

        return $buffer;
    }
}
