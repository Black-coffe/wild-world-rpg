---
story: w2-n4-tails-01
spec: w2-n4-tails
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Ядро: переезд блокирует стройку и апгрейд; подтверждение апгрейда с уровнем

## Goal
Ядро стройки и апгрейда само отказывает во время активного переезда базы, и веб наследует этот отказ.
Подтверждение апгрейда в боте и в вебе несёт уровень «с N». Ядро применяет апгрейд только с этого
уровня, а устаревшее или повторное подтверждение ничего не списывает. Старая кнопка без уровня заново
показывает экран «Подтвердите апгрейд?».

## Requirements
> 1. Во время активного переезда базы каталог, карточка, старт стройки и апгрейд отказывают в обоих клиентах тем же текстом, что бот. Проверка живёт в ядре.
> 2. Подтверждение апгрейда (кнопка бота и форма веба) несёт уровень «с N». Если постройка уже не на N, ядро отказывает и не списывает ни золота, ни ресурсов. Старая кнопка без уровня показывает свежий запрос.

## Files
- app/Services/Tasks/ActiveTasksService.php
- app/Services/Buildings/BuildOrderService.php
- app/Services/Buildings/BuildingUpgradeService.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_base.php
- tests/database/BuildOrderServiceTest.php
- tests/database/BuildingUpgradeServiceTest.php
- tests/unit/Camp/BuildBotParityTest.php
- tests/unit/Camp/UpgradeConfirmBaseSuffixTest.php
- tests/database/PlayViewControllerTest.php
- phpstan-baseline.neon

## Non-goals
- Не снимать `checkRelocationAndBlock` из бот-handler'ов и не трогать другие экраны (Поход, крафт, роботы).
- Не менять `WHERE level = n-1` в `BuildingUpgradeApplier`: он остаётся защитой от одновременных запросов.
- Не менять тексты экранов бота, кроме `callback_data` кнопки «✅ Подтвердить».

## Map slice
`memory/map/bases.md` — «Три ядра», «Ловушки» (переезд в вебе, уровень в подтверждении); `memory/map/website.md` — `view=base`, `:upgrade`.

## Acceptance criteria
- [ ] `ActiveTasksService::hasActiveRelocation()` без Telegram-вызовов; `checkRelocationAndBlock()` через него.
- [ ] При задаче `BaseRelocation` в работе `BuildOrderService` catalog/preview/start и `BuildingUpgradeService` preview/apply отказывают текстом бота, без записи в БД; тест на каждый путь.
- [ ] `apply()` с `fromLevel`, не равным текущему уровню, или с `null` возвращает `stale` и не меняет золото, ресурсы и уровень (тест: два последовательных подтверждения с одного уровня → второе `stale`, списание одно).
- [ ] Кнопка «✅ Подтвердить» = `confirm_upgrade_building_<id>_l<N>[_b<base>]`, длина ≤ 64 байт; кнопка без `_l` отдаёт экран подтверждения и не зовёт `apply` (тест паритета).
- [ ] Веб-форма апгрейда несёт `from`; `/play` без `from` или с чужим уровнем отказывает без списания.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
