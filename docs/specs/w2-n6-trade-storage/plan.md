# W2.N6 — Торговля сырьём и склад базы в /play (plan)

**Tier:** 2 · **Spec slug:** `w2-n6-trade-storage` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-189 (мост — фолбэк), ADR-096 (оптовая продажа), ADR-157 (спред), ADR-059 Q5 (выдача со склада), ADR-020 (media-off), ROADMAP §2.7
**Depends on:** w2-n1…w2-n5 (нативные экраны `/play/view`, `web_play_intents`, док), v0.51.687

## Goal
Второй клиент к уже существующим механикам: торговля сырьём (магазин → продать по редкости / купить / оптом)
и склад базы (список, забрать всё или вид, положить из рюкзака) получают нейтральные модели экранов и операции
в ядре; бот рисует из них то же, что сейчас, веб `/play` — нативные экраны `view=shop` и `view=storage`
вместо моста. Проверка «на базе» и защита от двойной сделки уходят из handler'ов в ядро. Крафт и снаряжение
у торговца остаются в боте и в вебе идут мостом — это N6b.

## Assumptions
- Торговля к месту не привязана (в боте магазин доступен отовсюду, `ShopAction` без гейта) — в вебе так же.
- В вебе «своё число» — числовое поле с потолком (как `qty`/`max_qty` в крафте W2.N3); `qty > max` — отказ в ядре.
  ForceReply-путь бота (`GenericmessageCommand::handleTradeReply`, маркеры `SELL:/BUY:`) не меняется, только
  зовёт то же ядро.
- Склад: выдача и сдача сейчас идут транзакцией в `BaseStorageListAction`/`BaseStorageDepositAction`; переносим
  их в сервис `App\Services\Bases\BaseStorageService` без смены правил (вес/вместимость, мульти-база через
  `BaseScopeResolver` — как сейчас в handler'ах; если handler базу не скоупит — не добавляем, фиксируем находкой).
- Повтор в вебе гасит `web_play_intents` (как N3–N5); в ядре — условная запись/`FOR UPDATE`, чтобы двойной тап
  бота и веб одновременно не списали дважды. Если `ResourceTradeService` это уже держит
  (`ResourceTradeGoldRaceTest`) — не переписываем, а доказываем тестом.
- Рассылки и анонса нет (ROADMAP §2.6: только при W-REG). Числа баланса не появляются — цены из `TradePricingService`/GameSettings.
- Кнопка «📦 Склад базы» в нативном экране базы (`WebNativeScreenService.php:726`) и маршрут `baseStorageList`
  из списка моста (`:141`, `:186`) переводятся на нативный `view=storage`; «💰 Продать торговцу» у снаряжения
  (`native_gear.php:158`) остаётся мостом (N6b).
- Журнал ROADMAP §5 дописывается строками W2.N1–N5 (по тегам из hot.md) и W2.N6 при шипе.

## Stories

**Wave 1**
- `w2-n6-trade-storage-01` — ядро склада `BaseStorageService` (модель списка, выдача/сдача, гейт «на базе»), бот-handler'ы склада — рендереры, паритет.

**Wave 2**
- `w2-n6-trade-storage-02` — модели экранов магазина сырья (хаб, редкость, карточка с ценой и пресетами, покупка, опт) поверх `ResourceTradeService`; бот-handler'ы — рендереры, паритет; ForceReply цел.

**Wave 3**
- `w2-n6-trade-storage-03` — веб: `view=shop`/`view=storage`, операции с дедупом intent, вьюхи, док, ui-kit, журнал ROADMAP.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei, 2026-10-08
**Briefed:**
**Branch:** vulyk/w2-n6-trade-storage
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-10-08, at 5e7fffd0, pack faccd6a8f1de
**Shipped:**
