---
story: w2-n6-trade-storage-03
spec: w2-n6-trade-storage
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 3
blocked_by: [w2-n6-trade-storage-01, w2-n6-trade-storage-02]
---

# Веб /play: «🛒 Магазин» и «📦 Склад базы» нативно

## Goal
`WebNativeScreenService` получает `view=shop` (хаб, редкость, карточка, покупка, опт) и `view=storage`
(список, забрать всё или вид, положить всё или вид) из моделей story 01–02. Операции `op=sell|buy|bulk_sell|
storage_take|storage_put` дедупятся через `web_play_intents`, без JS работает PRG. Количество задаётся пресетами
и числовым полем с потолком. Вне базы склад показывает замок с путём. «Купить/продать крафт» в хабе остаются
кнопками моста. Вход «🛒 Магазин» в доке; «📦 Склад базы» в экране базы ведёт на нативный экран (`:726`),
`baseStorageList` уходит из списка моста (`:141`, `:186`). В журнал ROADMAP §5 дописываются строки W2.N1–N5 и W2.N6.

## Requirements
> 1. В /play есть «🛒 Магазин» с сырьём: продать или купить по редкости, оптом долю всех ресурсов. Количество задаётся кнопками или своим числом, цена видна до сделки. Крафт и снаряжение у торговца пока через мост.
> 2. В /play есть «📦 Склад базы»: список, забрать всё или один вид, положить из рюкзака. Не на базе кнопки не пропадают: виден замок с путём.
> 5. Всё читается без картинок. Вердикты: в /guide — нет, совет — нет (механика не меняется, это второй клиент).
> 6. Живой проход на preprod: веб и бот, сделки с точными списаниями, повтор формы без двойной сделки. Плюс строки W2.N1–N6 в журнал ROADMAP.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_shop.php
- app/Views/site/_play/native_storage.php
- app/Views/site/_play/dock.php
- public/assets/css/wildworld-ui.css
- app/Views/site/_layout/meta.php
- public/ui-kit.html
- tests/database/PlayViewControllerTest.php
- tests/unit/Views/PlayViewsTest.php
- ROADMAP.md

## Non-goals
- Не делать нативные экраны крафта и снаряжения у торговца: это мост (N6b).
- Не вводить радиусы, тени, новые шрифты (ADR-062); новый компонент сначала в `ui-kit.html`.
- Не выводить id персонажа в HTML и JSON.

## Map slice
`memory/map/website.md` → «Игра на сайте», Gotchas `/play`

## Acceptance criteria
- [ ] `view=shop` и `view=storage` рендерятся на 1440/768/375 без горизонтального скролла, консоль чистая.
- [ ] Повтор POST той же формы (тот же `intent_id`) не даёт второй сделки или перекладки (тест).
- [ ] `qty` больше потолка: отказ, ничего не списано (тест).
- [ ] Вне базы виден замок «положить и забрать можно только на базе» с путём, кнопки не исчезли (тест вьюхи).
- [ ] ROADMAP §5 содержит строки W2.N1–N6 с тегами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- Перед сборкой в ветку влит `develop` (merge `47190476`): `hotfix-bulk-confirm-once` (v0.51.689) конфликтовал со
  story 02 в `BulkSellAction`. Отпечаток плана опта теперь идёт через ядро: `bulkPreviewModel()` отдаёт `token`,
  `bulkSell(…, $confirmToken)` его требует; снимки паритета бота обновлены на кнопки с отпечатком; тест повтора
  подтверждения через сервис экранов — в `ResourceShopScreenServiceTest`.
- `WebNativeScreenService`: виды `shop`/`storage`, `shopModel()` (разделы с откатом на уровень выше при чужом
  ресурсе/редкости/доле), `shopSell()`/`shopBuy()`/`bulkSell()` и `storageModel()`/`storageTake()`/`storagePut()` —
  дедуп `intent_id` (`:sell|:buy|:bulk_sell|:storage_take|:storage_put`); `SELL_RESOURCE`/`BULK_SELL` пишутся в
  `action_log` тем же голосом, что у бота (`logDone()`, `chat_id` 0) — их читают «Куда ушло» и шаг онбординга.
- `viewForCallback()`: кнопки `shop` и `baseStorageList` любого нативного экрана (инвентарь, база) открывают нативный
  вид — контроллер подменяет `op=bridge` на вид, мост не трогается. Поэтому `native_inventory.php` и `native_base.php`
  не правились, а `baseStorageList` ушёл из `BRIDGE_ROUTES` и `BASE_BRIDGE_EXACT`. `sellCraft`/`buyCraft` — мост
  через `shop` с карточки «Я» бота.
- Количество: пресеты ядра ≤ `max_qty`, «🧺 Всё — N шт» (продажа) и поле «своё число» 1…`max_qty`; `qty` в форме —
  `\d{1,7}` (`Play::quantity()`, 4 знака крафта малы для сырья). Больше потолка — отказ ядра `over_max`.
- Новых компонентов нет: экраны собраны из `play-craft-*`, `play-kb-*`, `play-lock`, `play-native-*` — поэтому
  `wildworld-ui.css`, `meta.php` (`?v=`) и `ui-kit.html` не тронуты.
- Док: «🛒 Магазин» — последней кнопкой всегда (после фолбэка «Меню»); `assertFormsCarryCsrfAndUniqueIntent` в
  `PlayViewsTest` пропускает формы навигации на нативный вид (у них нет `intent_id` по замыслу, как у «🧑 Я»).
- ROADMAP §5: строки W2.N1–N5 с тегами из ship-коммитов (`git log --grep 'shipped v'`); строка W2.N6 — при шипе,
  с настоящим тегом.
- Вне `## Files`: `memory/map/website.md` (срез `/play`), ноты vault (`WebNativeScreenService.md`, `Play.md`,
  `ResourceShopScreenService.md`) — правило tech-writing.
- Вердикты: guide — нет, совет — нет (механика не меняется, второй клиент; бриф ask 5).

## Findings
- Одноразовая подсказка бота при первом открытии склада (`OnboardingHintService::maybeSendFirstStorageHint`) из веба
  не шлётся: ей нужен `chat_id`. Веб-игрок с Telegram получит её при первом открытии склада в боте; чисто веб-игрок —
  нет. Кандидат в хвосты (через `WebDelivery`, как `BaseScreenService::open()`).
- Кнопка «📦 Склад базы» в событиях шага на карте (`MoveService` event `storage`) — сообщение моста и жмётся через
  `/play/act` (бот), а не нативно: события шага живут на экране моста. Не регресс, отмечено.
