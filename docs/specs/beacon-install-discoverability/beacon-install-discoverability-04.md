---
story: beacon-install-discoverability-04
spec: beacon-install-discoverability
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [beacon-install-discoverability-02]
---

# Гвард отказа должен ловить пропажу кнопок, а не переписанную строку

## Goal

Регресс-гвард story 02 пинит форму исходника, а не поведение. Ревью проверило мутацией:
подмена условия отправки на `if (false)`, при которой `reply_markup` не уезжает в
`Request::sendMessage()` вообще, оставляет тест ЗЕЛЁНЫМ; а безобидный рефактор
`$keyboardJson ?: null` — краснит. То есть кнопки под отказом могут исчезнуть молча,
и починенная сегодня дверь снова станет тупиком без единого красного теста.
После story существует утверждение, падающее ровно тогда, когда параметры отправки
отказа теряют `reply_markup`.

## Requirements

> «Для установки телепорт-маяков нужно здание *Центр телепортации*. Построй его, а потом возвращайся!»

> Ни кнопки «🏗 Строить», ни возврата.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportBeacon.php
- tests/unit/TeleportBeacon/BeaconScreenRefusalHasWayForwardTest.php

## Non-goals
- Не менять поведение экрана: набор кнопок отказа («🏗 Строить», «🏠 База») и текст остаются теми же.
- Не строить контроллерный харнесс под `handle()` с живым `CallbackQuery` и моделями БД —
  это отдельная задача, здесь она не нужна.
- Не трогать остальные 4 вызова `sendError` в файле и не менять их поведение.
- Не оставлять регулярку по исходнику единственной защитой. Если она остаётся как
  дополнение — она не должна краснеть от эквивалентного рефакторинга.
- Не заводить GameSettings-ключей и не трогать схему БД.

## Map slice
`memory/map/telegram.md` (action-handler'ы, отправка сообщений).

## Acceptance criteria
- [ ] Существует тест, который краснеет, если `reply_markup` перестаёт попадать в параметры
      отправки отказа — независимо от того, как переписан исходный текст метода.
- [ ] Тест НЕ краснеет от эквивалентного рефакторинга, не меняющего поведение
      (проверить хотя бы на замене тернарника `!== false ? … : null` на `?: null`).
- [ ] Прежние проверки (клавиатура строится, в ней «🏗 Строить» и «🏠 База», ни одного
      ряда из одной кнопки) остаются.
- [ ] `vendor/bin/phpstan analyse` по этому файлу — без ошибок.
- [ ] В отчёте назови, ЧЕМ именно ты проверил оба пункта выше — какую мутацию вносил
      и что наблюдал. Утверждение без проведённой мутации не принимается.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TeleportBeacon/`

## Implementation notes

- `TeleportBeacon.php`: вынес построение параметров отказа из `sendError()` в отдельный
  приватный метод `buildSendErrorParams(chatId, message, replyMarkup): array` — тест зовёт
  его через рефлексию напрямую, минуя `Request::sendMessage()`/сеть и живой `CallbackQuery`.
  `sendError()` теперь просто передаёт результат в `Request::sendMessage()`.
- `BeaconScreenRefusalHasWayForwardTest.php`: добавлены
  `testBuildSendErrorParamsCarriesReplyMarkupWhenGiven` (красит на пропаже `reply_markup`)
  и `testBuildSendErrorParamsOmitsReplyMarkupWhenNull`. Регэксп в
  `testRefusalBranchStillPassesKeyboardToSendError` ослаблен: больше не фиксирует конкретную
  форму тернарника, только что `$keyboardJson` передаётся третьим аргументом в `sendError()`.
- Мутация 1 (`if ($replyMarkup !== null)` → `if (false)` внутри `buildSendErrorParams`):
  вручную заменил через `sed`, прогнал `vendor/bin/phpunit tests/unit/TeleportBeacon/` —
  красный `testBuildSendErrorParamsCarriesReplyMarkupWhenGiven` («Failed asserting that an
  array has the key 'reply_markup'»), 17/18 остались зелёными. Откатил правку тем же `sed`.
- Мутация 2 (`$keyboardJson !== false ? $keyboardJson : null` → `$keyboardJson ?: null` в
  `handle()`): вручную заменил через `sed`, прогнал тот же файл тестов — все 18 тестов
  зелёные (включая ослабленный регэксп-тест и новую пару поведенческих тестов). Откатил
  правку тем же `sed`, финальный diff файла контроллера идентичен состоянию до мутаций.

## Findings
