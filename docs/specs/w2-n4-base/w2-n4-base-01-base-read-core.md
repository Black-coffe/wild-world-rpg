---
story: w2-n4-base-01
spec: w2-n4-base
status: done
returned: DONE
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
- `App\Services\Bases\BaseScreenService`: `resolve()` (правило «🏠 База»), `resolveConstruction()` (правило «🏘 Постройки»: без пикера, две базы вне сигнала → `far` первой по id — так было у бота), `overview()`, `open()`. Отклонения от контракта плана: у состояния есть пятое значение `far` (одна база вне сигнала — у бота это отдельный экран с координатами); `bases[]` пикера — строки `coverageByBase()` как есть (`base_id,cell,name,x,y,towerLevel,distance,maxCoverage,isCovered`), без биома; `open()` принимает необязательный `$chatId` (без него — чат персонажа через `VirtualIdentityService`, у веб-игрока виртуальный → входящие).
- `resolve()` без побочных эффектов; бот зовёт `open()` перед `overview()` — порядок «визит → онбординг-подсказки → экран» прежний. Визит пишется только когда игрок на самой базе (как было).
- Бот: `BaseService::showBaseInfo` и `DetailedBaseInfoAction` рисуют из модели прежние тексты; `BaseService` отдаёт ядру свой `claimedCellModel` (шов `BasePickerTest`, подмена рефлексией, не сломан).
- N+1: `BaseBuildingsList::rows()` — строки базы + здания одним `whereIn`; `buildSummary()` переведён на него (раньше `where()->first()` на каждое здание). Тест: число запросов обзора с 1 и 5 постройками равно.
- Ask 5: «🏗 Строить» → `Build_b<id>` (роутер резолвит по первому сегменту, `BuildListAction` пока суффикс игнорирует — разбор в story 02).
- Паритет: `BaseScreenBotParityTest` — 15 сценариев (Base: на базе, нет баз, одна вне сигнала, одна под Вышкой, пикер 0/1/2 покрытых, `Base_b<id>`, недоступная; construction: на базе, под Вышкой, нет базы, вне сигнала, `_b<id>`, пустая). Снимок снят с кода до правки (фото — через подменённую обёртку `http`, вызовы — захватом `WebDelivery`); на старом коде тест красный ровно на `Build`→`Build_b1`, на новом зелёный.
- Налог: строка налога на экране суммирует `tax` по строкам, не × `amount` — не менял (non-goal), стопка в модели идёт отдельным `amount`.
- phpstan-baseline: удалено 19 устаревших записей, 2 счётчика уменьшены (код типизирован моделью ядра).

## Findings
