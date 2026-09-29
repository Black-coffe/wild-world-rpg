---
story: w2-n4-tails2-01
spec: w2-n4-tails2
status: todo
returned:
tier: 1
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Русские имена ресурсов в ядре и вебе, «устарело» первым, часики на отказах бота

## Goal
Превью апгрейда несёт `resource_names` (name_en → русское имя), веб-экран апгрейда подписывает ресурсы
по-русски. `apply()` отвечает `stale`, если постройка найдена и её уровень ≠ `fromLevel`, раньше
прочих отказов. Бот на каждом отказе запроса и подтверждения вызывает `answerCallbackQuery` один раз.

## Requirements
> веб /play апгрейд подписывает ресурсы английскими ключами (Water, Wood, Pebble, Sand) — ядро должно отдать русские имена, веб их показать
> бот UpgradeBuildingAction: отказы stale/refused/missing не снимают «часики» — answerCallbackQuery на каждом отказе
> BuildingUpgradeService::apply: «устарело» раньше прочих условий — если постройка найдена и её уровень ≠ fromLevel, ответ stale, даже если условия следующего уровня не выполнены.

## Files
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- app/Services/Buildings/BuildingUpgradeService.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- app/Views/site/_play/native_base.php
- tests/database/BuildingUpgradeServiceTest.php
- tests/unit/Camp/UpgradeConfirmBaseSuffixTest.php
- tests/unit/Views/PlayViewsTest.php

## Non-goals
- Не переписывать `BuildingUpgradeMessageFormatter::askPrompt` на `resource_names`.
- Не трогать тексты отказов валидатора и порядок его проверок.

## Map slice
none

## Acceptance criteria
- [ ] Превью с ресурсами `Water`/`Wood` отдаёт `resource_names` = `['Water' => 'Вода', 'Wood' => 'Древесина']` (по справочнику), веб-вьюха печатает русское имя, английский ключ в HTML не попадает.
- [ ] `apply()` с `fromLevel` ≠ уровня постройки при нехватке золота / уровня персонажа отвечает `stale`, ничего не списано.
- [ ] `confirmUpgrade()` с устаревшим `_l` и `askForUpgrade()` с отказом шлют ровно один `answerCallbackQuery`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes

## Findings
