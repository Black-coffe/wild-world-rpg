---
story: craft-shortfall-buy-06
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: []
---

# CraftShortfallBuyService: расчёт недостачи, цены и наценки

## Goal
Появляется единственный источник чисел для докупки: сколько не хватает, почём это у торговца,
какая наценка, сколько итого, что из недостачи докупить нельзя и почему. Экран и сделка будут
звать один и тот же метод — число на экране обязано быть числом сделки. Экранов эта story не
трогает вовсе.

## Requirements
> система считает по текущему курсу, сколько будет столить купить у продлавца на рынке
> если есть в наличии то считаем общую сумму и накидываем сверху процент за оптовую поставку
> Базовый процент + добавка, пропорциональная тому, какую долю рецепта закрывает докупка.
> Потолка доли нет. Вместо стены — ценовой сигнал: наценка растёт с долей докупки от 15% до 50%
> Проверка централизованная, тем же сервисом, что считает цену на экране.

## Files
- app/Services/Craft/CraftShortfallBuyService.php
- app/Services/Craft/CraftShortfallQuote.php
- tests/unit/Services/Craft/CraftShortfallBuyServiceTest.php

## Non-goals
- Не рисовать текст и не собирать клавиатуру — тексты отказов живут в story 08.
- Не проводить сделку и не списывать золото — это story 09.
- Не включать в докупку крафтовые компоненты: в первой версии только сырьё (замер ADR-158: 137 отказов из 144 — по сырью, 8 — по компоненту). Компонент помечается непокупаемым с причиной `not_on_sale`.
- Не изобретать собственную формулу цены сырья: только `ResourceTradeService::unitPrice()`.
- Не вводить потолок доли докупки — владелец его снял.

## Map slice
memory/map/craft.md; ADR-171 (пул), ADR-175 (живые цены), ADR-158 (экран нехватки).

## Acceptance criteria
- [ ] Контракт `quote()` и поля DTO — ровно как в `## Contracts` плана.
- [ ] `share` считается в деньгах (`base/full`), а не в штуках: одна единица дорогого сырья не весит столько же, сколько полсотни дешёвого.
- [ ] Наценка в DTO — **фактическая, посчитанная обратно из `total`**. Тест на граничный случай: при `base = 1` и наценке 15% показанный процент совпадает с реально списываемой суммой, а не расходится вдвое.
- [ ] `min_markup_gold` не даёт наценке округлиться в ноль на копеечных позициях.
- [ ] Непокупаемая позиция (`is_tradeable = 0`, `level_required` выше уровня персонажа, компонент не на витрине) не входит в `base`, но входит в `full`, и делает `available = false` с конкретной причиной.
- [ ] Деления на ноль нет ни при `full = 0`, ни при пустом списке недостачи.
- [ ] Гейт рентабельности реализован, читается из настройки и по умолчанию выключен.
- [ ] При выключенном килсвитче `quote()` возвращает `refusal = killswitch_off`, а не бросает исключение.
- [ ] Тесты проверяют поведение на реальных граничных значениях, а не совпадение формулы с формулой.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Craft/`

## Implementation notes

- `App\Services\Craft\CraftShortfallBuyService::quote()` — единая формула (`base`/`full`
  в деньгах, `share = base/full` без потолка, `markup = base_markup_pct + slope_markup_pct
  × share`, `total = max(base + min_markup_gold, ceil(base × (1 + markup)))`); `markupPct`
  в DTO посчитан обратно из `total`, не из формулы.
- `App\Services\Craft\CraftShortfallQuote` — readonly DTO, поля ровно по контракту плана.
- Recipe читается как уже разобранные `resources`/`crafted_items` (`array<string,int>`),
  как их отдаёт `Config\CraftRecipes` и потребляет `GenericCraftActionStart::checkResources()`
  — сервис не парсит сырую строку `required_resources` заново.
- Крафтовые компоненты (`crafted_items`) никогда не покупаемы: если по компоненту есть
  недостача — строка с `blockReason=not_on_sale`, `unitPrice=0`, вклад в `base`/`full`
  нулевой (цены крафта в этой версии не существует, изобретать её запрещено Non-goals).
- Гейт рентабельности (ревью-правка): считает РЕАЛЬНУЮ выручку по `$recipe['item_name_eng']` →
  `crafted_items.price` → `CraftTradeService::sellUnitPrice()` (карма персонажа + складской
  бонус, ADR-157/ADR-085 — та же формула, что видит экран продажи, не своя копия). При
  `profit_gate_enabled=false` (дефолт) не участвует в отказе; при `true` реально сравнивает
  `total` с выручкой и способен как заблокировать, так и пропустить сделку — оба направления
  покрыты тестами. Если цену определить нельзя (нет `item_name_eng`, рецепт не в
  `crafted_items`, `price` не число) — гейт **fail-closed** (`profit_gate`), а не молчаливый
  пропуск: «выключен по умолчанию» обязано значить «исправен при включении».
- `refusal`-приоритет: `killswitch_off` (ранний return) → первая блокирующая позиция
  (`not_tradeable`/`level_required`/`not_on_sale`) → `profit_gate` → `min_gold`.
- phpstan L9 потребовал явного фильтра `is_string($key)` в `craftedRow()` — `getRowByName()`
  легаси-модели не декларирует тип, PHPStan видел ключи как `array-key`, не `string`; каст
  запрещён правилом, сузили циклом.
- Тесты (17, `CraftShortfallBuyServiceTest`) держат все двойники (`ResourceModel`,
  `ResourcePoolService`, `CraftedItemsModel`, `CraftedItemsLogModel`, `GameSettingsModel`)
  через подмену протектед/паблик методов — паттерн зеркалит `CraftCardHelperTest`/
  `CraftDurationServiceTest`. `ResourceTradeService`/`TradePricingService`/
  `CraftTradeService`/`WarehouseSellBonusService` — все `final`, поэтому не двойники, а
  настоящие инстансы на общем `GameSettingsService`: нейтральная карма (`trading_karma=100`,
  `GameBalance::$startingTradingKarma`) и killswitch склада off-by-default делают
  `sellUnitPrice(price, 100) === price` — детерминированная выручка без похода в БД
  (паттерн — `CraftTradeServiceTest`).
- Флагманский граничный случай подтверждён числом: `base=1`, номинал 15% → `total=2`,
  `markupPct=100` (не 15) — тест `testMarkupPctIsActualNotNominalOnSingleUnitGap`.
- Player-facing вердикт: эта story не трогает экраны и коллбэки (Non-goals), поэтому
  UX-discoverability/guide/tips-ревью откладываются на story 08/09/10/11 по плану —
  здесь вердикт не требуется (чистая логика без новой игровой поверхности).

## Findings
