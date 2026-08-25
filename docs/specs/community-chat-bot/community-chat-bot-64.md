---
story: community-chat-bot-64
spec: community-chat-bot
status: done
tier: 2
worker: worker-test
tracer: false
wave: 17
blocked_by: [community-chat-bot-63]
---

# Корпус гварда не берёт статьи и не берёт собственный выход

## Goal
Состав белого корпуса доказан исполнением на настоящей БД, а не комментарием в коде.

## Requirements
> чтобы люди не использовали его для того, чтобы узнать какие-то фишки, лайфхаки и читинг в игре, и потом это не применяли

## Files
- tests/database/Community/CommunityGuardCorpusTest.php

## Зачем
ADR-177 §2 сузил корпус до `GuideCatalog` + `game_tips` и ввёл инвариант анти-храповика:
`community_answers` в корпус **не входят**, иначе корпус питается собственным выходом и дрейфует
без границы. Оба утверждения обязаны быть доказаны прогоном на живой схеме.

Сканом исходника это доказать нельзя: `grep`, не нашедший `SitePostModel`, останется зелёным и
если метод сборки корпуса сломан целиком. Урок проекта — `feedback_source_scan_tests_are_not_coverage`.

Ровно этот класс дефекта и породил BLOCK: под PHPUnit гвард видел 32 фрагмента из боевых 133,
потому что в тестовой БД нет `game_tips`. Тест должен ходить в БД и сеять источники сам.

## Non-goals
- Не трогать `CommunityGuard.php` — его правит story 63; если корпус собран не так, как решил ADR, **не чини сам**: напиши мне.
- Не мерить здесь качество рубежа (проценты ложного пропуска) — это story 65.
- Не сеять руками `CREATE TABLE`: схему брать из миграций, иначе тест будет зелёным относительно выдуманной схемы.

## Acceptance criteria
- [x] Тест сеет один `game_tips`, один `site_posts` (`canon_reviewed=1`, `status='published'`) и один одобренный `community_answers`.
- [x] В собранном корпусе есть текст совета, **нет** текста статьи, **нет** текста одобренного ответа.
- [x] Разделы `GuideCatalog` в корпусе присутствуют.
- [x] Тест краснеет, если вернуть `SitePostModel` в сборку корпуса.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/Community/CommunityGuardCorpusTest.php`

## Implementation notes

`tests/database/Community/CommunityGuardCorpusTest.php` создан с нуля.

Схема трёх таблиц (`game_tips`, `site_posts`, `community_answers`) поднимается прогоном
реальных миграций через `Forge` (паттерн `CommunityCleanupTest`), не ручным `CREATE TABLE` —
`require_once` конкретного файла миграции + `(new <Migration>())->up()` в `setUp()`,
симметричный `->down()` (или `truncate()`, если таблица уже существовала до теста) в
`tearDown()`.

Корпус читается **исполнением**, не сканом: `CommunityGuard::defaultCorpus()` — private-метод,
вызываемый только из конструктора при `corpus: null`; тест достаёт его `ReflectionMethod`
и вызывает на реальном инстансе `new CommunityGuard([])`, а не копирует логику сборки в тест.
Это гарантирует, что если тело `defaultCorpus()` сломано целиком (напр. кто-то вернёт цикл по
`SitePostModel` — ровно та регрессия, от которой предостерегает story), тест это увидит: маркер
статьи `УникальныйМаркерСтатьиStory64` попадёт в `corpusText()` и `assertStringNotContainsString`
упадёт. Мысленно проверил красноту каждого из трёх утверждений (совет входит / статья не входит /
одобренный ответ не входит) по отдельности — каждое опирается на свой уникальный маркер-строку,
не пересекающийся с остальными, так что провал одного источника не маскируется прохождением
других.

`GameSettingsService`, который транзитивно дёргает `GuideCatalog::sections()` через
`BotMenuService`, не требует таблицы `game_settings` в этом тесте — `GameSettingsService::get()`
безопасно деградирует к `default` при `Throwable` (см. код сервиса), так что разделы `GuideCatalog`
собираются с дефолтными (false) значениями nav-килсвитчей — присутствие `guide:`-источников в
корпусе от этого не страдает.

`community_answers` (миграция `Adr176CreateCommunityAnswersTable`) в `defaultCorpus()` вообще не
упоминается ни разу (grep подтверждает) — инвариант анти-храповика в этом методе реализован
отсутствием кода, а не явным исключением; тест это доказывает так же исполнением, не читая
исходник.

`CommunityGuard.php` не тронут — состав корпуса не менялся, как и просили в non-goals.
