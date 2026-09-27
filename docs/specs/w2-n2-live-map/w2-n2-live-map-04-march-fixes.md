---
story: w2-n2-live-map-04
spec: w2-n2-live-map
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 4
blocked_by: []
---

# Поход: msg_id при создании строки и потолок продления

## Goal
Два minor ревью раунда 1 (`.vulyk/reports/w2-n2-live-map/round-1/review.attempt-1.md`), которые
владелец 2026-09-27 велел закрыть до ship:
1. Бот-Поход несёт `msg_chat_id`/`msg_id` в строке `character_tasks` с момента её создания, как до
   рефакторинга (`git show f20704b4:app/Controllers/Telegram/Commands/Actions/MarchAction.php`, ~строка 260).
   Сейчас `attachMessage` зовётся после подсказок и правки (`MarchAction.php:174`), и при
   `minutes_per_cell=1` тик в этом окне шлёт новые сообщения до конца Похода. Веб-Поход по-прежнему
   пишет строку без `msg_id` (plan `## Assumptions`).
2. Продление зажимает `n` в `world.march.max_steps_per_order` (или тот потолок, которым уже пользуется
   `clampOrderToCap`) на обоих путях: веб `op=march_extend` и бот `march_more_<n>` (`MarchService.php:295`).
   Подделанная форма или callback не удлиняет Поход сверх потолка.

## Requirements
> 4. Поход — сервис превью/старта/продления/возобновления/остановки для обоих клиентов; веб видит идущий Поход (прогресс, ETA, «Остановиться») через HUD и опрос; тики в Telegram доставляются как раньше.

## Files
- app/Services/World/MarchService.php
- app/Controllers/Telegram/Commands/Actions/MarchAction.php
- tests/database/MarchServiceTest.php
- tests/database/PlayViewControllerTest.php

## Non-goals
- Не трогать `MarchingTaskHandler`, ключи и значения `world.march.*`.
- Не менять видимый текст бота (снимок паритета из story 03 остаётся зелёным).

## Acceptance criteria
- [ ] Тест: после старта бот-Похода строка задачи уже при вставке несёт `msg_chat_id`/`msg_id` (или сообщение отправлено до вставки и id переданы в `start`) — окна без id нет.
- [ ] Тест: `extend` с `n` больше потолка зажимается в потолок (веб-форма и бот-путь).
- [ ] Снимок паритета бота из story 03 зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
