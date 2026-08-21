<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Consumables;
use Config\Database;

/**
 * Anti-drift: `Config\Consumables` — чисто презентационная классификация поверх
 * `crafted_items.type='drug'` (докблок класса), и НИЧТО до этой story не сверяло её
 * с реальностью. Опечатка в `name_eng` внутри `MEDICINE`/`PROVISION` увозила бы
 * настоящее лекарство на чужую полку при полностью зелёном `ConsumablesCatalogTest`
 * (тот проверяет лишь 4 имени, достижимых через `Config\Debuffs::cured_by`, а не все 39).
 *
 * 🔴 Гоняется только там, где `crafted_items` собрана миграциями и заполнена
 * (testbot/CI, project bindings): локальная `wildworld_tests` в этом репозитории
 * часто пуста — красный локальный прогон на пустых данных ничего не доказывает
 * (docs/specs/pharmacy-split/pharmacy-split-07.md).
 */
final class ConsumablesCatalogMatchesDbTest extends CIUnitTestCase
{
    /** @return list<string> */
    private function drugNamesFromDb(): array
    {
        $db = Database::connect();
        if (! $db->tableExists('crafted_items')) {
            $this->markTestSkipped('crafted_items недоступна в этом окружении (сверка гоняется на testbot/CI)');
        }

        $rows = $db->table('crafted_items')->select('name_eng')->where('type', 'drug')->get()->getResultArray();
        if ($rows === []) {
            $this->markTestSkipped('crafted_items не содержит ни одной строки type=drug в этом окружении (сверка гоняется на testbot/CI)');
        }

        return array_map(static fn (array $row): string => (string) $row['name_eng'], $rows);
    }

    /**
     * Каждое имя из `MEDICINE`/`PROVISION` обязано существовать в `crafted_items.name_eng` —
     * иначе опечатка в конфиге ссылается на несуществующий предмет.
     */
    public function testEveryCatalogNameExistsInCraftedItems(): void
    {
        $dbNames = $this->drugNamesFromDb();

        foreach (array_merge(Consumables::MEDICINE, Consumables::PROVISION) as $nameEng) {
            $this->assertContains(
                $nameEng,
                $dbNames,
                "«{$nameEng}» из Config\\Consumables не найден в crafted_items.name_eng (type=drug) — опечатка в каталоге?",
            );
        }
    }

    /**
     * Каждая строка `crafted_items` с `type='drug'` обязана числиться РОВНО в одном
     * из двух каталогов — не в обоих (конфликт полки) и не ни в одном (новый предмет
     * тихо съедет на полку провизии по умолчанию `Consumables::shelfOf()`, оставшись
     * незамеченным).
     */
    public function testEveryDrugRowIsClassifiedInExactlyOneCatalog(): void
    {
        $dbNames = $this->drugNamesFromDb();

        foreach ($dbNames as $nameEng) {
            $inMedicine  = in_array($nameEng, Consumables::MEDICINE, true);
            $inProvision = in_array($nameEng, Consumables::PROVISION, true);

            $this->assertTrue(
                $inMedicine || $inProvision,
                "«{$nameEng}» (crafted_items.type=drug) не числится ни в MEDICINE, ни в PROVISION — новый предмет забыт в Config\\Consumables",
            );
            $this->assertFalse(
                $inMedicine && $inProvision,
                "«{$nameEng}» числится сразу в MEDICINE и PROVISION — конфликт полки в Config\\Consumables",
            );
        }
    }
}
