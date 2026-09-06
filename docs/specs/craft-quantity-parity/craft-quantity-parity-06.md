---
story: craft-quantity-parity-06
spec: craft-quantity-parity
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 3
blocked_by: []
---

# Доступное количество на карточке T3 — из того же источника, что у гейта старта

## Goal
Карточка T3-утилит считает доступное количество через `CraftCardHelper::available()`
(`ResourcePoolService`: рюкзак + склад базы), то есть ровно тем источником, которым проверяет
`GenericCraftActionStart::checkResources()`. Ступень, которую игрок реально может оплатить, больше
не пропадает с экрана из-за того, что сырьё лежит на складе, а не в рюкзаке.

## Requirements
> **2026-09-06, там же.** Триггер: карточка считала доступное количество по рюкзаку

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchProfessional/UtilityRecipePreviewT3Action.php
- app/Services/Craft/CraftCardHelper.php

## Non-goals
- НЕ дублировать вызов `ResourcePoolService` в экране: доступность обязана идти через
  `CraftCardHelper::available()`, иначе это третья копия одной формулы.
- НЕ менять сам `ResourcePoolService` и не трогать killswitch `storage.pool_enabled`.
- НЕ трогать расчёт по золоту и по компонентам, кроме случая, когда компоненты считаются не тем
  источником, что гейт старта — тогда исправить и сказать об этом в отчёте.
- НЕ мигрировать другие карточки крафта.

## Map slice
`memory/map/craft.md`; `app/Services/Player/ResourcePoolService.php:59-88` (ADR-171, пул рюкзак+склад).

## Acceptance criteria
- [ ] Для персонажа на базе, у которого сырьё лежит на складе, карточка показывает те же ступени,
      которые пропустит `GenericCraftActionStart` — проверить на конкретном сценарии, а не по чтению.
- [ ] Строка «не хватает» не появляется, когда сырья хватает с учётом склада.
- [ ] Подпись кнопок совпадает с обычными карточками крафта (`🛠️ Крафт {N}шт` в
      `BasicMedKitCraft1Action:256`) — паритет, который обещает бриф, распространяется и на текст.
- [ ] Убран артефакт вставки: задвоенный комментарий `// Resources.` и лишняя пустая строка.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
