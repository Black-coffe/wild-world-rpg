---
story: hotfix-bulk-confirm-once-01
spec: hotfix-bulk-confirm-once
status: todo
returned:
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

## Findings
