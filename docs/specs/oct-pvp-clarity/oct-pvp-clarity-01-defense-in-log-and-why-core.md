---
story: oct-pvp-clarity-01
spec: oct-pvp-clarity
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Профиль обороны в `log_data` + нейтральный разбор факторов боя в карточке

## Goal
Новый PvP-бой пишет в `log_data.defense` фактическую оборону защитника (срез стены, суммарный урон ограды,
бонус вышки, дрон). Нейтральный `PvpOutcomeExplainer` из `log_data` собирает строки «почему так вышло», а
`BattleJournalService::card()` отдаёт их полем `battle.why` — бот и `/play` берут готовое.

## Requirements
> 1. В лог PvP-боя пишется оборона защитника.
> 3. Баланс не меняется: стена режет и удар насмерть.
> Сначала разобраться с B2: так задумано или это баг.

## Files
- app/Services/PVE/PvpRoundOrchestrator.php
- app/Services/PVE/PvpBattleLogBuilder.php
- app/Services/PVE/PvpOutcomeExplainer.php
- app/Services/PVE/BattleJournalService.php
- tests/database/PvpBattleLogBuilderTest.php
- tests/database/BattleJournalServiceTest.php
- tests/unit/Services/PVE/PvpOutcomeExplainerTest.php

## Non-goals
- Не менять ни одной формулы и ни одного числа боя: `PvpDamageCalculator`, `PvpFormulaService`,
  `DefenseStructureService`, `Config\GameBalance` не трогать. Оркестратор только **сообщает** в результате
  то, что уже применил (срез стены, урон ограды по факту).
- Не переносить потолки в `GameSettings` — отдельная спека.
- Не трогать рендеры (story 02) и публичный `/battles/view`.
- Не писать оборону в дуэли и PvE.

## Map slice
`memory/map/pve-pvp.md` → Entry points (PvP, Лог), «Журнал и арена», Gotchas «Журнал боёв».

## Acceptance criteria
- [ ] Новый PvP-бой с защитником на базе пишет `log_data.defense` по контракту plan.md; бой без обороны — поле с нулями и `drone: false`.
- [ ] `PvpOutcomeExplainer` на фикстуре боя 75134 (раунды с прода из brief.md, сокращённо) даёт строки: срез стены 40 %, урон ограды, 1 удачный удар слабого, 2 смягчённых удара насмерть, пояснение потолков Силы и уровней.
- [ ] Соседняя форма: старый бой без `defense` — нет строк про стену и ограду, остальные строки есть; битый/пустой `log_data` — пустой `why`, без исключения.
- [ ] Дуэль (`DUEL`) и PvE — `why` пустой.
- [ ] Строки без Markdown-разметки и без чисел баланса, вписанных в код: проценты и потолки — из лога и `Config\GameBalance`.
- [ ] Регрессия: существующие тесты оркестратора и билдера лога зелёные, исход боёв на фиксированном `mt_rand` не изменился.
- [ ] Tech-writing: ноты `PvpRoundOrchestrator`, `PvpBattleLogBuilder`, `BattleJournalService` обновлены, новая нота `PvpOutcomeExplainer`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
