---
story: e-first-02
spec: e-first
status: done
returned: DONE
tier: 2
worker: worker-code
wave: 2
blocked_by: [e-first-01]
red: required
red_reason: FirstStepMenuService" not found
red_verified_at: 2026-11-01T14:02:31Z 0798a1c478303136c11da87307e47f6376db2ea6baba163fb0619a7e2a5368a0
---

# Полное меню один раз после первого шага

## Goal
После первого шага новичка (первый `move` с `origin = 'cold'` — inline-кнопка Роби или нижняя кнопка из `e-first-01`)
приходит отдельное сообщение с полной нижней клавиатурой и строкой «что где». Ровно один раз на персонажа: маркер
`FirstStepMenuAttached` в `action_log`, ставится только при успешной доставке. Повторные шаги и ветераны его не получают.
Флаг тот же — `onboarding.cold_open_v2.first_step_keyboard`; при OFF ничего не шлётся.

## Requirements
> 3. После первого шага новичка (первый `move` с экрана Роби или с нижней кнопки) приходит одно отдельное сообщение с полной нижней клавиатурой и строкой «что где»; ровно один раз на персонажа (маркер в `action_log`), повторные шаги его не шлют.
> 4. Навигация не теряется: `/start` у существующего персонажа и `/menu` по-прежнему возвращают полное меню; набранный руками текст (`я`, `база`, `мир`…) работает как сейчас; fallback «не понял» подсказывает возврат.
> 9. Tier-3 cold-smoke на preprod-testbot: свежий `/start` → внизу одна кнопка → тап → компас + полное меню одним разом; повторный шаг меню не шлёт; `/start` повторно → полное меню; флаг OFF → как сейчас.

## Files
- app/Services/Onboarding/FirstStepMenuService.php
- app/Controllers/Telegram/Commands/Actions/MoveCharacterAction.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php

## Non-goals
- Не трогать `NavMenuRefreshService` и его маркеры — это пере-аттач ADR-150 для ветеранов; новый сервис только повторяет его форму.
- Не слать меню после «✏️ Назвать героя».
- Не менять экран компаса.

## Map slice
`memory/map/telegram.md` — пере-аттач меню (`NavMenuRefreshService`); `memory/map/onboarding.md` — первый экран.

## Acceptance criteria
- [ ] `FirstStepMenuService::maybeAttach(charId, chatId)`: флаг OFF, origin ≠ `cold`, маркер уже есть, или `charId/chatId` пустые → ничего не шлёт. Иначе — одно `sendMessage` с `BotMenuService::mainReplyKeyboard()` и текстом, где кнопки перечислены через `BotMenuService::replyButtonsLine()` (не хардкод); маркер ставится только при `isOk()`. Исключения не выходят наружу.
- [ ] Вызов — после ответа компаса в `MoveCharacterAction` и в текстовом роуте нижней кнопки (`GenericmessageCommand`).
- [ ] Текст сообщения проходит `scripts/defects-bot-lexicon-check.php` (без «нижнего меню», счёта кнопок); legacy-Markdown безопасен.
- [ ] Тест падает, если второй шаг шлёт меню повторно (red), и если маркер ставится при неуспешной доставке.
- [ ] Tech-writing: ноты `FirstStepMenuService` (новая), `ColdOpenWelcomeService`, `StartCommand`, `GenericmessageCommand`, `MoveCharacterAction` — обновлены (`last_reviewed: 2026-11-01`).
- [ ] Tier-3 cold-smoke на preprod по ask 9 — результат в `docs/specs/e-first/smoke-preprod.md`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `FirstStepMenuService::maybeAttach` повторяет форму `NavMenuRefreshService`: проверки → одно `sendMessage` (полная клавиатура, Markdown) → маркер `FirstStepMenuAttached` только при `isOk()`, `Throwable` ловится внутри. Швы `flagEnabled / currentOrigin / alreadyAttached / mark / send`.
- Текст: кнопки из `replyButtonsLine()`, «куда идти» из `menuLabel('world')` (у каркаса без `world_hub` это «Карта», а не «компас»), запасной путь `/menu`. Без «нижнего меню», счёта кнопок и «☰».
- Вызовы: `MoveCharacterAction` после `MoveSurfaceService::show` (метка `cold` приходит из `UpdatePipeline`). В текстовом роуте `GenericmessageCommand` метку ставит e-first-01, персонаж резолвится заново после `handleWorld`. На ветке «идёт переезд» меню не шлётся: шага не было.
- Ask 4: `/start`, `/menu`, ручные алиасы и fallback не тронуты.
- Tech-writing: новая нота `services/FirstStepMenuService.md`. Ноты `StartCommand` не было, создана `handlers/StartCommand.md`. Обновлены `ColdOpenWelcomeService`, `ColdOpenGreetingService`, `BotMenuService`, `GenericmessageCommand`, `MoveCharacterAction`, у всех `last_reviewed: 2026-11-01`.
- **Tier-3 cold-smoke (ask 9) ещё не сделан:** ему нужен деплой на preprod, а он идёт после GREEN совета и merge в `develop`. `docs/specs/e-first/smoke-preprod.md` будет записан перед тегом.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php
- exit: 2
- tests: 0798a1c478303136c11da87307e47f6376db2ea6baba163fb0619a7e2a5368a0 (tests/unit/Services/Onboarding/FirstStepMenuServiceTest.php)
- impl: f8fed9d8b6d0889e21f2c6e5544e99cab00ff0fb2947a64183b0ca5d13d0e18a
- stamped: 2026-11-01T14:02:31Z

```
C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\FirstStepMenuServiceTest.php:127

5) Tests\Unit\Services\Onboarding\FirstStepMenuServiceTest::testMessageCarriesFullKeyboardAndWhereIsWhatLine
Error: Class "App\Services\Onboarding\FirstStepMenuService" not found

C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\FirstStepMenuServiceTest.php:24
C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\FirstStepMenuServiceTest.php:137

[37;41mERRORS![0m
[37;41mTests: 5[0m[37;41m, Assertions: 0[0m[37;41m, Errors: 5[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
