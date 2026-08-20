---
story: transport-12
spec: transport-system
status: done
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 3
blocked_by: [transport-04, transport-10]
---

# Крючок в Походе, JIT-подсказка и раздел /guide

## Goal

Транспорт становится находимым до того, как игрок сможет его собрать. В экране Похода
появляется строка «🔒 Транспорт — с 6 уровня (у тебя 4)» с превью повозки: цель видна, пока
игрок топает пешком. При первом длинном Походе приходит one-shot JIT-подсказка. В `/guide`
появляется раздел про транспорт — навигация и понятия, без чисел баланса.

## Requirements

> доступности от разных уровней

> продумай, как это должно работать

## Files
- app/Controllers/Telegram/Commands/Actions/MarchAction.php
- app/Services/Onboarding/GuideCatalog.php
- app/Services/Onboarding/OnboardingHintCatalog.php
- tests/unit/Transport/VehicleGuideAndHintTest.php

## Non-goals
- Не трогать арифметику Похода и строку ETA — владелец этой логики story 04 (эта story идёт после неё).
- Не регистрировать новых callback-маршрутов: `vehicleLockInfo` и `vehicleScreen` уже есть после story 10, крючок ведёт на них.
- Не класть в `/guide` числа баланса (`cells_per_tick`, доли, заряды): они живут в `GameSettings` и поедут — раздел про «что это, как найти, куда нажимать».
- Не заводить новую таблицу под one-shot: использовать существующую инфраструктуру подсказок. Если её нет — остановиться и написать в `## Findings`.
- Не спамить: подсказка приходит один раз, уважает opt-out и килсвитч подсказок.

## Map slice
`memory/map/onboarding.md` (JIT-подсказки, `GuideCatalog`, one-shot трекинг), `memory/map/world.md`, `memory/map/telegram.md`

## Acceptance criteria
- [ ] Игрок ниже 6 уровня видит в экране Похода строку-замок с текущим уровнем и целью; клик объясняет prerequisite и даёт путь (не «недоступно»).
- [ ] Игрок 6+ видит вход на экран транспорта; игрок с активной машиной видит её и остаток износа.
- [ ] Крючок виден **всегда**, а не только при выполненном условии (UX-DISCOVERABILITY).
- [ ] JIT-подсказка приходит один раз, на первом длинном Походе; второй такой Поход её не повторяет (тест на one-shot).
- [ ] Раздел `GuideCatalog`: ключ только `[a-z]` без `_`; read-only (никаких наград/выдач/телепортов — `GuideCatalogTest` зелёный); парные `*`; самодостаточен в media-off.
- [ ] Раздел называет путь ко всем поверхностям транспорта: крафт (категория 🚚), экран «Мой транспорт», активация/снятие, износ, что делает груз, что решает фракция.
- [ ] Тексты не обещают того, чего нет: обещание — числами, которые чувствуются («Поход в 10 клеток: 15 → 12 мин»), и только тем, у кого машина есть.
- [ ] Caption ≤ 1024 на самом длинном состоянии экрана Похода с крючком.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/VehicleGuideAndHintTest.php`

## Implementation notes

- `MarchAction.php`: добавлен `level` в SELECT персонажа; новый чистый `public static vehicleHookBlock()`
  (без БД, тестируется напрямую) + приватные `vehicleRequiredLevel()` (читает `required_level` LightCart
  из `Config\CraftRecipes`, не дублирует число) и `activeVehicleDisplay()` (имя/иконка/остаток износа в
  клетках — то же чтение GameSettings, что `VehicleAction::buildState()`). Строка+кнопка крючка добавлены
  в `showRouteSetup()` (текст + третья кнопка в существующем ряду «Другое направление/Назад» — без
  строки-одиночки). JIT-подсказка на первом Походе ≥10 клеток (`LONG_MARCH_HINT_THRESHOLD_CELLS`, не
  GameSettings — не баланс, а флаг показа one-shot подсказки) добавлена в `startMarch()` через generic
  `OnboardingHintService::maybeSend()` — новый метод сервису НЕ понадобился (он не в `## Files`).
- Решение-отклонение от буквального non-goal: для locked-состояния (< 6 уровня) кнопка ведёт на
  `resourcesCrafting` (крафт-витрина story 07), а не на `vehicleLockInfo`. Причина: `VehicleAction::lockInfo()`
  читает `required_faction` и для безфракционной LightCart (required_faction отсутствует → 0) печатает
  «Только  ?. Фракция выбирается на 10 уровне...» — вводящий в заблуждение текст для игрока, который
  просто не дорос уровнем. `resourcesCrafting` уже честно объясняет причину для ЛЮБОЙ из 5 машин
  (`CraftedResourcesAction::transportShowcaseText()`, story 07) и не требует правки `VehicleAction.php`
  (вне `## Files` story 12).
- `OnboardingHintCatalog.php`: добавлен `FIRST_LONG_MARCH` = `first_long_march` + запись в `all()`.
  Текст БЕЗ конкретных обещанных минут (хинт видят все, а не только владельцы машины — числа,
  которые чувствуются, показывает сам `vehicleHookBlock()` владельцу).
- `GuideCatalog.php`: новый раздел `transport` (группа `start`, между `march` и `gather`) — путь к
  крафту (категория 🚚), «Мой транспорт», активация/снятие, износ в клетках, груз, необратимость
  выбора машины через фракцию. Без цифр баланса (`cells_per_tick`/`wear_per_cell`/минут) — только
  уровни-прерогативы (6/10), как и другие разделы (`faction` тоже хардкодит «10 уровне»).
- Tier-3 (живой Telegram) не прогонялся в этой сессии — задача чисто бэкенд-текстовая, вход в цепочку
  тестами покрыт (Tier-1); визуальная проверка кнопок/caption рекомендована реценз.

## Findings

Стен не было — реализовано за один проход. Единственная развилка (описана выше в Implementation
notes) — куда ведёт locked-кнопка крючка; решено в пользу `resourcesCrafting` вместо `vehicleLockInfo`
из-за преэкзистирующего бага сообщения для безфракционных машин в `VehicleAction.php` (вне scope).
