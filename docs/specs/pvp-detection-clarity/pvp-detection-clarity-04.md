---
story: pvp-detection-clarity-04
spec: pvp-detection-clarity
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Вышка срабатывает и на обычном шаге, а её сообщение перестаёт теряться на именах

## Goal

После story Дозорная вышка предупреждает владельца базы при обоих способах приближения чужого
игрока — и шагом Похода, и обычным одноклеточным шагом. Её сообщение уходит с `parse_mode = HTML` и
экранированным именем, поэтому имя со звёздочкой или подчёркиванием больше не даёт 400 и тихий
no-send. Срабатывание видно в аудите.

## Requirements

> а как же вышка, что оповещает если другой игрок рядом?

> хз , у меня уведомлений не было никаких и сооружения защитные целые

> Наступил рядом

## Files
- app/Services/PVE/TowerAlertService.php
- app/Controllers/Telegram/Commands/Actions/MoveCharacterToDirectionAction.php
- tests/database/TowerAlertServiceTest.php

## Non-goals
- Не менять радиус и кулдаун оповещения: `defense.tower.alert_range_cells` и `defense.tower.alert_cooldown_sec` уже живут в `GameSettings` и трогать их не надо.
- Не добавлять кнопки в сообщение вышки: действия защитника — это окно противостояния, story `-09`.
- Не трогать `PlayerDetectionService` (его правит `-07`) и не переносить туда вызов вышки.
- Не переводить на HTML другие сообщения проекта: здесь ровно `sendAlert()`.
- Не запускать полный набор и не делать `DROP`/`migrate` на общей локальной тест-БД; не делать `git stash`/`git checkout`.

## Map slice
`app/Services/PVE/TowerAlertService.php:53-105` (`notifyTowersNear`) и `:167-177` (`sendAlert`,
`'parse_mode' => 'Markdown'`, имя вставляется как `*{$moverName}*`);
`app/TaskHandlers/MarchingTaskHandler.php:319-324` — единственный нынешний вызывающий, образец вызова;
`app/Controllers/Telegram/Commands/Actions/MoveCharacterToDirectionAction.php:452` и `:493` — места,
где уже зовётся обнаружение игроков; ADR-186 §8 (почему HTML), ADR-031 (вышка).

## Acceptance criteria
- [ ] `TowerAlertService::notifyTowersNear()` вызывается из обычного шага игрока, в том же месте пути, где уже зовётся обнаружение соседей, и с теми же аргументами по смыслу, что в `MarchingTaskHandler:319-324`. Полный список вызывающих получен `Bash`-грепом (`grep -rn 'notifyTowersNear' app/`) и приведён в отчёте.
- [ ] Оповещение не дублируется, если за один шаг сработали оба пути: кулдаун `defense.tower.alert_cooldown_sec` по-прежнему единственная защита от спама, и он соблюдается.
- [ ] Владелец вышки не получает оповещения о самом себе.
- [ ] `sendAlert()` шлёт `parse_mode = HTML`; имя чужого персонажа экранируется, а не подставляется сырым. Имя вида `a*b_c` больше не может дать 400.
- [ ] Сообщение самодостаточно текстом (media-off): кто, где, на каком расстоянии — без опоры на картинку.
- [ ] Срабатывание пишет строку аудита `tower_alert_sent` — иначе измерить постфактум, работала ли вышка, по-прежнему нечем.
- [ ] Существующие 7 тестов `TowerAlertServiceTest` зелёные; добавлены тесты на новый путь вызова и на экранирование имени со спецсимволами.
- [ ] В `## Implementation notes` честно сказано: PHPUnit не рендерит Telegram-сообщение, поэтому факт «оповещение реально пришло» доказывается только Tier-3 на testbot'е двумя аккаунтами.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/TowerAlertServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
