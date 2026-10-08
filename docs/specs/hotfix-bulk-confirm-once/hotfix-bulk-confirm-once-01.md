---
story: hotfix-bulk-confirm-once-01
spec: hotfix-bulk-confirm-once
status: done
returned: DONE
tier: 1
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Подтверждение оптовой продажи исполняется один раз

## Goal
`ResourceTradeService::bulkSellPreview()` отдаёт отпечаток плана (8 hex от `charResId:quantity` строк плана);
`BulkSellAction` кладёт его в callback подтверждения `bulkSell_go_all_{pct}_{tok}` /
`bulkSell_go_rarity_{r}_{pct}_{tok}`. `bulkSellResources(..., ?string $confirmToken)` в транзакции читает
строки рюкзака `FOR UPDATE`, считает отпечаток заново и при несовпадении откатывает: ничего не продано,
отказ «Сделка уже выполнена или запас изменился — открой оптовую продажу заново.» Кнопка без отпечатка —
«кнопка устарела». Условное списание утреннего хотфикса остаётся вторым барьером.

## Requirements
> Оптовая продажа («🧺 Всё», «💰 N%»), похоже, всё ещё платит дважды при двойном нажатии. Утренний хотфикс закрыл это только для продажи одного ресурса.

> Отдельный хотфикс (Рекомендую)

## Files
- app/Services/Player/Trade/ResourceTradeService.php
- app/Controllers/Telegram/Commands/Actions/Sell/BulkSellAction.php
- tests/database/BulkSellResourcesTest.php
- tests/database/BulkSellConfirmOnceTest.php
- tests/unit/Services/Trade/BulkSellPlanTest.php
- tests/unit/Services/Player/Trade/ResourceBankInsertRaceTest.php
- docs/defects/README.md
- docs/defects/confirm-repeats-irreversible.md
- docs/defects/fixtures/confirm-repeats-irreversible-original.php
- docs/defects/fixtures/confirm-repeats-irreversible-neighbour.php
- scripts/defects-confirm-once-check.php
- phpstan-baseline.neon

## Non-goals
- Не трогать тексты превью и итога, доли, цены, банк, поштучную продажу.
- Не заводить таблицу/колонку под токены.
- Не чинить другие confirm-экраны (крафт, снаряжение) — если check их найдёт, перечислить в `## Findings`.

## Map slice
`memory/map/player.md`, ноты `tech-writing/services/ResourceTradeService.md`, `tech-writing/handlers/sell/BulkSellAction.md`

## Acceptance criteria
- [ ] Два подряд `bulkSellResources` с одним отпечатком (50%): второй — отказ, запас и золото как после одной сделки (тест).
- [ ] «Одновременный» случай: план второго вызова снят до первой сделки (подмена снимка), отпечаток тот же — после первой сделки второй под блокировкой видит другой запас и откатывается (тест).
- [ ] Бот: два нажатия одной кнопки `bulkSell_go_…_<tok>` — одна запись `BULK_SELL`, второй экран — отказ с кнопками назад к продаже (тест через handler).
- [ ] Кнопка подтверждения старого формата — отказ «кнопка устарела», ничего не продано (тест).
- [ ] Тексты превью/итога прежние; callback ≤ 64 байт (тест на самый длинный).
- [ ] Карточка `confirm-repeats-irreversible`: цитата владельца, `check:` падает на обеих фикстурах и проходит на `app/Controllers/Telegram/Commands/Actions/Sell/BulkSellAction.php`; `bash scripts/defects-check.sh` зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- Отпечаток — `ResourceTradeService::bulkConfirmToken()`: 8 hex sha1 от доли, редкости и `charResId:quantity`
  продаваемых строк, отсортированных по id (превью читает строки моделью, сделка — `FOR UPDATE` в своём порядке).
  `bulkSellPreview()` отдаёт `token`; `bulkSellResources(..., ?int $rarity, string $confirmToken)` — токен
  **обязателен**: в транзакции план пересчитывается под `FOR UPDATE`, отпечаток сверяется `hash_equals`, иначе откат.
  Выбор «обязателен», а не «опционален»: опциональный токен оставил бы ядро открытым для забывчивого вызывающего —
  ровно соседняя форма класса (веб W2.N6). Цена — `ResourceBankInsertRaceTest` добавлен в `## Files` (одна строка вызова).
- Бот: токен прямо в строке callback (`bulkSell_go_{$goScope}_{$preview['token']}`), чтобы check видел его там же;
  разбор `go_all_{pct}_{tok}` (5 частей) / `go_rarity_{r}_{pct}_{tok}` (6). Старая кнопка без токена — «кнопка устарела».
- Тест «одновременного» случая на уровне сервиса — `testSameConfirmTwiceSellsOnce`: оба вызова несут отпечаток одного
  превью (так и выглядит двойное нажатие). Настоящая параллельность — PoC двумя процессами (scratchpad, не в репо,
  харнесс утреннего хотфикса): 3000 видов × 10, «50%», одна кнопка — 3/3 прогона золото 15 000 и остаток 15 000,
  второй процесс ждёт блокировку и получает отказ; подряд — то же.
- `testBulkSellFromStaleSnapshotRollsBackEverything` (утренний) теперь отказывает раньше — на отпечатке (снимок 1000 шт.
  против 55 под блокировкой); его утверждения те же. Условное списание остаётся вторым барьером, но этим тестом больше
  не достигается — честно: отдельного теста на него поверх отпечатка нет (достичь его можно только гонкой мимо FOR UPDATE).
- Карточка `docs/defects/confirm-repeats-irreversible.md` + `scripts/defects-confirm-once-check.php`: ловит callback
  `bulkSell_go_…` без токена в строке и вызов `bulkSellResources()` с < 4 аргументами. Обе фикстуры падают, `app/` чист,
  `bash scripts/defects-check.sh` GREEN. Ветка W2.N6 (`ResourceShopScreenService::bulkSell`, 3 аргумента) этим check'ом
  ловится — при merge `develop` её надо перевести на токен (story 03).
- Другие confirm-экраны (крафт, снаряжение) check не смотрит — он про опт; их повторяемость не проверял (Non-goals).
- Вне `## Files`: ноты vault `ResourceTradeService.md`, `handlers/sell/BulkSellAction.md`.
- Вердикты: guide — нет, совет — нет (фикс поведения, новой механики нет).

## Findings
