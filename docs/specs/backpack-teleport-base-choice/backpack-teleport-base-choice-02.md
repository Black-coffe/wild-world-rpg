---
story: backpack-teleport-base-choice-02
spec: backpack-teleport-base-choice
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 2
blocked_by: [backpack-teleport-base-choice-01]
---

# Экран выбора базы в телепорте

## Goal
При ≥2 активных базах любой из четырёх способов телепорта показывает экран «Куда прыгаем?» с кнопкой на каждую базу; нажатие выполняет телепорт на выбранную базу. С одной базой — прыжок сразу, как раньше.

## Requirements
> Достал рюкзак-телепорт : и вот тут вопрос - по какому принципу и на какую базу закидывает?
> Нужно разобраться, прораьботать решение и реализовать его на проде и препроде

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/TeleportUseAction.php
- app/Services/Player/TeleportUse/TeleportUseMessageFormatter.php
- tests/unit/Services/Player/TeleportUseMessageFormatterBaseChoiceTest.php

## Non-goals
- Не менять `TeleportUseValidator` (story 01; при несоответствии контракта — Findings, не правка).
- Не добавлять «главную базу», сортировку по расстоянию, GameSettings.
- Не трогать экран «📡 Маяки», `TeleportBeacon*`, респавн.
- Не регистрировать новый action-класс: расширить разбор `callback_data` в существующем `TeleportUseAction`. Проверить, как роутер матчит `TeleportUse_*` — если матч точный по полному имени, зарегистрировать хвостовые варианты тем же способом, что уже принят в этом хендлере/роутере (в Files ничего сверх не добавлять; если роутер требует правки другого файла — Findings и стоп).

## Map slice
memory/map/telegram.md (роутинг callback, MediaSender, `packButtonRows`); story 01 §Contract.

## Contract (из plan.md)
- Callback `TeleportUse_<Kind>_<claimedCellId>`; `TeleportUse_<Kind>` без хвоста остаётся валидным.
- Экран выбора: текст самодостаточен (media-off, ADR-020): «У тебя N баз. Куда прыгаем?» + список; кнопки `🏠 <camp_name|База> (<x>,<y>)`, ряды через `packButtonRows()` (никогда 1 в ряд), плюс кнопка назад в раздел телепортов. Стоимость/заряды НЕ списываются на этом экране.
- Markdown-safe: `camp_name` экранировать (legacy Markdown: `*`, `_`).

## Acceptance criteria
- [ ] 2 базы → экран выбора, ничего не списано; нажатие на базу → телепорт именно туда, списание как раньше.
- [ ] 1 база → прыжок сразу (регрессии нет), для всех 4 способов.
- [ ] Чужой id в callback → сообщение «база не найдена», без списания.
- [ ] Экран читается без картинки; кнопки 2–3 в ряд; имя базы с `_`/`*` не ломает рендер (тест форматтера).
- [ ] firehose (`player_action_log`) пишет новый callback автоматически (единая точка ADR-148) — не обходить роутер.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Player/TeleportUseMessageFormatterBaseChoiceTest.php`

## Implementation notes

## Findings
