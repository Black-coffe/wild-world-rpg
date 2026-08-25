---
story: community-chat-bot-74
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 20
blocked_by: []
---

# Мост между двумя системами Telegram-id при сбросе персонажа

## Goal
Сброс персонажа удаляет именно его сообщения в чате, а тест это доказывает на **различающихся**
идентификаторах.

## Requirements
> чтобы этот Telegram-бот, когда мы запускаем cloud code, проходился по всем веткам, собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- app/Config/WipeManifest.php
- app/Services/Admin/WipeService.php
- tests/unit/Services/Admin/WipeServiceCharacterResetTest.php
- tests/unit/Config/WipeManifestCoverageTest.php
- tests/unit/Config/CommunityWipeClassificationTest.php

## В чём дефект
Нашёл ревьюер, подтверждено чтением кода.

`community_messages.telegram_user_id` — **сырой `from.id` Telegram** (`CommunityIngestService.php:185`).
`characters.telegram_user_id` — **внутренний `telegram_users.id`** (джойн `CharacterModel.php:189`;
конвенцию прямо фиксирует докблок `PlayerActionLogger`). `WipeService::telegramUserIdOf()`
(`:441-448`) читает второй и подставляет в связку, объявленную по первому.

Сравниваются несравнимые пространства ключей → `resetCharacter()` удаляет **ноль** строк и
рапортует успехом. Обещание §9 плана «стереть данные игрока» на этом пути не выполняется.
Полный вайп не затронут — там связка не используется.

🔴 **Тест зелен на вымысле.** `WipeServiceCharacterResetTest` сеет `characters.telegram_user_id=1001`
и `community_messages.telegram_user_id=1001`, то есть кодирует ошибочное предположение и потому
не может покраснеть на нём. Это тот самый класс, что записан уроком
`feedback_two_key_systems_need_an_explicit_bridge`: тихий fallback между двумя системами ключей
даёт мёртвую фичу с зелёными тестами.

## Non-goals
- Не менять семантику колонок: `community_messages` обязана хранить сырой `from.id` — по нему матчится входящий апдейт.
- Не трогать полный вайп: там дефекта нет.
- Не чинить мост «на месте» в `WipeManifest` магической строкой — связке нужен явный способ разрешения, читаемый в коде.

## Acceptance criteria
- [ ] Сброс персонажа удаляет строки `community_messages`, принадлежащие именно ему, при **различающихся** внутреннем id и сыром Telegram-id.
- [ ] Тест сеет разные значения (например, `telegram_users.id=7`, `from.id=584213905`) и краснеет, если мост убрать.
- [ ] Чужие строки остаются нетронутыми.
- [ ] Гейт покрытия манифеста знает про новый способ связи: `WipeManifestCoverageTest` зелёный и по-прежнему падает на записи `PLAYER_DATA` без связи.
- [ ] Способ разрешения id назван в коде явно, а не подразумевается совпадением значений.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Admin/WipeServiceCharacterResetTest.php`

## Implementation notes
- `app/Config/WipeManifest.php`: добавлено новое значение `by` — `'telegram_raw'` (легенда в
  докблоке `$tables` расписывает разницу с `'telegram'`). `community_messages` переведена на
  `'by' => 'telegram_raw'`, `note` объясняет, что `telegram_user_id` тут — сырой `from.id`, а не
  internal id, и куда смотреть за мостом.
- `app/Services/Admin/WipeService.php::resetCharacter()`: добавлен `rawTelegramIdOf()` — join
  `characters.telegram_user_id → telegram_users.id → telegram_users.telegram_id`. Матчинг заменён
  с тернарника на `match($by)`: `'telegram'` → internal id (как раньше, для `poll_votes` и т.п.),
  `'telegram_raw'` → результат `rawTelegramIdOf()`, иначе → `characterId`. Полный вайп не тронут —
  `wipeAll()`/`preview()` не используют `by`/`link` вовсе (DELETE всей таблицы).
- `tests/unit/Services/Admin/WipeServiceCharacterResetTest.php`: пересеян на **разные** id —
  внутренний `telegram_users.id` (7/8) и сырой `from.id` (584213905/112233445), связанные только
  через новую таблицу `telegram_users` в схеме теста. Метод переименован
  (`...AcrossKeySpaces`), докблок объясняет прежний вымышленный тест. Прогнал сам (разрешено) +
  вручную откатил обе поломки по очереди (`by`→`'telegram'`, `strategy`→`TRANSIENT`) — оба раза
  тест красный (`assertSame(0, remainingA)` падает на 3), затем восстановил рабочее состояние —
  снова зелёный (1 тест, 5 assertions).

## Findings
`tests/unit/Config/WipeManifestCoverageTest.php::testPlayerDataEntriesHaveLink` (строка 87)
приведён в соответствие: список допустимых `by` расширен `['character', 'telegram',
'telegram_raw']`, сообщения об ошибке обновлены. Проверил весь файл на другие закрытые
перечисления — `VALID_STRATEGIES` (:24-31) это отдельный enum `strategy`, его не касается, других
закрытых списков `by`/стратегий нет.

Проверил, что гейт не ослаблен: временно поставил `community_messages.by = 'bogus_value'` —
`testPlayerDataEntriesHaveLink` покраснел («Failed asserting that an array contains
'bogus_value'»), затем восстановил `'telegram_raw'` — снова зелёный. Гейт по-прежнему ловит
незнакомый способ связи, просто белый список расширен легитимным значением.

`tests/unit/Config/CommunityWipeClassificationTest.php::testCommunityMessagesIsPlayerDataLinkedByTelegram`
утверждал `by='telegram'` — то самое неверное значение. Переименован в
`...LinkedByRawTelegramId`, утверждает `by='telegram_raw'`, докблок объясняет разницу
`telegram_raw` vs `telegram` (сырой `from.id` vs internal `telegram_users.id`) и как первая версия
теста маскировала дефект моста.

Искал другие места с зашитым `by` для `community_messages` grep'ом по всему репо
(`community_messages` во всех `*.php`, исключая миграции) — других хардкодов `by='telegram'`
не нашёл, только этот один файл.

Прогнал все три разрешённых файла вместе на финальном состоянии:
`vendor/bin/phpunit tests/unit/Config/CommunityWipeClassificationTest.php
tests/unit/Config/WipeManifestCoverageTest.php
tests/unit/Services/Admin/WipeServiceCharacterResetTest.php` — 11 тестов, 493 assertions,
зелёный.
