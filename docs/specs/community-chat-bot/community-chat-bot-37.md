---
story: community-chat-bot-37
spec: community-chat-bot
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 9
blocked_by: [community-chat-bot-33]
---

# Фильтр вебхука переживает недоступность настроек

## Goal
Прямой запрос к таблице настроек, добавленный ради наблюдаемости, перестаёт валить фильтр,
который отрабатывает на каждом входящем апдейте.

## Requirements
> как добавить нашего же Telegram-бота, который уже есть, добавить в чат, где идет общение всех игроков

## Files
- app/Filters/TelegramRateLimitFilter.php
- tests/unit/TelegramRateLimitGroupScopeTest.php

## Non-goals
- Не менять сам групповой потолок, его ключ и значение по умолчанию.
- Не трогать `BotController`, community-сервисы, миграции.
- Не убирать наблюдаемый след отката — он нужен, чинится способ его получения.

## В чём дефект
Story 33 научила `groupMaxPerMinute()` отличать «строки настройки нет» от «значение совпало
с fallback'ом случайно» — через прямой `GameSettingsModel::findByKey()`. Вызов не защищён, и
при недоступной таблице исключение уходит наверх ИЗ ФИЛЬТРА, который стоит на каждом апдейте
вебхука.

Полный прогон набора после волны 9 краснеет ровно на этом — три теста
`TelegramRateLimitGroupScopeTest` (`testGroupFloodDoesNotConsumePersonalWindow`,
`testGroupTrafficIsBlockedOnItsOwnKeyEventually`, `testBlockedGroupTrafficProducesNoOutboundCall`)
падают с `Table 'wildworld_tests.game_settings' doesn't exist`. Узкий прогон story 33 этого не
показывал: её собственный тестовый файл таблицу создаёт.

Прод-смысл важнее тестового: сбой или недоступность БД в момент апдейта не должны превращаться
в отказ обработки апдейта. Rate-limit — это защита, а не критический путь; при невозможности
прочитать настройку он обязан деградировать к безопасному значению и оставить след, а не падать.

## Contract
- Чтение настройки группового потолка не может выбросить исключение наружу фильтра ни при каком
  состоянии БД.
- При недоступности настройки применяется прежний безопасный fallback, а факт отката остаётся
  наблюдаемым (след, введённый story 33, сохраняется).
- Различие «строки нет» и «значение совпало случайно» сохраняется, когда БД доступна.
- Тесты, которые не создают `game_settings`, снова зелёные — без создания этой таблицы в них
  (иначе проверка деградации исчезнет).

## Acceptance criteria
- [ ] Три названных теста зелёные без добавления `game_settings` в их фикстуру.
- [ ] При недоступной таблице фильтр работает по безопасному значению и оставляет след.
- [ ] При доступной таблице поведение story 33 сохранено: отсутствующая строка отличается от совпавшего значения.
- [ ] Полный набор зелёный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TelegramRateLimitGroupScopeTest.php tests/unit/TelegramRateLimitFilterTest.php tests/unit/TelegramRateLimitGroupCeilingTest.php`
