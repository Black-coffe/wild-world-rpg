---
story: backpack-teleport-base-choice-02
spec: backpack-teleport-base-choice
status: done
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

- `TeleportUseAction::handle()`: switch на точный `getData()` заменён на `parseCallbackData()`
  (regex `^TeleportUse_(Portable|WithExperience|WithGold|Backpack)(?:_(\d+))?$`). Роутер не тронут:
  `CallbackqueryCommand::execute()` резолвит класс по `explode('_', $data)[0]` = `TeleportUse` для
  ЛЮБОГО хвоста, так что `TeleportUse_Backpack_242` уже приходил в этот класс и раньше — просто
  `switch` его не понимал. Non-goal «не регистрировать новый action-класс» выполнен без правки
  роутера/другого файла.
- Все 4 `use*Teleport()` получили `?int $claimedCellId = null` и общую ветку `handleReason()`:
  `reason=choose_base` → `formatter->chooseBase($kind, bases)` (ничего не списано), `reason=no_base`
  → `formatter->baseNotFound()`. При 0/1 активной базе `TeleportUseValidator` ведёт себя как раньше
  (story 01), так что `handleReason()` возвращает `null` и falls through к старому коду — 1:1
  регрессия отсутствует.
- `TeleportUseMessageFormatter::chooseBase()`: текст самодостаточен без картинки (ADR-020) — число
  баз + список `🏠 <имя> (X=.., Y=..)`; кнопки `TeleportUse_<Kind>_<id>` через `ButtonPacker::pack()`
  (гарантия «нет одиночного ряда» уже проверена `ButtonPackerTest`, здесь ре-проверена на своих
  данных n=2..7). Хвостовой ряд «Назад к телепорту / 🏠 База» — намеренно 2 кнопки, чтобы не быть
  единственной строкой из одной.
- Имя базы санитайзится через уже существующий `App\Services\Display\MarkdownSafe::name()` (правило
  legacy-Markdown — вырезание, а не экранирование обратным слэшем, `*`/`_`/`` ` ``/`[`/`]` убираются
  целиком) — второй копии `escapeMarkdown()` не заводил.
- Кнопка «Назад» ведёт на `TeleportToCamp` (реальный route-key хаба `Camp\TeleportAction` в
  `CallbackRoutes.php`) — в контракте истории было написано `teleportScreen`, такого роута нет,
  использован фактический.
- phpstan потребовал явного `is_scalar(...) ? (string) : fallback` для `camp_name`/`coordinate_x/y`
  (mixed из БД) и типизирующего фильтра `extractBases()` в `TeleportUseAction` (превращает
  `mixed $result['bases']` в `array<int, array<string,mixed>>` без `@phpstan-ignore`).
- Тест `TeleportUseMessageFormatterBaseChoiceTest` — только форматтер (media-off самодостаточность,
  callback_data с kind+id, «нет одиночной кнопки в ряду» на n=2..7 баз, markdown-safe имя базы,
  fallback «База» при пустом имени, `baseNotFound()` без `reply_markup`). Валидатор/роутинг не
  перепроверялись — это story 01 и существующий `CallbackqueryCommand` (не менялся).
- `vendor/bin/phpunit --no-coverage --no-progress` (formatter + validator + ButtonPacker тесты) —
  19 тестов зелёные; `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — 0 ошибок.
  Tier-3 (preprod, тест-чар с 2 базами) не выполнен в этой задаче — не было доступа к живому
  Telegram-сеансу; см. `plan.md`/Integration gate — Tier-3 остаётся открытым перед тегом на develop.
- Guide/Tips-вердикт для этой story — «да», уже зафиксирован в `plan.md` (раздел `teleport` /
  seed-совет `персонаж`) и относится к story 03, которая его реализует; здесь не дублировался.

## Findings

Нет — контракт story 01 (`validate*(character, ?claimedCellId)`, `reason`∈{`choose_base`,`no_base`},
`bases` от `listActiveBases()`) совпал 1:1, роутер менять не пришлось (см. Implementation notes).
