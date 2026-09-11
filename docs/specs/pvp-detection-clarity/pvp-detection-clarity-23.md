---
story: pvp-detection-clarity-23
spec: pvp-detection-clarity
status: done
tier: 1
worker: worker-code
tracer: false
wave: 8
blocked_by: []
---

# Одиночная кнопка уходит в нормализатор, а мост перечисляет причины из источника

## Goal

После story экран «цель под чужой тревогой» собирается тем же нормализатором рядов, что и
остальные экраны, и не нарушает правило «ноль одиночных кнопок в ряду»; а тест-мост между двумя
списками причин берёт их из источника кодов, а не из литерального массива в тесте.

## Requirements

> BLOCK-3, major 1: экран «цель под чужой тревогой» обязан выводить путь дальше так, чтобы ни один ряд клавиатуры не нёс единственную кнопку, и собираться тем же нормализатором рядов, что остальные экраны: сейчас это инлайновый `[[«🗺️ Поход»]]` мимо `ButtonPacker`, а story `-19` в AC прямо требовала «кнопки по 2–3 в ряд». Тест `testAttackerHittingForeignOpenWindowSeesNoOwnershipTrapButtons` проверяет только `assertNotEmpty`, поэтому нарушение зелёное.

> BLOCK-3, minor 3: мост «замок ↔ бесплатный тап» должен перечислять причины из источника кодов `checkPvPAllowed()`, а не из литерального списка в тесте: пятая причина, добавленная в оба места кода, но не в этот массив, разъедется молча — ровно тот класс, ради которого мост и писали.

## Files
- app/Controllers/Telegram/Commands/Actions/PVP/AttackPlayerAction.php
- tests/database/StandoffAttackGateTest.php

## Non-goals
- Не добавлять на экран кнопок, которые игроку откажут: чужие `standoffCheck_` / `standoffLeave_` там по-прежнему недопустимы (это и была находка major #5 второго прохода).
- Не менять поведение гейтов и порядок вызовов в `handle()` — это устоялось двумя волнами, трогаем только сборку клавиатуры и тест.
- Не трогать `PlayerDetectionService` и `PvPRestrictionService`: список причин из них ЧИТАЕТСЯ (Reflection'ом, как уже сделано в мосте), но не правится.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`AttackPlayerAction.php:857-861` — инлайновая клавиатура `sendForeignStandoffScreen()`;
`tests/database/StandoffAttackGateTest.php:610` `testLockButtonReasonsMatchCooldownExemptReasons` —
литеральный массив причин; `PvPRestrictionService::checkPvPAllowed()` — источник `reason_code`;
`ButtonPacker` — централизованная нормализация рядов, которой пользуются остальные экраны.

## Acceptance criteria
- [ ] Клавиатура экрана «цель под чужой тревогой» проходит через тот же нормализатор рядов, что и остальные экраны, и ни один её ряд не содержит единственной кнопки. Если для этого экрана честно нужна ровно одна кнопка — добавь вторую, ведущую туда, где игрок может что-то сделать, а не оставляй одиночку.
- [ ] Тест проверяет не `assertNotEmpty`, а форму рядов на ВЫХОДЕ нормализатора: нарушение правила «2–3 в ряд» обязано делать тест красным.
- [ ] Тест-мост берёт перечень `reason_code` из источника (`PvPRestrictionService`), а не из литерального массива: причина, добавленная в код и не добавленная в тест, больше не может разъехаться молча. Если источник не отдаёт перечень программно — скажи это в `## Findings` и объясни, чем закрыл.
- [ ] Мост по-прежнему падает в обе стороны: и когда причина есть в `lockLabel()`, но нет в `restrictionReasonHasLockButton()`, и наоборот.
- [ ] Прежние тесты файла остаются зелёными; ни один не ослаблен.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/StandoffAttackGateTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `sendForeignStandoffScreen()` теперь собирает клавиатуру через `ButtonPacker::pack()` (новый
  `use App\Services\Telegram\ButtonPacker;`). Добавлена вторая кнопка «◀️ Я» (`callback_data =>
  'character'`) — тот же запасной путь, что у `RunAwayAction` после побега; ряд стал `[Поход, Я]`.
- `restrictionReasonHasLockButton()` и `checkPvPAllowed()` не трогались (запрещено story).
- `StandoffAttackGateTest`: `flattenButtons()` теперь построен поверх нового `keyboardRows()`
  (ряды КАК ЕСТЬ, не расплющенные) — `testAttackerHittingForeignOpenWindowSeesNoOwnershipTrapButtons`
  проверяет `count($row) >= 2` на каждом ряду, а не только `assertNotEmpty`.
- `testLockButtonReasonsMatchCooldownExemptReasons` берёт реальные коды из нового
  `pvpAllowedReasonCodes()` — сканирует исходник `PvPRestrictionService::checkPvPAllowed()` regex'ом
  `'reason_code'\s*=>\s*'([a-z_]+)'` (тот же класс приёма, что `token_get_all` в
  `CallbackDataRoutingTest`/`CommunityGuardTest`), плюс литеральный контрольный
  `'some_future_reason_code'`, который не может появиться в источнике.

## Findings

- `PvPRestrictionService::checkPvPAllowed()` не отдаёт перечень `reason_code` программно (ни
  константы, ни enum, ни метода-перечисления) — коды рассыпаны по `return`-блокам как строковые
  литералы. Закрыл сканом исходника через `ReflectionClass::getFileName()` + regex (AC #3 прямо
  разрешает такой путь при отсутствии программного перечня). Сканирующий тест — источник правды
  «что реально возвращает код», а не «что задокументировано» (докблок над методом это же
  перечисление уже держит вручную и мог разойтись с кодом — теперь бридж-тест не зависит от него).
- Обе находки проверены на красноту раздельно (не вместе): (1) временно вернул клавиатуру экрана
  к одиночной `[[«🗺️ Поход»]]` — `testAttackerHittingForeignOpenWindowSeesNoOwnershipTrapButtons`
  упал на новой проверке рядов, затем восстановил; (2) временно убрал `'account_age'` из
  `restrictionReasonHasLockButton()` — бридж-тест упал в направлении «lockLabel знает, метод нет»,
  восстановил; затем временно добавил туда же `'map_missing'` — упал в обратном направлении
  («метод знает, lockLabel нет»), восстановил. Итоговый прогон файла — 21/21 зелёных.
