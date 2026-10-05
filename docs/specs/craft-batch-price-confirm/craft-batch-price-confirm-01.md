---
story: craft-batch-price-confirm-01
spec: craft-batch-price-confirm
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Цена за штуку на карточке T3 и строка «Списано» после старта

## Goal
Карточка T3-утилиты подписывает золото «за 1 шт.» и показывает время одной штуки; сообщения «Процесс крафта
запущен» и «В очередь поставлено» говорят, что именно списано за всю партию.

## Requirements
> «за 1 шт.» и время одной штуки на карточке; в стартовом сообщении строка «Списано: 300 000 💰, ресурсы …»
> 1. Карточка T3-утилит (`UtilityRecipePreviewT3Action`) подписывает золото «за 1 шт.» и показывает время одной штуки (из `CraftDurationService`, с бонусами, если они есть).
> 2. Сообщение «Процесс крафта запущен» содержит строку «Списано: N 💰» с золотом за всю партию и перечнем списанных ресурсов и компонентов с количествами за всю партию.
> 6. Все тексты экранов markdown-safe (парные `*`/`_`, имена рецептов не ломают разметку), читаются без картинки, на экране подтверждения нет одиночных кнопок в ряду.

## Files
- app/Services/Craft/CraftOrderService.php
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchProfessional/UtilityRecipePreviewT3Action.php
- tests/database/CraftOrderServiceTest.php
- tests/unit/Craft/CraftSpentLineTest.php

## Non-goals
- Не трогать `CraftCardHelper::quantityRows` (подписи кнопок) и форму `craft_again_callback`.
- Не менять формулу времени и цены — только показывать.
- Не переносить `gold_required` рецептов в GameSettings (отдельная задача).

## Map slice
`memory/map/craft.md` → Entry points, Gotchas (ADR-171 пул, ADR-182).

## Acceptance criteria
- [ ] `start()` возвращает `consumed` с итогом за партию; database-тест: 3 шт рецепта → gold = 3 × gold_required, ресурсы = 3 × норма.
- [ ] Строка «Списано» собирается чистой функцией (тестируемой без Telegram): золото с разделителем тысяч, ресурсы и компоненты с русскими именами; пустые разделы не печатаются; имя со `_`/`*` не ломает Markdown.
- [ ] Карточка T3: «*Золото (за 1 шт.):*» и строка времени одной штуки; при бонусах — база и итог, как в строке правды ADR-158.
- [ ] Caption карточки и стартового сообщения ≤ 1024 символов на самом длинном T3-рецепте.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `CraftOrderService::start()` отдаёт `consumed{gold, resources, crafted_items}` (тип `Consumed`): ровно то, что списала транзакция; рюкзак+склад сложены, компоненты — `name_rus`. У отказа — нули.
- `GenericCraftActionStart::spentLine()` — public static, чистая; строка «💸 *Списано:* …» вставлена и в старт, и в «В очередь поставлено». Имена чистятся от `* _ \` [ ]` (паттерн `TasksSurfaceService::markdownSafe`).
- Эталон паритета бота `BOT_BEFORE` в `CraftOrderServiceTest` получил строку «Списано» в трёх случаях (start / start_items / queue) — это намеренное изменение текста, остальное тождественно.
- Карточка T3: «Золото (за 1 шт.)» + «Время (за 1 шт.)» из `CraftDurationService::forOne` (строка правды при бонусах, с killswitch `craft.duration_breakdown.enabled`).
- Длина caption: проверена строка «Списано» на самом тяжёлом рецепте ×100 (< 400 символов); полный caption карточки T3 чистой функцией не рендерится — проверка ≤1024 остаётся за Tier-3 смоуком.
- Tech-writing: `services/CraftOrderService.md`, `handlers/craft/GenericCraftActionStart.md`, `handlers/craft/UtilityRecipePreviewT3Action.md`.

## Findings
