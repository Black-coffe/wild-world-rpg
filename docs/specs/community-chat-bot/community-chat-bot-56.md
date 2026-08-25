---
story: community-chat-bot-56
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 14
blocked_by: []
---

# Приём слышит прямое обращение, правку и коллективный вопрос

## Goal
Сообщение, адресованное боту, доходит до решения матчера независимо от того, похоже ли оно на
вопрос по эвристике; отредактированное сообщение судится по актуальному тексту; список
коллективных обращений перестаёт быть выборочным.

## Requirements
> чтобы когда игроки что-то спрашивают, уточняют по игре, он отвечал, коммуницировал, комментировал, чтобы была живая группа

> чтобы он от своего имени, от бота, давал ответы на вопросы

## Files
- app/Services/Community/CommunityIngestService.php
- tests/unit/Services/CommunityIngestServiceTest.php

## Три дефекта, найденные ревью 2026-08-25

**1. Анти-флуд глушит прямое обращение.** `CommunityIngestService.php:143-145`: шестой вопрос
автора за час обнуляет `is_question`. Строка с `addressed_to_bot=1` при этом тонет молча — ни
ответа, ни эскалации, ни реакции. Квота обязана глушить *подслушанное*, а не прямое обращение к
боту: на «Роби, помоги» бот не имеет права молчать (докблок `CommunityAnswerMatcher.php:23-24`
обещает это структурно).

**2. Правка сообщения не доходит до приёма.** `CommunityIngestService.php:88` читает только
`$update['message']`. Story 24 делегировала правку в story 25, story 25 записала её в Non-goals —
сужение не выполнено ни одной и нигде не зафиксировано. Сценарий: игрок пишет «привет», через
секунду редактирует в «Роби, где вода?» — строки в `community_messages` нет, вопроса для бота не
существует. Обратный случай: модерация уже судит новый текст (`edited_message` до неё доходит), а
матчер и гвард работают по старому.

**3. Список коллективных обращений выборочен.** `CommunityIngestService.php:59` знает
`народ/ребят/ребята/пацаны/всем`, но «Люди, где вода?», «Мужики, как крафтить?», «Друзья, …»
глотает как чужой разговор. Тот же дефект, что чинила story 20, на других словах.

## Non-goals
- Не трогать гвард, его рубежи и пороги — рубеж 1 переделывается отдельным ADR.
- Не трогать матчер, отправитель, админку и тик авто-ответа (`CommunityAutoReplyHandler` — story 57).
- Не менять схему `community_messages` и не заводить миграций: правка обновляет существующую
  строку по `UNIQUE(chat_id, message_id)`, новая колонка для этого не нужна.
- Не подменять смысл `is_question`: колонку читают очередь `/admin/community` и счётчики, а не только тик. Выборку тика чинит story 57.
- Не расширять список обращений «на всякий случай» безгранично — добавить разговорные формы,
  которые реально встречаются, и покрыть их тестом, а не сочинять словарь.

## Acceptance criteria
- [ ] `«Роби, подскажи»` и `«@robibot помоги»` (без `?` и без вопросительного слова) сохраняются с `addressed_to_bot=1`, при этом `is_question` остаётся честным (0) — смысл колонки не переопределяется. Доведение такой строки до тика доказывает story 57 своим тестом.
- [ ] Превышение авторской квоты не обнуляет признак вопроса у сообщения с `addressed_to_bot=1`; для подслушанного поведение прежнее.
- [ ] `edited_message` обновляет текст существующей строки, а признаки вопроса/обращения пересчитываются по новому тексту.
- [ ] Правка сообщения, которого в таблице нет (пришла после TTL-чистки), не создаёт дубля и не падает.
- [ ] «Люди, …», «Мужики, …», «Друзья, …» распознаются как коллективное обращение наравне с «Народ, …».
- [ ] Каждый пункт выше подан тестом, который краснеет на возврате прежнего поведения.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityIngestServiceTest.php`

## Implementation notes

- `CommunityIngestService.php:59` — `COLLECTIVE_ADDRESS_WORDS` дополнен словами
  `люди, мужики, друзья` (дефект №3).
- `CommunityIngestService.php:84-104` — `handle()` разбит на диспетчер: `$update['message']` →
  `ingestNewMessage()` (бывшее тело метода), `$update['edited_message']` → новый
  `ingestEditedMessage()` (дефект №2).
- `CommunityIngestService.php:165-169` (и симметрично в `ingestEditedMessage`) — правка ревью
  2026-08-26: `isQuestion` остаётся ЧИСТОЙ эвристикой (`looksLikeQuestion()`), НЕ смешивается с
  `isAddressedToBot` — эту колонку читает не только тик, но и очередь `/admin/community` со
  счётчиками, и «Роби, спасибо, всё понял» не должен становиться вопросом в очереди. Квота
  (`authorOverQuota`) по-прежнему обнуляет `is_question` только когда `! $isAddressedToBot`
  (дефект №1, эта часть осталась) — доведение прямого обращения без эвристики до тика отдано
  story 57 (`is_question=1 OR addressed_to_bot=1`).
- `ingestEditedMessage()` ищет строку по `UNIQUE(chat_id, message_id)`, при отсутствии — no-op
  (без INSERT, без исключения); при наличии — `UPDATE` полей `text/is_question/addressed_to_bot`,
  `telegram_user_id`/`sent_at` для квоты берёт из уже существующей строки (правка не меняет автора
  и время получения).
- phpstan L9 (замечание ревью 2026-08-26): `Model::first()` типизирован базовым классом как
  `array|object|null` независимо от `returnType='array'` в конкретной модели — добавлен явный
  `is_array($existing)` с ранним выходом вместо `(array)`-приведения (обходит касты Entity, урок
  этого репо), и `is_numeric()`-проверки перед `(int)`-кастом полей `telegram_user_id`/`id` (не
  голый `(int) $mixed`, отдельная ошибка L9). `vendor/bin/phpstan analyse --memory-limit=512M
  --no-progress app/Services/Community/CommunityIngestService.php` — чисто.
- Тесты добавлены в `CommunityIngestServiceTest.php`: `testDirectAddressWithoutQuestionMarkOrWordIsStoredButNotAQuestion`
  (переименован после правки ревью — доказывает `addressed_to_bot=1` + честный `is_question=0`),
  `testAuthorQuotaDoesNotZeroAddressedToBotQuestion`, `testMoreCollectiveAddressWordsAreQuestions`,
  `testEditedMessageUpdatesTextAndRecomputesFlags`, `testEditedMessageCanClearQuestionFlag`,
  `testEditOfMissingRowIsNoopAndDoesNotThrow` — каждый краснеет на откате соответствующего фикса.
- В файлы вне `## Files` не выходил. `php -l` на обоих файлах — чисто. `vendor/bin/phpunit` не
  запускал (запрет team-lead) — верификацию прогонит team-lead последовательно.

## Findings
