---
story: pve-reward-pool-whitelist-02
spec: pve-reward-pool-whitelist
status: done
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# `RewardService` курирует пул наград по типу и цене

## Goal
`getRandomCraftedItems` перестаёт выбирать предметы по одной лишь цене: пул ограничивается
белым списком `crafted_items.type` и потолком цены из `GameSettings`, а магический литерал
`5000` заменяется читаемым ключом. Килсвитч возвращает прежнее поведение без релиза.

## Requirements
> Пул выбирается только по цене и больше ни по чему (getRandomCraftedItems, строки 228–237): price >= 5000 для сильного NPC, price < 5000 для слабого. Фильтра по типу нет вообще
> в розыгрыш попадает весь каталог crafted_items: постройки, магические предметы, военное, транспорт, телепорты, роботы, дроны, верстаки
> Итого 13 строк / 44 штуки роботов, дронов и верстаков мимо всей цепочки крафта
> он чинит причину — некурируемый пул, — а не симптом

## Files
- app/Services/PVE/RewardService.php

## Что сделать

1. Внести в сервис геттеры по образцу `App\Services\Player\CraftInsuranceService`
   (`eligibleTypes()`, строки 107-119; `enabled()`, строки 78-88) — тот же паттерн,
   не изобретать свой:
   - `pve.reward.craft_types` → `list<string>`: `settings->get($key, DEFAULT_*)`,
     `explode(',')`, `trim()`, отбросить пустые, при пустом результате — фолбэк на
     константу-дефолт класса.
   - `pve.reward.price_cap` → `int`, `0` означает «без потолка».
   - `pve.reward.expensive_threshold` → `int`, подставляется вместо обоих инлайновых `5000`
     (строки 233 и 236).
   - `pve.reward.type_filter_enabled` → `bool`. Читать НЕ прямым кастом:
     `(bool) "false" === true` — живая PHP-ловушка, из-за которой `CraftInsuranceService`
     разбирает значение вручную. Повторить его разбор.
2. В `getRandomCraftedItems` добавить `whereIn('type', $types)` и, при `price_cap > 0`,
   `where('price <=', $cap)`. При `type_filter_enabled = false` ни белый список, ни потолок
   не применяются — остаётся только порог.
3. Константы-дефолты класса должны совпадать со значениями story `-01`:
   типы `drug,food,component,tool,weapon,clothing`, cap `10000`, порог `5000`, килсвитч `true`.
   Дефолт в коде — точка старта для свежих окружений и тестов, источник истины на проде —
   строка в `game_settings`.
4. Пустой белый список после фильтрации не должен ронять бой: если пул пуст,
   `grantRewards` просто не выдаёт крафт-предметов, остальные награды (золото, статы,
   ресурсы) выдаются как раньше.

## Non-goals
- **Не наследовать CI4 builder-state quirk.** Текущий код делает `$query = $this->craftedItemModel;`
  и вешает `where()` на общий инстанс модели: в кроновом `AutoPveHandler` условия накопятся
  между вызовами. Брать свежий инстанс модели или `->builder()`.
- Не оптимизировать `findAll()` + `shuffle()` в PHP на `ORDER BY RAND()`/`LIMIT` — это
  отдельная задача, не смешивать с фиксом пула.
- Не трогать соседний код цикла записи: `durability_count` → `CraftedItemsLogModel::baseCharges()`
  (строки 132-140) имеет собственную историю фиксов от 2026-08-09.
- Не менять сигнатуру `grantRewards()` и ни один из трёх вызывающих файлов
  (`PvEService.php`, `AutoPveHandler.php`, `NpcInteractionService.php`) — фикс целиком
  внутри одного сервиса.
- Не изымать и не пересчитывать уже выданные игрокам предметы: решением человека выданное
  остаётся (`brief.md` → `## Answers`).
- Не писать тесты — это story `-03`, она идёт следующей волной и создаёт свой файл.
- Не оставлять в методе ни одного балансового литерала: после правки `5000` не должно
  остаться в коде ни в каком виде.

