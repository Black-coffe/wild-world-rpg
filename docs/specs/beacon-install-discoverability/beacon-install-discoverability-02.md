---
story: beacon-install-discoverability-02
spec: beacon-install-discoverability
status: done
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Экран маяков без «Центра телепортации» перестаёт быть тупиком

## Goal

Игрок без постройки «Центр телепортации» жмёт «📡 Маяки» и получает голое текстовое сообщение
без единой кнопки — ни «🏗 Строить», ни возврата. После этой story отказ остаётся отказом,
но несёт путь дальше: кнопку на стройку и кнопку назад. За месяц в этот тупик попали двое
из девяти открывавших экран.

## Requirements

> «Для установки телепорт-маяков нужно здание *Центр телепортации*. Построй его, а потом возвращайся!»

> А как маяки устанавливать? Работает сейчас это? Сообщение пролетело с Wild World Info ветки чат.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/TeleportBeacon.php
- tests/unit/TeleportBeacon/BeaconScreenRefusalHasWayForwardTest.php

## Non-goals
- Не менять смысл отказа: без «Центра телепортации» маяк ставить по-прежнему нельзя.
  Чинится тупик, а не предусловие.
- Не трогать 8-шаговую валидацию установки (`BeaconPlacementValidator`) и `BeaconInstaller`.
- Не трогать happy-path-ветку экрана (список маяков, лимиты, кнопку «Установить маяк здесь»).
- Не переносить `sendError` в общий helper и не рефакторить остальные его вызовы в файле:
  правится ветка «нет Центра телепортации», остальные `sendError` — настоящие ошибки данных.
- Не заводить `GameSettings`-ключей: чисел баланса story не вводит.

## Map slice
`memory/map/bases.md` (постройки, вход в стройку), `memory/map/telegram.md` (callback-маршруты).

## Acceptance criteria
- [ ] При отсутствии постройки «Центр телепортации» ответ содержит клавиатуру, а не только текст.
- [ ] В клавиатуре есть путь на стройку (существующий `callback_data` входа в стройку —
      взять фактический из кода, не выдумывать) и путь назад на экран базы.
- [ ] Текст по-прежнему называет причину отказа и постройку «Центр телепортации».
- [ ] Ни один ряд не состоит из одной кнопки.
- [ ] Тест падает, если клавиатуру убрать.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TeleportBeacon/`

## Implementation notes
- `TeleportBeacon.php`: ветка «нет Центра телепортации» теперь строит клавиатуру
  (`🏗 Строить`→`Build`, `🏠 База`→`Base`, оба callback подтверждены существующими в `CallbackRoutes`)
  через новый приватный метод `noTeleportCenterKeyboard()` и передаёт её в `sendError()`.
- `sendError()` получил опциональный третий параметр `?string $replyMarkup = null` (backward
  compatible) — остальные 4 вызова `sendError` в файле не переданы никаких изменений, поведение
  сохранено.
- Тест `BeaconScreenRefusalHasWayForwardTest.php`: полный `handle()` требует живого
  `Longman\TelegramBot\Entities\CallbackQuery` + DB-моделей — такого паттерна нигде в
  `tests/unit` нет, поэтому клавиатура вынесена в изолируемый метод и тестируется через
  `ReflectionClass::newInstanceWithoutConstructor()` + вызов метода напрямую (без создания
  `TeleportBeacon` с зависимостями). Регресс-гвард на «клавиатуру не забыли передать в
  sendError()» — через regex по исходнику файла (source-scan), т.к. полный DB/Telegram-харнес
  для controller-уровня — вне scope story. Вручную проверено: откат строки `return
  $this->sendError($chatId, $errorText, ...)` на старую (без клавиатуры) — тест красный (1 failure);
  восстановление — снова зелёный (16/16).

## Findings

### Ремонт (2026-09-08): phpstan-ошибка на `json_encode()` возвращающем `string|false`
`sendError()` объявлен как `?string $replyMarkup = null`, а вызов на строке 88 передавал
`json_encode(...)` напрямую — при неудаче кодирования (в теории — например NAN/inf в данных,
здесь невозможных, но статика этого не знает) в `sendError()` уехало бы `false`, что не строка
и не null. Правка: результат `json_encode()` сохраняется в `$keyboardJson`, и в `sendError()`
передаётся `$keyboardJson !== false ? $keyboardJson : null` — при неудаче кодирования игрок
всё равно получает текст отказа, просто без клавиатуры (не пустота, не исключение).
Source-scan regex в `BeaconScreenRefusalHasWayForwardTest::testRefusalBranchStillPassesKeyboardToSendError`
обновлён под новую форму строки — вручную проверено (откат к варианту без переменной
`$keyboardJson` красит тест: 1 failure; восстановление — снова 16/16). phpstan на файле:
0 ошибок.
