---
story: w2-n4-base-01
spec: w2-n4-base
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Ядро чтения базы: выбор, обзор, список построек; бот — рендерер

## Goal
`App\Services\Bases\BaseScreenService` отдаёт модель экрана без `chat_id`/Markdown: `resolve()`
(пикер / база / нет базы / недоступна), `overview()` (клетка, биом, на базе ли, срок, налог, декор,
покрытие Вышки, постройки строками со стопкой `amount` и `bridge_callback` карточки) и `open()`
(визит `touchVisit` + событие онбординга `recordBaseOpened`). `BaseService::showBaseInfo` и
`DetailedBaseInfoAction` рисуют из этой модели прежний текст. Кнопки ядра («🏗 Строить», карточка,
«назад») несут `_b<id>`, когда база известна.

## Requirements
> 1. База — одно ядро для бота и веба: обзор, выбор базы, список построек, карточка, старт стройки и апгрейд — сервисы с моделью экрана; handler'ы бота — рендереры, тексты и кнопки прежние (кроме п.5).
> 5. Мульти-база: обзор, «Строить», карточка, апгрейд, «назад» несут номер базы, ядро перепроверяет его в обоих клиентах.
> 6. Открытие базы в вебе = визит, как в боте; одноразовые подсказки веб-игроку — во входящие.

## Files
- app/Services/Bases/BaseScreenService.php
- app/Services/BaseService.php
- app/Services/Bases/BaseServiceMessageFormatter.php
- app/Services/Bases/BaseBuildingsList.php
- app/Controllers/Telegram/Commands/Actions/Camp/DetailedBaseInfoAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/ShowBaseInfoAction.php
- tests/database/BaseScreenServiceTest.php
- tests/unit/Camp/BaseScreenBotParityTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать 14 карточек `Camp/Buildings/*Handler.php`, склад, снос, `DeleteBaseAction`, маяки, ангар, декор, развитие: там только `bridge_callback` в модели.
- Не менять текст бота и формулу налога; расхождение налога со стопками — в Findings.
- Не трогать стройку/апгрейд (story 02) и веб (story 03).

## Map slice
`memory/map/bases.md` — Entry points, Gotchas (multibase-picker, `BaseScopeResolver`, `BaseCallbackSuffix`).

## Acceptance criteria
- [ ] Снимки бота до/после: «🏠 База» (на базе, нет баз, одна под Вышкой, пикер 2+, выбранная `_b<id>`, недоступная) и `construction` (на базе, под Вышкой, нет базы) — текст байт-в-байт; отличается только `callback_data` кнопок ядра суффиксом `_b<id>`.
- [ ] `resolve()` с чужим/неактивным/непокрытым `baseId` → `unavailable`, никогда чужая база.
- [ ] `overview()` отдаёт стопку `amount` и не делает запрос на каждое здание (без N+1).
- [ ] `open()` пишет визит и онбординг-событие ровно как бот; вызов без `chat_id` не падает, подсказка web-only доставляется через `WebDelivery`/outbox, а не теряется.
- [ ] Модель не содержит Markdown и `Request`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
