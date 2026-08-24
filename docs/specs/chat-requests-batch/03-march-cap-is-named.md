---
story: chat-requests-batch-03
spec: chat-requests-batch
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Потолок Похода назван на экране заказа

## Goal
Экран настройки маршрута говорит, сколько клеток можно заказать за раз и почему,
вместо того чтобы молча зажимать введённое число.

## Requirements
> [29.07.2026] Max Syskov: «в походе могу выбрать максимум 60 клеток, это баг или фича?)»

## Files
- app/Controllers/Telegram/Commands/Actions/MarchAction.php
- tests/unit/World/MarchCapTextTest.php

## Notes
Зажим стоит в `showRouteSetup` (~строка 137) и ещё в двух местах: значение берётся из
`profile['max_steps_per_order']`, то есть зависит от машины (ADR-174). Текст обязан
брать это же значение, иначе подкрутка из админки или пересадка на транспорт сделают
подпись ложной. Показывать потолок всегда, а не только при упоре в него: игрок должен
знать границу до того, как в неё врежется.

## Non-goals
- Не менять сам потолок и не выносить новых ключей: `world.march.max_steps_per_order`
  уже в админке.
- Не трогать расчёт ETA, стоимости по здоровью и выносливости.
- Не переписывать разбивку маршрута на сегменты.

## Acceptance criteria
- [ ] Создан и зелёный `tests/unit/World/MarchCapTextTest.php` — он обязан падать на доправочном поведении,
      иначе гейт зелёный впустую (урок «скан исходника ≠ покрытие»).
- [ ] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/MarchAction.php`

## Findings

- В `showRouteSetup()` клэмп `$n` уже читал `profile['max_steps_per_order'] ?? 60` — вынес это в
  переменную `$cap` и переиспользовал её же для клэмпа `$n`, для `$plus` (кнопка ➕5) и для новой
  строки текста. Один источник — как требовали Notes.
- Строку потолка вынес в чистый `private static function capLine(int $cap): string` (по образцу
  `VehicleAction::lockInfoText()`/`activationFailedText()` — статический pure-рендер, тестируемый
  рефлексией без БД). Показывается всегда, сразу после блока «Отряд идёт сам…», до крючка
  транспорта.
- `startMarch()` (третье место с клэмпом, строка ~210) не трогал — там нет текста, только защита
  от инъекции большого `$n` в задачу; правка текста туда не требовалась.
- Тест `tests/unit/World/MarchCapTextTest.php` бьёт по `capLine()` через `ReflectionMethod`
  (аналог `VehicleScreenRenderTest::invokeSavingsMinutes`) — DB/Telegram не нужны, метод чистый.
  Стешил диф `MarchAction.php` (тест-файл при этом остался untracked/добавлен), прогнал —
  все 3 теста упали `ReflectionException: Method ...::capLine() does not exist` (метода не
  существовало до правки). Стеш вернул — тесты снова зелёные. Это и есть доправочное красное.
- `vendor/bin/phpstan …MarchAction.php` — 0 ошибок. Полный `vendor/bin/phpunit --no-coverage
  --no-progress` — 3353 теста (было 3350 + 3 новых), 0 падений, 36 deprecations / 10 skipped —
  тот же фон, что был в baseline до задачи.
- Сомнение: строка потолка не имеет собственного guide/tip-раздела — это доработка текста уже
  существующего экрана «Поход» (не новая механика), поэтому вердикт по UX-DISCOVERABILITY/
  ONBOARDING/GUIDE/TIPS — «нет, потому что это не новая механика, а исправление вводящей в
  заблуждение подписи на уже находимом экране» (не проверял отдельно с командой — можно уточнить
  у Queen, если нужен явный tip про транспорт-зависимость потолка).
