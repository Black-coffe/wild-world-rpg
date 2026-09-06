---
story: pve-reward-pool-whitelist-03
spec: pve-reward-pool-whitelist
status: done
tier: 2
worker: worker-test
tracer: false
wave: 2
blocked_by: [pve-reward-pool-whitelist-01, pve-reward-pool-whitelist-02]
---

# Первые тесты на пул наград `RewardService`

## Goal
У `RewardService` появляется регрессионное покрытие там, где его не было никогда:
тест падает, если фильтр по типу или потолок цены перестанут применяться, и падает,
если килсвитч перестанет возвращать прежнее поведение.

## Requirements
> Фильтра по типу нет вообще — значит в розыгрыш попадает весь каталог crafted_items: постройки, магические предметы, военное, транспорт, телепорты, роботы, дроны, верстаки
> гасится килсвитчем

## Files
- tests/unit/Services/PVE/RewardServiceTest.php

## Что покрыть
- Предмет с типом вне белого списка (`building`, `robots`, `drones`, `workbench`,
  `magical item`) в выборку не попадает — на обеих ветках, «дорогой» и «дешёвой».
- Предмет разрешённого типа, но дороже `price_cap`, в выборку не попадает.
- `price_cap = 0` означает «без потолка», а не «всё отсечь».
- `pve.reward.type_filter_enabled = false` → выборка ведёт себя как до фикса.
- Пустой результат фильтрации не бросает исключение.

## Non-goals
- **Не source-scan.** Тест, который грепает исходник на наличие `whereIn`, остаётся
  зелёным при сломанном методе — такой тест не покрытие, а декорация. Проверять
  поведение: что метод ВЕРНУЛ при заданных данных.
- Не править `app/Services/PVE/RewardService.php` — если для тестируемости не хватает шва,
  зафиксировать это в `## Findings` и остановиться, а не расширять чужую story.
  **Тот файл в этот момент может держать другой воркер.**
- Не трогать миграцию из story `-01`.
- Не поднимать полноценный PvE-бой и не тестировать `grantRewards()` целиком — предмет
  этой story ровно один: состав пула.
- Не рассчитывать на таблицу `map` и мировые данные — в тестовой базе `wildworld_tests`
  их нет.

## Map slice
`memory/map/pve-pvp.md`. Читать `## Implementation notes` story `-02` — там воркер
запишет фактические имена геттеров и способ внедрения `GameSettingsService`.
Образец мока настроек — `tests/unit/Services/GameSettings/GameSettingsServiceTest.php`
(тестирует на mock-модели, БД не трогает).

## Acceptance criteria
- [ ] Тест краснеет, если из `getRandomCraftedItems` убрать фильтр по типу
- [ ] Тест краснеет, если убрать применение `price_cap`
- [ ] Тест зелёный на локальной машине с поднятым MySQL и не требует данных мира
- [ ] Ни один кейс не сводится к чтению исходного файла

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/PVE/`

## Implementation notes

- Файл: `tests/unit/Services/PVE/RewardServiceTest.php`, 5 тестов, `Tests\Unit\Services\PVE`.
  `app/Services/PVE/RewardService.php` не тронут.
- **Доступ к приватному `getRandomCraftedItems`** — `ReflectionMethod` + `setAccessible(true)`,
  по образцу `tests/database/TaxCollectionPerBaseTest.php`. `grantRewards()` целиком не гонял
  (Non-goals) — он тянет `CharacterStatsService`/`CharacterResourceModel`/`ResourceModel`, что
  требует чара и ресурсов, а предмет story — состав пула, не бой.
- **Настройки** — mock-наследник `GameSettingsModel` (анонимный класс, только `findByKey()`),
  без обращения к реальной `game_settings` — паттерн `GameSettingsServiceTest`. В каждом тесте
  явно задаются все 4 ключа (`type_filter_enabled`/`craft_types`/`price_cap`/`expensive_threshold`),
  так тест не зависит от дефолтов класса и не путается с кэшем `GameSettingsService` (60s):
  `cleanCache()` в `setUp`/`tearDown`, как в `CraftInsuranceServiceTest`.
- **Данные пула** — реальная БД `wildworld_tests`: таблица `crafted_items` создаётся/дропается
  вручную в `setUp`/`tearDown` (минимальный набор колонок: id/name_rus/name_eng/type/
  direction_craft/price/durability_count), по образцу `LootTableServiceTest` — своя изолированная
  таблица, а не общая тестовая схема, не завязана на данные мира (`map` в базе нет и не нужна).
- **5 кейсов** покрывают все пункты `## Что покрыть`: белый список на обеих ветках (weapon/food
  разрешены, building/robots/drones/workbench/`magical item` — нет), `price_cap` режет дорогой
  предмет разрешённого типа, `price_cap=0` — без потолка, `type_filter_enabled=false` — дофиксовое
  поведение (building и предмет дороже cap проходят), пустой пул после фильтрации → `[]`, не
  исключение.
- **Доказательство красноты** (без изменения `RewardService.php` в финальном виде): временно
  заменил `if ($this->typeFilterEnabled())` на `if (false)` в личной рабочей копии, прогнал
  `phpunit` — 3 из 5 тестов покраснели (`testTypeFilterExcludesNonWhitelistedTypesOnBothBranches`,
  `testPriceCapExcludesItemAboveCapEvenIfTypeAllowed`,
  `testEmptyPoolAfterFilteringReturnsEmptyArrayWithoutException`), 2 остались зелёными ожидаемо
  (`testPriceCapZeroMeansNoCap` не зависит от фильтра типа; `testKillswitchOffRestoresPreFixBehaviour`
  проверяет ровно ветку `type_filter_enabled=false`, которая при этой правке становится
  недостижимой веткой `if`, но семантически совпадает). Затем `cp` восстановил файл из бэкапа,
  `git diff --stat app/Services/PVE/RewardService.php` — пусто, файл byte-identical коммиту
  `b9342e41`.
- Verification: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/PVE/` —
  155 тестов (5 новых + 150 существующих), зелено, 1 деприкейшн — тот же, что и в
  `GameSettingsServiceTest` без моих изменений (фреймворковый, не от этого файла).

## Findings
