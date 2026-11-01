---
story: d-dead-res-heal-line-01
spec: d-dead-res-heal-line
status: done
returned: DONE
tier: 1
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: Call to undefined method App\TaskHandlers\Craft\GenericCraftCompletionHandler::healLine()
red_verified_at: 2026-11-01T21:57:06Z 2467eba87c71b30afd82278545d3f2ee98d97697b768628d0231afb7ad58d5e9
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
- `GenericCraftCompletionHandler::healLine()` — чистая статическая функция, геттер настройки на входе (тест без БД
  и Telegram). Snake-правило скопировано с `UsePharmacyAction::toSnakeCase` (третья копия рядом с
  `MedicalRecipePreviewT3Action`). Общий хелпер — отдельная гигиена: те файлы вне story.
- `notifyUser` берёт `GameSettingsService::get()` (кэш 60 с) и ставит строку сразу под «Ты создал…». Для предметов
  без ключа текст итога байт в байт прежний (пустая вставка, тот же `

`).
- `$craftedItem` для обычных предметов — полная строка `crafted_items` (`:182`), `type` есть. У оружия и брони свои
  синтетические строки, `drug` там не бывает.
- Вердикты: `/guide` — нет, совет — нет: правка текста существующего экрана, новой механики нет.
- Tech-writing: `tasks/craft/GenericCraftCompletionHandler.md` (vault `8ce4b87`).

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/TaskHandlers/Craft/CraftCompletionHealLineTest.php
- exit: 2
- tests: 2467eba87c71b30afd82278545d3f2ee98d97697b768628d0231afb7ad58d5e9 (tests/unit/TaskHandlers/Craft/CraftCompletionHealLineTest.php)
- impl: 6fa7b91b2a6d2af532f69b4ec70e132393390f5753ef0efdf3775cd82e9aef2b
- stamped: 2026-11-01T21:57:06Z

```
C:\laragon\www\mmorpg\tests\unit\TaskHandlers\Craft\CraftCompletionHealLineTest.php:70

6) Tests\Unit\TaskHandlers\Craft\CraftCompletionHealLineTest::testLineIsMarkdownSafe
Error: Call to undefined method App\TaskHandlers\Craft\GenericCraftCompletionHandler::healLine()

C:\laragon\www\mmorpg\tests\unit\TaskHandlers\Craft\CraftCompletionHealLineTest.php:27
C:\laragon\www\mmorpg\tests\unit\TaskHandlers\Craft\CraftCompletionHealLineTest.php:78

[37;41mERRORS![0m
[37;41mTests: 6[0m[37;41m, Assertions: 0[0m[37;41m, Errors: 6[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
