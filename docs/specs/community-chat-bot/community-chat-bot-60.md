---
story: community-chat-bot-60
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 14
blocked_by: []
---

# Сообщения игрока в чате — данные игрока, а не транзиентный шум

## Goal
Точечный сброс персонажа админом чистит его сообщения в чате сообщества так же, как чистит его
firehose действий.

## Requirements
> чтобы этот Telegram-бот, когда мы запускаем cloud code, проходился по всем веткам, собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- app/Config/WipeManifest.php
- tests/unit/Config/WipeManifestCoverageTest.php
- tests/unit/Services/Admin/WipeServiceCharacterResetTest.php
- tests/unit/Config/CommunityWipeClassificationTest.php

## В чём дефект
`WipeManifest.php:197` классифицирует `community_messages` как `TRANSIENT` с пометкой
«регенерируется чатом». Пометка неверна по существу: сообщения игрока ничем не регенерируются,
они живут до TTL и несут `telegram_user_id` на каждой строке. Структурный близнец —
`player_action_log` (`:144`), тот же append-only firehose с player-связью — классифицирован
`PLAYER_DATA` именно поэтому.

Последствие проверено в коде: `WipeService::resetCharacter()` (`app/Services/Admin/WipeService.php:383`)
перебирает **только** `PLAYER_DATA`, значит сброс одного персонажа не тронет его строки в
`community_messages`, тогда как его же `player_action_log` вычистит. На полном вайпе разницы нет —
`wipeAll()` обрабатывает обе стратегии одинаково (`:287`), поэтому дефект и не всплыл на самом
заметном сценарии.

Образец для правки лежит в том же файле: `poll_votes` (`:173`) — `'link' => 'telegram_user_id',
'by' => 'telegram'`. Ветка `by === 'telegram'` в `WipeService` (`:395`) уже существует, новый код
там не нужен.

## Non-goals
- Не трогать `community_answers` — `KEEP` верно: это авторский корпус владельца наравне с `game_tips`.
- Не трогать `WipeService` — нужная ветка уже есть.
- Не трогать `CommunityCleanup` и TTL — это независимый от вайпа механизм.
- Не переклассифицировать другие таблицы «заодно».

## Acceptance criteria
- [ ] `community_messages` классифицирована `PLAYER_DATA` с `link: 'telegram_user_id'`, `by: 'telegram'`, и пометка описывает фактическую природу таблицы.
- [ ] Сброс одного персонажа удаляет его строки в `community_messages` и не трогает чужие.
- [ ] `WipeManifestCoverageTest` зелёный.
- [ ] `CommunityWipeClassificationTest::testCommunityMessagesIsTransient` приведён в соответствие: утверждает `PLAYER_DATA` вместе со связкой (`link`/`by`), докблок объясняет, почему стратегия сменилась, со ссылкой на эту story. Утверждение по `community_answers` (`KEEP`) не трогать.
- [ ] Поведенческий тест: у двух персонажей есть строки в `community_messages`; сброс одного удаляет только его строки, строки второго остаются. Тест краснеет при возврате стратегии в `TRANSIENT`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/WipeManifestCoverageTest.php`

## Implementation notes
- `community_messages` перенесена из блока TRANSIENT в блок PLAYER_DATA (`app/Config/WipeManifest.php`), рядом с `poll_votes` — образец `link: 'telegram_user_id', 'by' => 'telegram'` скопирован как есть.
- Пометка `note` переписана: убрано «регенерируется чатом» (неверно по существу), явно указано, что строки живут до TTL и несут `telegram_user_id`.
- `WipeService` не менялся — ветка `by === 'telegram'` уже обрабатывает такие таблицы.
- `WipeManifestCoverageTest` зелёный: 8 тестов, 482 assertions.
- Добавлен `tests/unit/Services/Admin/WipeServiceCharacterResetTest.php`: два персонажа с разными
  `telegram_user_id`, у каждого свои строки в `community_messages`; сброс персонажа A через
  боевой (не подменённый) `WipeManifest` удаляет только его 3 строки, 2 строки персонажа B целы.
  Схема `community_messages` — из реальной миграции `Adr176CreateCommunityMessagesTable` (Forge,
  паттерн `CommunityIngestServiceTest`), не hand-rolled. `characters`/`map` — узкая ручная схема
  под конкретные поля, которые трогает `resetCharacter()` (паттерн `AchievementServiceTest`),
  эти две таблицы не были предметом story. Тест не запускал — DB-тест, конфликт с параллельными
  воркерами; верификация на стороне team-lead.
- `tests/unit/Config/CommunityWipeClassificationTest.php` приведён в соответствие: метод
  переименован в `testCommunityMessagesIsPlayerDataLinkedByTelegram`, утверждает не только
  стратегию `PLAYER_DATA`, но и связку (`link: 'telegram_user_id'`, `by: 'telegram'`) —
  раньше тест проверял бы только половину контракта. Докблок класса объясняет, почему
  классификация сменилась (ссылка на story community-chat-bot-60), старая пометка «поток
  регенерируется чатом» убрана как неверная по существу. `testCommunityAnswersIsKeep` не тронут.
  Не запускал — по инструкции team-lead, верификация на его стороне.

## Findings