## Map slice
`memory/map/pve-pvp.md` (Services/PVE, награда только победителю-игроку) +
`memory/map/admin.md` (GameSettings, кэш 60 с — значение с прода подхватится не мгновенно).

Точки в коде: `app/Services/PVE/RewardService.php:38` (`grantRewards`), `:108` и `:111-115`
(два места вызова), `:228-262` (сам метод), `:233`/`:236` (литералы `5000`).
Образец паттерна — `app/Services/Player/CraftInsuranceService.php:68,78-88,107-119`.

## Acceptance criteria
- [ ] В `crafted_items`-пуле после фикса не может оказаться предмет с `type` вне белого списка — ни на «дорогой», ни на «дешёвой» ветке
- [ ] Предмет дороже `price_cap` не попадает в пул, пока `price_cap > 0`
- [ ] При `pve.reward.type_filter_enabled = false` поведение совпадает с дофиксовым (фильтр по типу и потолок не применяются)
- [ ] Литерала `5000` в файле не осталось; порог читается из `pve.reward.expensive_threshold`
- [ ] Пустой пул после фильтрации не роняет `grantRewards` — золото/статы/ресурсы выдаются
- [ ] `whereIn`/`where` не накапливаются между двумя вызовами метода в одном процессе

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Services/PVE/RewardService.php`

## Implementation notes

- `RewardService::__construct(?GameSettingsService $settings = null)` — DI-параметр по
  образцу `CraftInsuranceService`, дефолт `new GameSettingsService()`, не меняет вызывающих
  (`new RewardService()` во всех трёх местах остаётся валиден).
- Четыре приватных геттера-парсера, имена ниже — их читает story `-03`:
  - `typeFilterEnabled(): bool` — ключ `pve.reward.type_filter_enabled`, ручной разбор
    `is_bool → is_numeric → in_array(strtolower(...), ['1','true','yes','on'])`, копия
    `CraftInsuranceService::enabled()`.
  - `eligibleCraftTypes(): array` (`list<string>`) — ключ `pve.reward.craft_types`, CSV →
    `explode(',')` + `trim()` + отброс пустых, фолбэк на `DEFAULT_CRAFT_TYPES` — копия
    `CraftInsuranceService::eligibleTypes()`.
  - `priceCap(): int` — ключ `pve.reward.price_cap`, `0` = без потолка.
  - `expensiveThreshold(): int` — ключ `pve.reward.expensive_threshold`, заменяет оба
    инлайновых литерала 5000 в `getRandomCraftedItems`.
  - Дефолты класса — `DEFAULT_CRAFT_TYPES = 'drug,food,component,tool,weapon,clothing'`,
    `DEFAULT_PRICE_CAP = 10000`, `DEFAULT_EXPENSIVE_THRESHOLD = 5000`,
    `DEFAULT_TYPE_FILTER_ENABLED = true` — совпадают буква в букву с контрактом `plan.md`.
- `getRandomCraftedItems()` теперь берёт **свежий** `new CraftedItemsModel()` на каждый
  вызов вместо общего `$this->craftedItemModel` (убрана как неиспользуемая property) —
  закрывает CI4 builder-state quirk для `AutoPveHandler`, крутящего `grantRewards()` в кроне.
  Фильтрация: `where('price >=/<', expensiveThreshold())` всегда; при `typeFilterEnabled()`
  дополнительно `whereIn('type', eligibleCraftTypes())` и, если `priceCap() > 0`,
  `where('price <=', priceCap())`.
- Единственный оставшийся текст `5000` в файле — значение константы-дефолта
  `DEFAULT_EXPENSIVE_THRESHOLD` (требование `plan.md`, совпадает со story `-01`) и упоминание
  в doc-комментарии; магического литерала в самой логике `where()` больше нет.
- Не трогал: сигнатуру `grantRewards()`, цикл записи `CraftedItemsLogModel`/`baseCharges()`,
  вызывающие файлы, `findAll()+shuffle()`.

## Findings
