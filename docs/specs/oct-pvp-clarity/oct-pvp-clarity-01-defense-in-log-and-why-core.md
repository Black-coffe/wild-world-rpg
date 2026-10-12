---
story: oct-pvp-clarity-01
spec: oct-pvp-clarity
status: done
returned: DONE
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

- **B2 — задумано, не баг.** Оркестратор только сообщает применённое: `simulateFight` возвращает `defense`
  (`owner_id`, срез стены, ограда по факту — сколько HP реально снято, вышка+дрон). Формулы, `GameBalance`,
  `DefenseStructureService` не тронуты; `AttackPlayerActionFixtureFenceTest` и тесты оркестратора зелёные,
  порядок `mt_rand` прежний.
- **`log_data.defense` пишется только при профиле обороны**, а не «нули в каждом бою»: билдер не отличает PvP вне
  базы от дуэли (обе зовут оркестратор с `defense = null`), различить можно только правкой
  `AttackPlayerAction`/`ArenaScreenService` вне Files. Для разбора «нули» и «нет поля» одинаковы — строк про
  оборону нет. В контракт добавлен `owner_id`: при контратаке хозяин базы стоит в слоте атакующего (plan.md → Plan deltas).
- `drone` — DB-чтение `DefenseStructureService::combatDroneActiveBonusPercent` сразу после боя, через
  инжектируемый `\Closure` в конструкторе билдера (ошибка → `false`).
- **`PvpOutcomeExplainer::explain($type, $log, $forParticipant = true)`** — строки без разметки; потолки Силы
  из `PvpFormulaService::computeStatsBonus` (точка насыщения / `statsBonusFactor`) и `GameBalance::levelDiffCap`,
  ×1.5 — `luckyStrikeDamageMult`; своих чисел в коде нет (тест подменяет `GameBalance` и видит новые числа).
- **Поправка владельца на сборке** (публичная `/battles/view`): режим `$forParticipant = false` — без чисел
  стены, ограды, инициативы, одна строка «бой шёл у базы X». Story 02 расширена, ask 8 в брифе, цитата — в
  `docs/defects/entry-off-audience-path.md`.
- `BattleJournalService::card()` → `battle.why`; `listFor` его не несёт.
- Тесты: `PvpOutcomeExplainerTest` (8: бой 75134, числа из GameBalance, старый бой, посторонний, контратака,
  дуэль/PvE/битый, ровный бой, без разметки); `PvpBattleLogBuilderTest` +3 (настоящий бой оркестратора у базы);
  `BattleJournalServiceTest` +2.
- Tech-writing: `PvpBattleLogBuilder`, `BattleJournalService` обновлены; `PvpRoundOrchestrator` (ноты не было) и
  `PvpOutcomeExplainer` созданы; `services/index.md`.

## Findings
