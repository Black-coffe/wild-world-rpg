---
story: pharmacy-split-02
spec: pharmacy-split
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [pharmacy-split-01]
---

# Две полки в игре: «💊 Аптечка» и «🍲 Провизия», и переход между ними всегда виден

## Goal
`PharmacyAction` показывает только лекарства, новый `ProvisionAction` (callback `provision`) —
еду и питьё. С каждого экрана видна кнопка на соседний — с количеством, — и она видна даже
когда соседняя полка пуста. Применение предметов работает как раньше.

## Requirements
> Возможно продукты и консервы надо убрать из аптечки в отдельную кнопку — не дело
> пересыпать тушёнку аспирином ☺️
> **UX-discoverability:** «🍲 Провизия» видна с экрана аптечки и наоборот **всегда**, даже
> когда соответствующий список пуст
> `UsePharmacyAction` не трогаем: оба экрана шлют тот же `usePharmacy_<name_eng>`.

## Files
- app/Controllers/Telegram/Commands/Actions/PharmacyAction.php
- app/Controllers/Telegram/Commands/Actions/ProvisionAction.php
- app/Config/CallbackRoutes.php
- phpstan-baseline.neon

## Как

Выборка в обоих экранах прежняя (`crafted_items.type='drug'`, `quantity > 0`) — делит её
`ConsumableShelfService::split()` из story 01. Отрисовка — `screen()` того же сервиса.

- **Аптечка** (`pharmacy`): раздел активных ран остаётся сверху (как сейчас,
  `DebuffService::active()` + `describe()`), дальше — только лекарства.
- **Провизия** (`provision`): тот же формат, раздела ран нет.
- Кнопка-сосед: `🍲 Провизия (N)` / `💊 Аптечка (N)`, где N — количество ВИДОВ на соседней
  полке. При N=0 кнопка остаётся, но ведёт на честный пустой экран с объяснением, где это
  берут («🔨 Крафт → 🔥 Костёр» для провизии, «🔨 Крафт → 💊 Лекарства» для аптечки) — а не
  в тупик.
- Пустая своя полка: текст объясняет, где взять, и кнопка-сосед на месте.
- Возвратные кнопки прежние: «🧑‍🌾 Действия 🛠️» и «◀️ Я» при `BotMenuService::meHubEnabled()`.
- Картинка аптечки прежняя (`many_medicinal_things.jpg`). Для провизии — существующий на
  проде файл `uploads/telegram/craft/cooking/campfire_hot.jpg` (котелок на костре, общий для
  готовки). 🔴 Перед отправкой проверь `is_file(FCPATH . 'uploads/telegram/craft/cooking/campfire_hot.jpg')`
  и при отсутствии отправляй БЕЗ фото, текстом: файлы картинок в репозитории не лежат
  (`public/uploads/telegram/craft/` локально пуст), они доезжают только деплоем, а `encodeFile`
  на несуществующем пути роняет экран целиком. Новых картинок не генерируем.
- Отправка — `MediaSender::sendPhotoOrText` (media-off, ADR-020), `parse_mode: 'Markdown'`.

## Non-goals
- Не менять `UsePharmacyAction` — ни эффекты, ни возвратные кнопки после применения.
- Не менять `crafted_items.type` и не писать миграций схемы.
- Не добавлять кнопку «Провизия» на другие экраны (карта, персонаж, события) — вход в еду
  идёт с аптечки; расширение точек входа — отдельное решение.
- Не собирать клавиатуру одиночками: строки по 2 кнопки, как сейчас (`array_chunk(..., 2)`),
  хвост правит `KeyboardNormalizer` централизованно — руками его не дублировать.
- Не генерировать новые изображения и не трогать `Config\ImageRegistry`.

## Map slice
`app/Controllers/Telegram/Commands/Actions/PharmacyAction.php` целиком (образец экрана),
`app/Config/CallbackRoutes.php` строки 255–265 (регистрация роутов).

## Acceptance criteria
- [ ] На экране «Аптечка» нет ни одного предмета провизии (тушёнка, каша, уха, квас, отвары).
- [ ] На экране «Провизия» нет ни одного лекарства.
- [ ] Каждое лекарство печатает строку «🩺 *Снимает:* …», если что-то снимает.
- [ ] Кнопка соседней полки присутствует на обоих экранах при ЛЮБОМ наборе предметов,
      включая «есть только еда» и «нет вообще ничего».
- [ ] Callback `provision` зарегистрирован в `Config\CallbackRoutes`.
- [ ] Кнопки применения по-прежнему `usePharmacy_<name_eng>` — применение не сломано.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/PharmacyAction.php app/Controllers/Telegram/Commands/Actions/ProvisionAction.php app/Config/CallbackRoutes.php`

## Implementation notes

- `PharmacyAction`: один запрос `type='drug'` как раньше, дальше `ConsumableShelfService::split()`
  делит на `medicine`/`provision`; рендер полки — `screen(SHELF_MEDICINE, $medicine, $activeDebuffLines)`.
  Раздел активных ран собирается контроллером (`DebuffService::active()+describe()`) и передаётся
  третьим аргументом — как и предупреждал автор story 01. Пустая полка лекарств → текст с путём
  «🔨 Крафт → 💊 Лекарства», кнопка-сосед «🍲 Провизия (N)» на месте.
- `ProvisionAction` — новый файл, зеркало `PharmacyAction`: тот же запрос, `screen(SHELF_PROVISION, ...)`
  без ран. Картинка — `uploads/telegram/craft/cooking/campfire_hot.jpg`, отправка через `is_file(FCPATH…)`
  guard (по образцу `MeteorShelterCraft1Action`): файл есть → `MediaSender::sendPhotoOrText`, нет →
  `Request::sendMessage` текстом. `public/uploads/telegram/craft/` в репозитории пуст (ассеты доезжают
  только деплоем) — guard обязателен, а не подстраховка на всякий случай.
- `CallbackRoutes.php`: добавлена строка `'provision' => ProvisionAction::class` рядом с `pharmacy`.
- Кнопка-сосед présent на ОБОИХ экранах при любом состоянии полок, включая «нет вообще ничего» —
  обе ветки (own-empty и own-non-empty) добавляют её безусловно.
- `UsePharmacyAction` не тронут — оба экрана шлют тот же `usePharmacy_<name_eng>`.
- phpstan-baseline.neon пришлось перегенерировать (`--generate-baseline`): убрал устаревшие записи
  под старым телом `PharmacyAction` (foreach/json_decode/encapsed-ошибки исчезли вместе с кодом,
  который их вызывал). `ProvisionAction` — новый файл, в baseline не заводился: свойства и параметр
  конструктора типизированы (`CraftedItemsLogModel`/`CraftedItemsModel`/`CallbackQuery`, как у
  `BaseAction`); из-за этого проступил `argument.type` на вызове `split()` (модель типизирована →
  PHPStan видит менее узкий тип, чем реальный рантайм) — снят узкой докблок-аннотацией
  `@var list<array<string, mixed>>` над `findAll()`, тем же приёмом, что уже используется в
  `CraftTreeService::fetchAll()` (не widening и не сокрытие бага — ключи выборки действительно все
  строковые, именованные алиасы select()).
- Guide/Tips-вердикт по этой задаче уже вынесен в `brief.md` (оба «ДА») — сами разделы/сиды не
  входят в Files story 02, это story 03 по неймингу спеки (следующая волна).

## Findings
