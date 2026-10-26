---
story: w-reg-polish-01
spec: w-reg-polish
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
---

# Ход новичка: подсказка о базе по порядку, без шума карго-дрона

## Goal
`OnboardingHintService::maybeSendFirstBaseTip` получает ещё один гейт: если у персонажа есть хоть один шаг цепочки
обучения, подсказка ждёт, пока среди его шагов появится `OnbStepClaimBase` (активный или закрытый); нет ни одного
шага цепочки — гейт прежний. В `MoveService` событие `cargo` для игрока без карго-дрона больше не несёт текст
«Карго-дрона пока нет.» — кнопка «🔒 Карго-дрон» (`cargoDroneLocked`) остаётся в клавиатуре хода.

## Requirements
> 1. Подсказка «первая база» приходит не раньше шага цепочки про базу, без обучения — как сейчас.
> 2. В сообщении хода нет строки «Карго-дрона пока нет.», кнопка «🔒 Карго-дрон» остаётся.
> Lock-вход нужен, но не на каждом ходе новичка L1.
> 8. (ворота проекта) UX-DISCOVERABILITY: вход «🔒 Карго-дрон» по-прежнему виден на экране хода и объясняет путь; подсказка «первая база» по-прежнему доходит до игрока без обучения.

## Files
- app/Services/Onboarding/OnboardingHintService.php
- app/Services/World/MoveService.php
- tests/unit/Services/Onboarding/OnboardingHintServiceTest.php
- tests/database/MoveServiceTest.php

## Non-goals
- Не добавлять триггер подсказки на закрытие шага «крафт» — она по-прежнему шлётся с хода.
- Не трогать текст подсказки, `OnboardingHintCatalog`, `CargoDroneLockedAction` и lock-кнопку.
- Не прятать кнопку «🔒 Карго-дрон» до базы (отвергнуто владельцем: нужен ADR против правила #4).
- Не трогать `NewbieAtmosphereService` (F13 — задумано).

## Map slice
`memory/map/onboarding.md` (подсказки Слоя 1, цепочка), `memory/map/world.md` (ход, хвост событий `TAIL_TYPES`).

## Acceptance criteria
- [ ] Персонаж с активным шагом `OnbStepMove` (или Gather/Craft) и без базы: `maybeSendFirstBaseTip` возвращает false и ничего не шлёт.
- [ ] Персонаж с шагом `OnbStepClaimBase` (активным или закрытым) и без базы: подсказка шлётся, как раньше (one-shot).
- [ ] Персонаж без шагов цепочки: поведение прежнее — подсказка шлётся на ходу.
- [ ] Ход без карго-дрона при `cargoIsEnabled()`: в тексте хода нет «Карго-дрона пока нет.», кнопка «🔒 Карго-дрон» с колбэком `cargoDroneLocked` есть.
- [ ] Тест в `OnboardingHintServiceTest` падает, если гейт по цепочке убрать (три случая выше).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/OnboardingHintServiceTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/database/MoveServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
