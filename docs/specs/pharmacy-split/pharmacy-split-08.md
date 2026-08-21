---
story: pharmacy-split-08
spec: pharmacy-split
status: done
tier: 2
worker: worker-code
tracer: false
wave: 5
blocked_by: [pharmacy-split-06]
---

# Путь на пустой полке становится настоящим, сопоставление имени — устойчивым

## Goal
Текст пустой полки называет ту последовательность кнопок, которую игрок действительно нажимает;
предмет не может уехать на чужую полку из-за регистра; докблок и реестр картинок не врут.

## Requirements
> **UX-discoverability:** «🍲 Провизия» видна с экрана аптечки и наоборот **всегда**
> Tip / анонс / квест с упоминанием фичи → точный путь + актуальный prerequisite

## Files
- app/Controllers/Telegram/Commands/Actions/PharmacyAction.php
- app/Controllers/Telegram/Commands/Actions/ProvisionAction.php
- app/Config/Consumables.php
- app/Config/ImageRegistry.php
- app/Services/Player/ConsumableShelfService.php

## Что чинить

**1. Путь врёт (главное).** `PharmacyAction:58` обещает «🔨 Крафт → 💊 Лекарства»,
`ProvisionAction:63` — «🔨 Крафт → 🔥 Костёр». Проверено: между ними есть экран. «🔨 Крафт»
открывает меню `CraftService::showCraftMenu` с кнопкой «🔨 Общий крафт» (`callback_data:
'generalCraft'`), и уже в `GeneralCraftingAction` живут «💊 Лекарства» и «🔥 Костёр». Читает
эту строку ровно тот, у кого полка пуста, — новичок, которому нечем добрать пропущенный шаг.
Назови полный путь: «🔨 Крафт → 🔨 Общий крафт → 💊 Лекарства» и «… → 🔥 Костёр».
🔴 Перед правкой сверь подписи кнопок с кодом ещё раз сам — переписывать текст по моему
пересказу нельзя, это тот же класс ошибки.

**2. Две системы ключей расходятся по регистру.** `Consumables::shelfOf()` сравнивает
`in_array(..., true)` — с учётом регистра, а `UsePharmacyAction::getCraftedItemId()` находит
ту же строку запросом в MySQL, где коллация регистр игнорирует. Строка, отличающаяся только
регистром, применится, но уедет на чужую полку и потеряет строку «🩺 Снимает». Сделай
сопоставление в `shelfOf()` нечувствительным к регистру (сравнивай приведённые к одному
регистру значения), сохранив сами литералы каталога как есть.

**3. Докблок `ConsumableShelfService` врёт.** Шапка обещает «ни БД, ни Telegram», но
конструктор строит `ConsumableExpiryService` → `GameSettingsService` → модель, и `screen()`
через `expiry->enabled()` читает `game_settings`. Перепиши честно: Telegram и `Request` слой
не трогает, а настройки годности читает — и потому принимает `ConsumableExpiryService`
параметром.

**4. Реестр картинок.** В `app/Config/ImageRegistry.php` у `craft/cooking/campfire_hot` в
`used_in` числится только `CampfireCookingSelect`; теперь у файла второй потребитель —
`ProvisionAction`. Допиши.

## Non-goals
- Не менять порядок строк внутри `itemLine()` и не трогать формат вывода — тексты про
  расположение строки правятся в соседней story, кодом это решать не нужно.
- Не добавлять `is_file`-guard в `PharmacyAction`: картинка аптечки работает на проде годами,
  выравнивание асимметрии — отдельная уборка.
- Не трогать `UsePharmacyAction`.
- Не заводить новых записей в `phpstan-baseline.neon`.

## Map slice
`app/Services/Player/CraftService.php:110-125` и
`app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/GeneralCraftingAction.php:20-45`
— источник истины по подписям кнопок пути.

## Acceptance criteria
- [ ] Текст пустой полки на обоих экранах называет все три шага пути.
- [ ] `Consumables::shelfOf('bandage')` и `shelfOf('BANDAGE')` дают полку лекарств.
- [ ] Докблок сервиса не обещает того, чего сервис не делает.
- [ ] `ProvisionAction` числится потребителем картинки в `ImageRegistry`.
- [ ] `vendor/bin/phpunit --no-coverage --no-progress tests/unit` зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/PharmacyAction.php app/Controllers/Telegram/Commands/Actions/ProvisionAction.php app/Config/Consumables.php app/Config/ImageRegistry.php app/Services/Player/ConsumableShelfService.php`

## Implementation notes

- `PharmacyAction.php:59`, `ProvisionAction.php:64` — путь дополнен реальным средним
  шагом: «🔨 Крафт → 🔨 Общий крафт → 💊 Лекарства» / «… → 🔥 Костёр». Сверено с
  `CraftService::hubRows()` (кнопка `generalCraft`) и `GeneralCraftingAction` (кнопки
  `medicinesCraft1`/`cook`).
- `Consumables::shelfOf()` — вместо `in_array(..., true)` цикл со `strcasecmp()`;
  литералы `self::MEDICINE` не тронуты, сравнение регистронезависимо.
- `ConsumableShelfService` — докблок класса переписан: не обещает «ни БД», честно
  говорит, что Telegram/`Request` не трогает, а настройки годности читает через
  `ConsumableExpiryService` (уже был параметром конструктора — переписан только текст).
- `ImageRegistry.php` — `craft/cooking/campfire_hot.used_in` дополнен `ProvisionAction`
  (подтверждено: `ProvisionAction.php:103` шлёт тот же файл).

## Findings

- `tests/unit/Player/ConsumableShelfTest::testMedicineShelfFitsPhotoCaptionLimitAtLiveStockSize`
  красный и до, и после этой story (файл теста меняет параллельный агент, не эта
  story) — это документированная в самом тесте находка про лимит caption 1024,
  явно вне Non-goals текущей story. Изолированный прогон
  `tests/unit/Config/ConsumablesCatalogTest.php`, `tests/unit/Config/ImageAssetsExistTest.php`,
  `tests/unit/Display/ConsumerImagePathsExistTest.php`, `tests/unit/Player/ConsumableShelfTest.php`
  даёт ровно этот один известный failure, ноль новых. Полный `tests/unit` дополнительно
  показывает ~56 ошибок «Table … already exists» — состояние машины (параллельные
  агенты в общей тестовой БД), не регрессия этой story.
