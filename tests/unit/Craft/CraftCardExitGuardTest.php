<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Story craft-shortfall-buy-05 — замок от дрейфа: карточка крафта не имеет
 * права оставить игрока в состоянии «нельзя собрать ни одной штуки» без
 * единого действия вперёд (тот тупик, что закрыла story craft-shortfall-buy-01
 * помощником `App\Services\Craft\CraftCardHelper::fallbackButton()` для 27
 * карточек слоя WorkbenchGeneral — коммиты b9365c7d/98c60309/f1da4d3d/2f0de0b3).
 *
 * ⚠️ Это АНТИ-ДРЕЙФОВЫЙ ЗАМОК ПРИСУТСТВИЯ, а не покрытие поведения. Тест
 * смотрит только на исходник: содержит ли файл строку вызова
 * `fallbackButton(`. Если `CraftCardHelper::fallbackButton()` сломают
 * изнутри (например, он начнёт отдавать пустой массив, битый callback_data
 * или вообще перестанет добавлять кнопку в клавиатуру) — этот тест
 * останется зелёным, он не исполняет код и не рендерит клавиатуру.
 * Поведение самого помощника проверяется отдельно, поведенческими тестами —
 * `tests/unit/Services/Craft/CraftCardHelperTest.php` (story 01).
 *
 * Список карточек тест строит из дерева каталога слоя `Craft/WorkbenchGeneral/`,
 * а не хардкодит: карточкой считается любой PHP-файл, объявивший собственную
 * логику количественных кнопок (`private array $craftQuantities`) — этим
 * признаком карточка отличается от экрана-хаба (списка карточек:
 * `ToolsCraft1Action`, `MedicalCraft1Action`, `ComponentsCraft1Select` и
 * подобных), у которого этой логики нет. Новая карточка с этим свойством,
 * добавленная в этот слой, попадает под проверку сама, без правки этого
 * файла.
 *
 * Область — именно слой `WorkbenchGeneral` (текущие 27 карточек + tracer,
 * story craft-shortfall-buy-01/02/03/04), а не весь каталог `Craft/`: 8
 * карточек брони/оружия `WorkbenchStandard` (Armor/Weapons) ещё не переведены
 * на этот помощник — их перевод заказан отдельной story craft-shortfall-buy-13
 * (wave 4 плана) и умышленно не входит в этот замок (см. `## Non-goals`
 * story craft-shortfall-buy-05: «не подменять... здесь только замок от
 * дрейфа слоя»). Расширение области на весь `Craft/` — за Queen, вместе со
 * story 13.
 *
 * @internal
 */
final class CraftCardExitGuardTest extends CIUnitTestCase
{
    private const CRAFT_ACTIONS_DIR = APPPATH . 'Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral';

    /** Признак «это карточка со своей логикой кнопок количества», не хаб. */
    private const OWN_QUANTITY_LOGIC_MARKER = 'private array $craftQuantities';

    /** Единственный установленный выход из тупика «нельзя собрать ни одной штуки». */
    private const EXIT_MARKER = 'fallbackButton(';

    public function testTreeScanFindsCraftCards(): void
    {
        // Страховка от тихо сломанного пути сканирования: если дерево вдруг
        // не отдаёт ни одной карточки, это не «всё ок», а сигнал, что маркер
        // или путь перестали совпадать с реальным деревом.
        $this->assertNotEmpty(
            $this->collectCraftCardFiles(),
            'Дерево ' . self::CRAFT_ACTIONS_DIR . ' не отдало ни одной карточки по маркеру "'
            . self::OWN_QUANTITY_LOGIC_MARKER . '" — проверь путь сканирования или сам маркер.'
        );
    }

    public function testEveryCraftCardHasAnExitFromTheShortfallDeadEnd(): void
    {
        $offenders = [];

        foreach ($this->collectCraftCardFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, self::EXIT_MARKER)) {
                $offenders[] = $this->relativePath($file);
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "Карточки крафта без вызова CraftCardHelper::fallbackButton() — "
            . "тупик «нельзя собрать ни одной штуки» у них ничем не закрыт:\n- "
            . implode("\n- ", $offenders)
        );
    }

    /** @return list<string> абсолютные пути к php-файлам карточек */
    private function collectCraftCardFiles(): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::CRAFT_ACTIONS_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (! $entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($entry->getPathname());
            if (str_contains($source, self::OWN_QUANTITY_LOGIC_MARKER)) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relativePath(string $absolute): string
    {
        $normalized = str_replace('\\', '/', $absolute);
        $appPath    = str_replace('\\', '/', APPPATH);

        return 'app/' . ltrim(str_replace($appPath, '', $normalized), '/');
    }
}
