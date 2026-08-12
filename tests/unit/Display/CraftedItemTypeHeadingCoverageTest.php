<?php

declare(strict_types=1);

namespace Tests\Unit\Display;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ полок инвентаря.
 *
 * `CraftedResourcesAction::renderGrouped()` — ручная карта `crafted_items.type`
 * → заголовок раздела. Тип без заголовка не ломает ничего заметного: предмет
 * молча падает в «🔸 Прочие предметы» и читается игроком как недоделка.
 *
 * Аудит 12.08.2026 (повод — «Метеоритное укрытие»): в проде 18 типов против 14
 * в карте. Без полки жили `defense`, `drones`, `food`, `accessory` — 9 предметов
 * у 20 владельцев, включая все четыре дрона.
 *
 * Гейт сканирует миграции и требует заголовок для каждого типа, который миграция
 * вставляет в `crafted_items`. Скан сужен до блока после `table('crafted_items')`:
 * иначе в выборку лезет `'type' => 'craft'` из соседней вставки в `tasks`.
 *
 * ⚠️ Граница гейта, честно: колонка `type` — `varchar`, не ENUM, авторитетного
 * списка в коде нет. Типы, засеянные вне миграций (исторические), гейт не видит —
 * их закрыл разовый аудит по проду. Зато он ловит КАЖДЫЙ новый тип, а новый
 * контент приезжает именно миграциями. Для `defense` он бы сработал ещё в мае.
 *
 * @internal
 */
final class CraftedItemTypeHeadingCoverageTest extends CIUnitTestCase
{
    private const ACTION = 'app/Controllers/Telegram/Commands/Actions/CraftedResourcesAction.php';

    private const MIGRATIONS = 'app/Database/Migrations';

    private const TABLE_CALL = "table('crafted_items')";

    /**
     * Главный гейт: у каждого типа из миграций есть заголовок-полка.
     */
    public function testEveryMigratedItemTypeHasHeading(): void
    {
        $headings = $this->headingKeys();
        $migrated = $this->typesInsertedByMigrations();

        $this->assertNotSame([], $migrated, 'Не найдено ни одной вставки в crafted_items — гейт был бы фиктивно зелёным.');

        $missing = [];
        foreach ($migrated as $type => $file) {
            if (! in_array($type, $headings, true)) {
                $missing[] = "'{$type}' (из {$file})";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Типы crafted_items без заголовка в CraftedResourcesAction: %s.\n"
            . "Предметы этих типов молча уедут в «🔸 Прочие предметы» и будут читаться как недоделка.\n"
            . 'Добавь заголовок в карту $typeHeadings.',
            implode(', ', $missing)
        ));
    }

    /**
     * Ключи карты заголовков.
     *
     * @return list<string>
     */
    private function headingKeys(): array
    {
        $path = ROOTPATH . self::ACTION;
        $this->assertFileExists($path, 'CraftedResourcesAction не найден — гейт был бы фиктивно зелёным.');

        $src = (string) file_get_contents($path);
        $pos = strpos($src, '$typeHeadings = [');
        $this->assertNotFalse($pos, 'Карта $typeHeadings не найдена — гейт был бы фиктивно зелёным.');

        $end   = strpos($src, '];', $pos);
        $chunk = substr($src, $pos, $end === false ? null : $end - $pos);

        preg_match_all("/'([a-z ]+)'\s*=>/", $chunk, $m);
        $keys = array_values(array_unique($m[1]));

        $this->assertNotSame([], $keys, 'Карта заголовков разобрана пустой — гейт был бы фиктивно зелёным.');

        return $keys;
    }

    /**
     * Типы, вставляемые миграциями именно в `crafted_items`.
     *
     * @return array<string, string> тип => файл-миграция, где он впервые встретился
     */
    private function typesInsertedByMigrations(): array
    {
        $dir = ROOTPATH . self::MIGRATIONS;
        $this->assertDirectoryExists($dir, 'Каталог миграций не найден — гейт был бы фиктивно зелёным.');

        $types = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            foreach ($this->typesInCraftedItemsBlocks($src) as $type) {
                $types[$type] ??= basename($file);
            }
        }

        return $types;
    }

    /**
     * Вырезает блоки, начинающиеся с `table('crafted_items')` и заканчивающиеся
     * следующим `table('...')`, и собирает `'type' => '...'` только внутри них.
     *
     * @return list<string>
     */
    private function typesInCraftedItemsBlocks(string $src): array
    {
        $found  = [];
        $offset = 0;
        $len    = strlen(self::TABLE_CALL);

        while (($pos = strpos($src, self::TABLE_CALL, $offset)) !== false) {
            $next  = strpos($src, "table('", $pos + $len);
            $chunk = $next === false ? substr($src, $pos) : substr($src, $pos, $next - $pos);

            if (preg_match_all("/'type'\s*=>\s*'([a-z ]+)'/", $chunk, $m)) {
                foreach ($m[1] as $type) {
                    $found[] = $type;
                }
            }

            $offset = $pos + $len;
        }

        return array_values(array_unique($found));
    }
}
