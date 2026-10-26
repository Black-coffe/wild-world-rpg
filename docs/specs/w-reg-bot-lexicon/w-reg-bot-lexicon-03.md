---
story: w-reg-bot-lexicon-03
spec: w-reg-bot-lexicon
status: todo
returned:
tier: 2
worker: worker-code
wave: 3
blocked_by: [w-reg-bot-lexicon-02]
---

# Советы в БД + карточка класса с проверкой

## Goal
Одна идемпотентная миграция правит тексты `game_tips` с бот-лексикой (по `title_en`, `REPLACE`; состав — по SELECT
на testbot). Карточка `docs/defects/bot-lexicon-in-web.md` (`status: block`) с `check:` — скрипт, который
токенайзером читает строковые литералы и inline-HTML в `app/` (без миграций) и в сид-миграциях советов новее
миграции-фикса и падает на любой фразе класса («нижнем меню», «клавиатуры ниже», «экран бота», счёт кнопок меню)
вне явного списка исключений (файл + фраза + причина). Две фикстуры: исходная фраза F7 и соседняя форма.

## Requirements
> 2. Советы в БД с этими фразами исправлены одной идемпотентной UPDATE-миграцией.
> 3. Карточка дефекта «бот-лексика в вебе» с проверкой, которая падает на исходной фразе и на соседней форме и не зависит от добровольной пометки.

## Files
- app/Database/Migrations/2026-12-21-100000_FixBotLexiconInTips.php
- scripts/defects-bot-lexicon-check.php
- docs/defects/bot-lexicon-in-web.md
- docs/defects/INDEX.md
- docs/defects/fixtures/bot-lexicon-in-web-original.php
- docs/defects/fixtures/bot-lexicon-in-web-neighbour.php

## Non-goals
- Не править старые миграции (они уже применены).
- Не делать проверку зависимой от пометки в тексте или от таблицы в плане: нет исключения — падение.
- Не ловить «/команды» (решение владельца: они работают и в вебе).

## Map slice
`docs/defects/README.md` (формат карточки), `docs/defects/entry-off-audience-path.md` (прецедент `check:`),
`app/Database/Migrations/2026-10-27-100000_FixStaleMenuLabelsInPlayerTexts.php` (прецедент UPDATE советов).

## Acceptance criteria
- [ ] `php scripts/defects-bot-lexicon-check.php` без аргумента на дереве после story 02 выходит 0; с аргументом-фикстурой — не 0 на обеих.
- [ ] Комментарий с фразой класса проверку не роняет; литерал с ней — роняет (соседняя фикстура держит оба случая: фраза в комментарии и в двойных кавычках с подстановкой).
- [ ] Миграция при повторном запуске ничего не меняет; после неё SELECT по паттернам `game_tips` на testbot пуст.
- [ ] `bash scripts/defects-check.sh` зелёный, карточка в INDEX.

## Verification
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
`bash scripts/defects-check.sh`

## Implementation notes

## Findings
