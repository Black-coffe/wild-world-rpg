---
story: e-first-rollback-01
spec: e-first-rollback
status: todo
returned:
tier: 1
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: при OFF нижняя кнопка не привозит меню
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

## Findings
