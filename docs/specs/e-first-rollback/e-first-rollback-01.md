---
story: e-first-rollback-01
spec: e-first-rollback
status: done
returned: DONE
tier: 1
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: при OFF нижняя кнопка не привозит меню
red_verified_at: 2026-11-01T15:22:50Z a3eb02b2e72eebc8d100e40485d24380662a0941ffb8d75cd0f0949727d2680a
---

# Нижняя кнопка первого шага работает при любом значении флага

## Goal
`GenericmessageCommand` роутит «🧭 Сделать первый шаг» в компас с `origin = cold` без проверки флага и зовёт
`FirstStepMenuService::maybeAttach(..., fromBottomButton: true)`, который в этом режиме пропускает гейт флага (метка `cold`,
маркер one-shot и доставка — как раньше). Вызов из `MoveCharacterAction` (inline `move~cold`) остаётся под флагом.

## Requirements
> Нижняя кнопка «🧭 Сделать первый шаг» работает всегда, а не только при флаге onboarding.cold_open_v2.first_step_keyboard: текстовый роут в GenericmessageCommand ведёт в компас с origin=cold и один раз привозит полное меню (FirstStepMenuService) независимо от флага; флаг по-прежнему решает, что показать НОВОМУ игроку на /start, и гейтит меню после inline-шага Роби (move~cold) — там OFF остаётся байт-в-байт.

## Files
- app/Services/Onboarding/FirstStepMenuService.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php

## Acceptance criteria
- [ ] Флаг OFF + нижняя кнопка: меню уходит один раз, маркер ставится при `isOk()`; повторный тап меню не шлёт.
- [ ] Флаг OFF + inline-шаг (без `fromBottomButton`): ничего не шлётся (поведение до E-FIRST).
- [ ] Текстовый роут в `GenericmessageCommand` больше не читает флаг.
- [ ] Tech-writing: ноты `FirstStepMenuService` и `GenericmessageCommand` обновлены.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `FirstStepMenuService::maybeAttach` получил `bool $fromBottomButton = false`. При `true` гейт флага пропускается, а метка `cold`, маркер one-shot и проверка доставки остаются. `MoveCharacterAction` не тронут: inline-шаг Роби по-прежнему под флагом, при OFF поток как до E-FIRST.
- `GenericmessageCommand`: условие на флаг из текстового роута убрано, вызов идёт с `true`.
- Ноты `FirstStepMenuService` и `GenericmessageCommand` обновлены.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php
- exit: 1
- tests: a3eb02b2e72eebc8d100e40485d24380662a0941ffb8d75cd0f0949727d2680a (tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php)
- impl: 5a8c39ac315811916f464e4dcc9c43c12a1b11fc1051f0f00c6b146b7682fa19
- stamped: 2026-11-01T15:22:50Z

```
There was 1 failure:

1) Tests\Unit\Services\Onboarding\FirstStepMenuServiceTest::testBottomButtonAttachesMenuOnceEvenWhenFlagIsOff
при OFF нижняя кнопка не привозит меню
Failed asserting that actual size 0 matches expected size 1.

C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\FirstStepMenuServiceTest.php:133

[37;41mFAILURES![0m
[37;41mTests: 7[0m[37;41m, Assertions: 31[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
