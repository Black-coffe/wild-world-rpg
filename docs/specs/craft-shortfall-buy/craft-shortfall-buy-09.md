---
story: craft-shortfall-buy-09
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 3
blocked_by: [craft-shortfall-buy-06]
---

# Экран подтверждения и проведение докупки

## Goal
Игрок тапает «Докупить и собрать», видит полную раскладку последствий и подтверждает. Сделка
проводится в правильном порядке: сначала проверяется, что крафт вообще может стартовать, потом
покупается недостающее, потом стартует сборка. Есть вторая дверь — «просто добрать» в рюкзак,
без немедленного старта.

## Requirements
> сделать возможность крафтить даже если недостаточно какого-то материала или компонента для крафта вещи
> и процент налога за опт указываем в тексте, игрок должен видеть и понимать чего не хватает и сколько за это придлется заплатить!
> От конвейера защищает не доля, а лимит штук: в первой версии докупка только на 1 шт., ручка в админке.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/CraftShortfallBuyAction.php
- app/Config/CallbackRoutes.php
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- tests/unit/Controllers/Telegram/CraftShortfallBuyActionTest.php

## Non-goals
- Не менять существующий путь старта крафта для тех, кому ресурсов хватает.
- Не изобретать собственный расчёт цены и не читать сырые поля БД: всё через `quote()`.
- Не проводить покупку своим SQL — только `ResourceTradeService::buyResource()`, которая уже атомарна, уже держит гейты и уже пишет в журнал.
- Не поднимать лимит штук выше настройки: в первой версии он равен единице.

## Map slice
memory/map/craft.md, memory/map/telegram.md; ADR-167 (симметрия эксклюзивных задач), ADR-168 (метка источника).

## Acceptance criteria
- [ ] 🔴 Порядок соблюдён: проверка возможности старта → покупка → старт. Если старт невозможен (занят эксклюзивный слот, нет очереди, не хватает постройки или золота сборки) — **золото не списывается вовсе**.
- [ ] Цена пересчитывается в момент подтверждения; при изменении показывается новая и задаётся вопрос заново, а не списывается старая.
- [ ] Если покупка частично прошла, а дальше случился отказ — состояние откатывается, частичная оплата не остаётся у игрока на руках как потеря.
- [ ] На экране подтверждения сказано до тапа: отмена крафта вернёт ресурсы, но золото не вернётся никогда.
- [ ] Если покупка съедает больше половины золота — показана честная строка последствия и кнопка отказаться.
- [ ] Кнопка «просто добрать» кладёт купленное в рюкзак и не стартует сборку.
- [ ] Событие докупки пишется в журнал действий с суммой, долей и предметом — иначе судить о пороге тревоги будет нечем.
- [ ] Ни одна кнопка не стоит в ряду одна.
- [ ] Метка источника наследуется существующим механизмом, `callback_data` не разъезжается с роутингом.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/`

## Implementation notes

- `GenericCraftActionStart.php` — все гейты старта КРОМЕ проверки сырья/крафт-компонентов
  вынесены в новый публичный `checkCanStartWithoutMaterials(recipeKey, recipe, character,
  taskRow, quantity): ?string`; `handle()` теперь зовёт его же (не копия). Добавлены
  `characterIntField()`/`recipeIntField()` — типизированные int-геттеры вместо `(int)
  $mixed[...]`, иначе union `array|CharacterEntity` даёт `mixed` на любом ArrayAccess-чтении
  под PHPStan L9 (см. память `feedback_entity_strict_array_typehint_trap`).
- `CraftShortfallBuyAction.php` (новый) — три callback'а одним action (`craftBuy`/
  `craftBuyGo`/`craftBuyOnly`). Порядок сделки закодирован в одном методе
  `attemptStartCraft()`: гейт → покупка, вызывается только из него. Цена — `priceChanged()`
  сравнивает свежий `quote()->total` с суммой, закэшированной в момент показа экрана
  (`Config\Services::cache()`, TTL 50с); при расхождении — переспрашивает, не списывает.
  Старт после покупки — не копия логики, а прямой вызов `GenericCraftActionStart::handle()`
  с синтетическим `CallbackQuery` (`genericCraft_<Key>_<qty>`, тот же raw_data + chat/message).
  Лимит штук — `craft.shortfall_buy.max_units_per_purchase` (default 1), клампится в `handle()`.
  Событие докупки пишет `logActivity('CRAFT_SHORTFALL_BUY', "recipe=... items=... spent=...
  share=...")`.
- `CallbackRoutes.php` — три exact-route ключа (`craftBuy`, `craftBuyGo`, `craftBuyOnly`) на
  один класс.
- Тесты (`CraftShortfallBuyActionTest`, 13 тестов) через тест-дубль, зеркалящий
  `ShuffleResourcesActionTestDouble`: без БД/Telegram, спаит `canStartError()`/
  `executePurchase()` в `callLog`, проверяет порядок (гейт раньше покупки, покупка НЕ
  происходит при отказе гейта), `priceChanged()`, текст/клавиатуру подтверждения.
- Компромисс: `showConfirmation()`/`handle()` не покрыты behavioral-тестом (реальный вызов
  бьёт в Telegram `Request::sendMessage` и БД `TaskModel`/`GenericCraftActionStart`) — как и
  у существующих action-тестов в этом дереве (`ShuffleResourcesActionTest`), unit-слой
  проверяет извлечённую чистую логику; Tier-3 нужен для полного прохода через реальный бот.
- Story 08 (блок «Докупить у торговца» в `CraftShortageService`, откуда должна приходить
  кнопка `craftBuy_...`) на момент этой сессии не реализована (status: todo) — эта story не
  трогала `CraftShortageService.php` (вне `## Files`); вход в докупку пока достижим только
  прямым `callback_data`, discoverability закрывает story 08.
- `phpstan-baseline.neon` осиротела запись `cast.int count: 3` для `GenericCraftActionStart.php`
  (после рефакторинга в файле реально 1 неустранимый mixed-cast, не 3) — по указанию не
  трогаю файл, оставляю на сведение.

## Findings
