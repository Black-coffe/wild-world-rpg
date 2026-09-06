---
story: storage-craft-insurance-02
spec: storage-craft-insurance
status: done
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Страховка крафта перестаёт врать, что предметов нет

## Goal
Игрок, застраховавший всё, что можно, видит список своих действующих полисов вместо фразы «у тебя нет
дорогих предметов под страховку». Типы застрахуемого называются по-русски, а не машинными токенами
`robots, workbench, transport`. Проверено на проде: полисы на месте (18 оплаченных строк), а
ложный пустой экран видят 5 из 31 игрока с застрахуемыми предметами — то есть каждый, кто довёл
механику до конца.

## Requirements
> [19.08.2026 04:18] Анжела: Была оплачена крафт страховка двух верстаков, после обновления с лекарствами пишет, что страховок нет, ибо отсутствуют непонятные английские слова 😂

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/Insurance/CraftInsuranceListAction.php
- app/Services/Player/CraftInsuranceService.php
- tests/unit/Player/CraftInsuranceServiceTest.php

## Non-goals
- НЕ менять значение `craft_insurance.eligible_types` и НЕ добавлять туда `drones` — это правка
  баланса через админку, решение владельца, вопрос ему задан отдельно.
- НЕ трогать `CraftInsureItemAction` (покупка полиса работает — деньги списываются, `insured=1`
  проставляется) и не трогать `LootProcessor`.
- НЕ заводить отдельный экран/новый callback под список полисов: он рисуется на том же экране,
  над списком доступного к страхованию. Лишний тап — это ровно та невидимость, из-за которой
  игрок и решил, что полисов нет.

## Map slice
`memory/map/craft.md` (страховка, `CraftInsuranceService`), `memory/map/telegram.md` (action-handler'ы).

## Acceptance criteria
- [ ] На экране «🛡 Страховка крафта» действующие полисы перечислены поимённо (`crafted_items.name_rus`)
      с количеством — до списка того, что ещё можно застраховать.
- [ ] Когда застраховано всё, что подходит, текст говорит именно это, а не «у тебя нет дорогих предметов».
- [ ] Когда подходящих предметов нет вообще, текст называет типы по-русски
      («роботы, верстаки, транспорт»), ни один машинный токен в player-facing текст не попадает.
- [ ] Перевод токена, которого нет в словаре, деградирует в сам токен, а не в пустоту — новый тип
      в GameSettings не должен ронять экран или показывать «, , ».
- [ ] Текст самодостаточен без картинки (MEDIA-OFF), парные `*` (Markdown не ломается).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/CraftInsuranceServiceTest.php`

## Implementation notes

- `CraftInsuranceService`: добавлен `TYPE_LABELS` (все 18 значений `crafted_items.type` с прода) +
  `typeLabel(string): string` (unknown → сам токен, не пустота) + `typeLabels(list<string>): string`
  (comma-join). `eligibleTypes()`/остальное не тронуто — не в scope.
- `CraftInsuranceListAction`: добавлен второй SELECT (`insured=1, quantity>0`, без фильтра по
  `eligibleTypes()` — уже купленный полис остаётся видимым, даже если admin позже уберёт тип из
  `craft_insurance.eligible_types`), отрисовка блока «📜 Действующие полисы» через новый приватный
  `renderPolicies()` (лимит 15 строк + «…и ещё N», защита от тихого обрезания caption >1024).
  Три состояния текста вместо одного: есть что страховать / всё подходящее уже застраховано /
  подходящих предметов нет вообще (типы — по-русски через `typeLabels()`).
- Заодно: имена предметов в обоих блоках (список eligible и список полисов) пропущены через
  `App\Services\Display\MarkdownSafe::name()` — раньше `*{$name}*` в eligible-списке не экранировался
  вообще, непарная `*`/`_` в `name_rus` валила бы всё сообщение по тому же классу бага, который чинит
  эта история. В eligible-списке машинный `($type)` тоже заменён на `typeLabel($type)`.
- Verification: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/CraftInsuranceServiceTest.php`
  → 6 tests, 45 assertions, зелёный. `phpstan analyse --memory-limit=512M --no-progress` на оба
  файла → No errors (пришлось ослабить `@param` `renderPolicies()` с `list<array<string,mixed>>` до
  `list<mixed>` — `findAll()` возвращает объединение array/object, узкий тип не проходил).
- Tier-3 (живой Telegram) не запускался в рамках этой задачи — не было доступа к MCP Chrome/тест-чару
  в этой сессии; логика проверена только Tier-1 (phpunit/phpstan). Рекомендую Tier-3 smoke перед
  релизом: чар с оплаченным полисом и `insured=1` на верстаке должен увидеть блок «📜 Действующие
  полисы», а не старое враньё.

## Findings
