---
story: pvp-detection-clarity-17
spec: pvp-detection-clarity
status: done
tier: 1
worker: worker-code
tracer: false
wave: 6
blocked_by: []
---

# Новичок без @username снова получает настоящее имя, а не машинное

## Goal

После story новый игрок, у которого в Telegram нет `@username`, проходит шаг автогенерации имени
из `character_names`, как это было до ветки, — а не остаётся навсегда с машинным `Путник-<id>`.

## Requirements

> BLOCK, major #6: `StartCommand.php:116` чеканит `Путник-<id>`, а `AutoGenerateNameAction.php:37` считает персонажа безымянным только при `empty($name) || in_array($name, ['Unknown Hero','NAN',null])`. Теперь ветка не срабатывает: вместо выдачи имени из `character_names` игрок получает «🆔 Твоё текущее имя персонажа: *Путник-42*» и остаётся с машинным именем навсегда.

> Это регресс, внесённый story `-07` при устранении «Unknown Hero»: литерал заменили, а места, которые его узнавали, не обошли.

## Files
- app/Controllers/Telegram/Commands/Actions/StartGame/AutoGenerateNameAction.php
- tests/unit/Controllers/Telegram/StartCommandMintDistinctNameTest.php

## Non-goals
- Не возвращать «Unknown Hero» — от него ушли намеренно, он утекал игроку как имя.
- Не трогать `StartCommand.php` — чеканка `Путник-<id>` как заглушки остаётся; узнавать её должен потребитель.
- Не переносить признак «имя ещё не выбрано» в новую колонку БД: это правка на один вызов, а не миграция схемы. Если по ходу окажется, что без колонки честно не выходит, — остановиться и доложить, а не расширять скоуп молча.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`AutoGenerateNameAction.php:37` — список литералов «персонаж ещё не назван»;
`StartCommand.php:116` — чеканка `Путник-<id>` и ветка с `@username`;
`grep -rn 'Unknown Hero' app/` находит остальные места, узнававшие старый литерал, — обойти ВСЕ,
а не только то, что названо в находке.

## Acceptance criteria
- [ ] Игрок с машинным именем `Путник-<id>` считается ещё не назвавшимся: шаг автогенерации имени из `character_names` ему предлагается. Тест закрывает именно этот вход.
- [ ] Игрок, который уже выбрал себе имя, шага автогенерации НЕ получает — существующее поведение не ломается. Тест закрывает и этот случай.
- [ ] Признак «имя ещё не выбрано» опознаётся в одном месте, а не размножен по литералам: следующий, кто сменит формат заглушки, правит одну строку. Если такое место уже есть — использовать его, не заводить второе.
- [ ] Выполнен `grep -rn 'Unknown Hero' app/` и `grep -rn 'Путник-' app/`; полный список мест, узнающих признак «без имени», приведён в отчёте, и каждое либо обновлено, либо названо с причиной, почему его трогать не надо.
- [ ] Имя, попадающее игроку в сообщение, экранировано и не выдаёт аккаунт Telegram — то, ради чего story `-07` затевалась, не откатывается.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Telegram/StartCommandMintDistinctNameTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `AutoGenerateNameAction::isUnnamed()` (новый `private static` метод) — единственное место,
  узнающее «персонаж ещё не назван»: старые литералы `Unknown Hero`/`NAN`/`null`/пустая строка
  + regex `/^Путник-\d+$/u` на машинную заглушку `StartCommand::mintDistinctName()`. Условие в
  `handle()` заменено на вызов этого метода.
- `StartCommand.php` не тронут (non-goal) — `mintDistinctName()` остаётся публичным static-хелпером,
  regex в `isUnnamed()` завязан на его формат вывода, а не на дублирующую константу.
- Тесты добавлены в `StartCommandMintDistinctNameTest.php` (файл назван в story) через Reflection
  на приватный статический метод: заглушка распознаётся, выбранное имя — нет, старые литералы —
  распознаются (регресс к прежнему поведению для legacy-строк не создан).
- `RelocateAbandonedCharacters.php:203` (штампует `'Unknown Hero'` в РАЗОВОЙ CLI-команде для
  строк с пустым/отсутствующим именем) не в `## Files` story — не трогал. Он производит литерал,
  а не распознаёт его, так что регресса из -07 там нет; но эти строки после его прогона попадут
  под старую ветку `in_array(..., 'Unknown Hero')` в `isUnnamed()`, которая сохранена намеренно.

## Findings

- Полный список мест, узнающих признак «игрок ещё не выбрал имя» (после `grep -rn 'Unknown Hero' app/`
  и `grep -rn 'Путник-' app/`):
  1. `AutoGenerateNameAction.php:37` (было) → заменено на `AutoGenerateNameAction::isUnnamed()`.
     Обновлено: теперь распознаёт и `Путник-<id>`, и старые литералы.
  2. `StartCommand.php:406` (`mintDistinctName`) — не узнаёт признак, а производит заглушку.
     Не трогал (non-goal, вне `## Files`).
  3. `StartCommand.php:93` — комментарий, упоминающий литерал `'Unknown Hero'` текстом (не код,
     не логика). Оставлен как есть — не место распознавания.
  4. `RelocateAbandonedCharacters.php:203` — штампует `'Unknown Hero'` для relocation-строк без
     имени; это производитель литерала (аналог StartCommand), не потребитель/распознаватель.
     Вне `## Files` story — не трогал. После его прогона такие строки всё ещё попадут под
     сохранённую ветку `in_array($name, ['Unknown Hero', 'NAN'], true)` в `isUnnamed()`.
  Других мест, проверяющих `character.name` на «ещё не назван» (по этим двум grep), в `app/` нет.
