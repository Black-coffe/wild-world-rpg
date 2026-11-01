---
story: d-dead-res-heal-line-01
spec: d-dead-res-heal-line
status: todo
returned:
tier: 1
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason:
---

# Числа лечения в итоге крафта лекарства

## Goal
`GenericCraftCompletionHandler::notifyUser` для `drug` с `medical.<snake>.heal_health` вставляет строку
«🩺 Лечит: ❤️ +N здоровья, ⚡ +M выносливости» (нулевую часть опускает). Строку строит
`GenericCraftCompletionHandler::healLine(array $craftedItem, \Closure $setting): ?string`. Тест держит ветки:
есть ключ → числа; нет ключа → null; не `drug` → null; выносливость 0 → без ⚡.

## Files
- app/TaskHandlers/Craft/GenericCraftCompletionHandler.php
- tests/unit/TaskHandlers/Craft/CraftCompletionHealLineTest.php

## Non-goals
- Не менять кнопки итога, оружие, броню, семена и тексты других рецептов.

## Acceptance criteria
- [ ] Итог крафта Сыворотки читается без фото: имя, количество, «🩺 Лечит: ❤️ +25 здоровья».
- [ ] Предмет без ключа — текст как раньше.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/Craft/CraftCompletionHealLineTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
