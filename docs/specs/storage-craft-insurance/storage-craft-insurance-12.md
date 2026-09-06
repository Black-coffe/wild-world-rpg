---
story: storage-craft-insurance-12
spec: storage-craft-insurance
status: done
tier: 2
worker: worker-test
tracer: false
wave: 4
blocked_by: [storage-craft-insurance-02, storage-craft-insurance-07]
---

# Починка страховки и текст «Пути новичка» — под тестом, а не на слово

## Goal
Баг из жалобы («нет дорогих предметов» тому, кто застраховал всё) живёт в ветвлении по списку
полисов, а единственный новый тест страховки проверяет словарь типов. То есть починка самой
жалобы не закреплена ничем. Новый текст `/guide` тоже не покрыт ни одним утверждением, хотя
файл теста назван в story 07. Плюс в докблоке экрана записан выдуманный факт про лимит 1024,
которого у текстового сообщения нет.

## Requirements
> [19.08.2026 04:18] Анжела: Была оплачена крафт страховка двух верстаков, после обновления с лекарствами пишет, что страховок нет, ибо отсутствуют непонятные английские слова 😂

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/Insurance/CraftInsuranceListAction.php
- tests/unit/Player/CraftInsuranceServiceTest.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php

## Non-goals
- НЕ менять значение `craft_insurance.eligible_types` и НЕ добавлять `drones`.
- НЕ переписывать экран: правки в нём — только докблок и, если потребуется, выделение
  формирования текста в тестируемый вид.
- НЕ трогать покупку полиса.

## Map slice
`memory/map/craft.md`, `memory/map/onboarding.md`.

## Acceptance criteria
- [ ] Тест доказывает три состояния текста: есть что страховать / всё уже застраховано /
      подходящих предметов нет. Второе — это и есть жалоба игрока.
- [ ] Тест доказывает, что действующие полисы перечисляются поимённо.
- [ ] Новый раздел `/guide` покрыт утверждением (существует, read-only, парная разметка).
- [ ] Докблок про лимит приведён в соответствие с реальностью: это текстовое сообщение, у него
      другой предел, и Telegram не обрезает молча, а отвечает отказом.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/CraftInsuranceServiceTest.php tests/unit/Services/Onboarding/GuideCatalogTest.php`

## Implementation notes

- `CraftInsuranceListAction`: вынес сборку текста/кнопок экрана из `handle()` в чистый
  `public static function renderScreen(array $rows, array $policyRows, array $types,
  CraftInsuranceService $insurance): array` — без DB/Telegram, поэтому вызывается напрямую
  из юнит-теста. `handle()` теперь просто передаёт результаты двух SELECT'ов в
  `renderScreen()` и шлёт то, что получилось; поведение не изменено (диф — построчный
  перенос старого кода, не переписывание логики). `renderPolicies()` стал `private static`
  для этого же вызова.
- Докблок `renderPolicies()` переписан: экран уходит текстовым сообщением через
  `MediaSender::editTextOrSend` (лимит Telegram 4096, не photo-caption 1024), и при
  превышении длины Telegram отвечает отказом (`ok=false`), а не обрезает молча. Лимит
  15 строк оставлен как есть — это читаемость экрана, не защита от API-предела.
- `tests/unit/Player/CraftInsuranceServiceTest.php`: добавил 4 теста на
  `CraftInsuranceListAction::renderScreen()` — три состояния текста (всё застраховано /
  нечего страховать вообще / есть что страховать) и поимённое перечисление действующих
  полисов с количеством. **Как проверил, что тесты красные без починки:** временно вернул
  тело `renderScreen()` к логике из `506aaf93~1` (единственная ветка «нет дорогих предметов»,
  без блока полисов, без разбора `policyRows`) прямо в файле, прогнал
  `tests/unit/Player/CraftInsuranceServiceTest.php` — 3 из 4 новых теста упали каждый на
  своём отдельном assert (не на фатальной ошибке): `testAllInsuredStateSaysEverythingIsInsuredNotThatThereIsNothing`
  (жалоба Анжелы буквально), `testNothingEligibleAtAllStateListsTypesInRussian`,
  `testActivePoliciesAreListedByNameAndQuantity`; `testEligibleItemsStateListsItemsWithButtons`
  (не про регрессию) остался зелёным, подтверждая, что тесты специфичны, а не ломаются от
  чего попало. Восстановил рабочую версию файла — 10/10 зелёных, диф идентичен исходной правке.
- Уточнение от team-lead: AC3 про `/guide` относится не к новому разделу про страховку
  (такого нет и заводить не нужно), а к разделу `storage`, который **story 07**
  (`2ff1464a`) расширила абзацами про единый пул рюкзак+склад для крафта/ремонта/апгрейда
  построек и про забор со склада по одному виду ресурса — этот текст не был покрыт ни
  одним утверждением, хотя `GuideCatalogTest.php` был в `## Files` story 07.
  `tests/unit/Services/Onboarding/GuideCatalogTest.php`: добавил
  `testStorageSectionExplainsUnifiedPoolAtBase` и `testStorageSectionExplainsWithdrawByType`
  — проверяют смысл по устойчивым маркерам (наличие «рюкзак»/«склад»/«крафт»/«ремонт»/
  «апгрейд» + один из маркеров «общ»/«един» для пула; «забрать» + один из маркеров
  «по одному»/«каждым вид»/«только его»/«не трогая» для забора по виду), а не точную фразу
  — по просьбе team-lead, текст ещё будут редактировать. Read-only и парность `*`/`_` для
  этого раздела уже покрыты существующими сквозными тестами
  (`testGuideFilesContainNoGrantOrMutationCalls`, `testRenderedTextHasBalancedMarkdownEntities`),
  дублировать не стал. **Проверка красноты:** временно подменил файл `GuideCatalog.php` на
  версию `2ff1464a~1` (до story 07), прогнал оба новых теста — оба упали (первый на
  отсутствии «ремонт», второй — ни один из маркеров забора по виду не найден). Восстановил
  текущую версию файла из копии — диф `git diff app/Services/Onboarding/GuideCatalog.php`
  пуст, файл не модифицирован (не входил в `## Files` story 12).
- Verification: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/CraftInsuranceServiceTest.php tests/unit/Services/Onboarding/GuideCatalogTest.php`
  → 27 tests, 526 assertions, зелёный. `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
  на `CraftInsuranceListAction.php` → No errors.

## Findings
