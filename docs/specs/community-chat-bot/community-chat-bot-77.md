---
story: community-chat-bot-77
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 22
blocked_by: []
---

# Превью сброса считает то же, что удалит сам сброс

## Goal
Владелец перед необратимым действием видит правдивое число строк, а не ноль.

## Requirements
> чтобы этот Telegram-бот, когда мы запускаем cloud code, проходился по всем веткам, собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- app/Services/Admin/WipeService.php
- tests/unit/Services/Admin/WipeServiceCharacterResetTest.php

## В чём дефект
Story 74 завела третий способ связи `telegram_raw` и починила `resetCharacter()`, но
`previewCharacter()` (`WipeService.php:226`) остался на прежней двоичной развилке:
`$matchId = $by === 'telegram' ? $tgId : $characterId;`

Для `community_messages` превью подставляет **id персонажа** в колонку, где лежит сырой
Telegram-id, и показывает владельцу **0 строк** — тогда как сброс удалит все его сообщения.
Экран `/admin/character-reset` — единственный вызывающий, тестов у метода нет.

Это тот же класс, что чинила story 74, в соседнем методе того же файла: разошлись две ветки,
которые обязаны решать одинаково.

🔴 Инвариант: **превью и сброс обязаны использовать один способ разрешения id.** Не «одинаковый
сегодня», а один — иначе третья ветка разойдётся с двумя первыми ровно так же.

## Non-goals
- Не менять `resetCharacter()` по существу — он верен.
- Не трогать полный вайп: там связка не используется.
- Не расширять превью новыми данными — только правдивость счёта.

## Acceptance criteria
- [ ] Превью для `community_messages` показывает то же число строк, которое удалит сброс, при различающихся внутреннем id и сыром Telegram-id.
- [ ] Разрешение id для всех трёх способов связи живёт в одном месте, а не повторяется в двух методах.
- [ ] Тест краснеет, если превью вернуть на двоичную развилку.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Admin/WipeServiceCharacterResetTest.php`

## Implementation notes
- `app/Services/Admin/WipeService.php`: новый приватный `resolveMatchId(string $by, int
  $characterId, ?int $telegramUserId, ?int $rawTelegramId): ?int` — единственное место с
  `match($by){...}`. И `previewCharacter()`, и `resetCharacter()` теперь только вызывают его,
  сами развилку не хранят. `previewCharacter()` дополнительно вызывает `rawTelegramIdOf()`
  (уже существовал с story 74, использовался только в `resetCharacter()`).
- `tests/unit/Services/Admin/WipeServiceCharacterResetTest.php`:
  `testPreviewCharacterCountsSameRowsAsResetAcrossKeySpaces` — на тех же двух персонажах
  (internal id 7/8, raw Telegram-id 584213905/112233445), что и story 74. Проверяет: превью
  видит 3 сообщения персонажа A (не 0), и число из превью совпадает с числом, которое реально
  удаляет `resetCharacter()` на тех же данных — не два независимых утверждения, а одна проверка
  на совпадение, которая ловит будущее расхождение веток напрямую.
- Проверил красноту исполнением: временно вернул `previewCharacter()` на бинарную развилку
  `$by === 'telegram' ? $tgId : $characterId` — новый тест упал
  (`Failed asserting that an array has the key 'community_messages'`, то есть превью показало 0
  строк вместо 3), восстановил `resolveMatchId()` — снова зелёный (2 теста, 9 assertions).
- `resetCharacter()` по существу не менялся (non-goal) — только точка вызова развилки заменена
  на общий метод, поведение то же самое (проверено: тест на resetCharacter из story 74 остался
  зелёным без изменений).
- По отдельному запросу ревью (тот же класс, что `feedback_test_schema_must_come_from_migration`):
  ручная схема `telegram_users` в тесте заменена на реальную миграцию
  `App\Database\Migrations\CreateTelegramUsersTable` (Forge, тот же паттерн, что уже был у
  `community_messages` с story 74). Обе колонки, которые читает `rawTelegramIdOf()` (`id`,
  `telegram_id`), теперь берутся из настоящей схемы, а не написаны на глаз.
  `requireMigrationClass()` переименован в `requireMigrationClasses()` (множественное число, обе
  миграции). Прогнал файл повторно — 2 теста, 9 assertions, зелёный.

## Findings
