---
story: duel-first-strike-coin-01
spec: duel-first-strike-coin
status: done
returned: DONE
tier: 1
worker: queen
model: opus
wave: 1
blocked_by: []
---

# Монетка первого удара в DuelService::simulate()

## Goal
`DuelService::simulate()` разыгрывает первый удар жребием: при решке бойцы идут в `simulateFight` в обратном
порядке. Монетка — `Random\Randomizer`, она подменяется через конструктор. Тест и проверка дефекта держат долю побед
вызывающего в 47–53 %.

## Requirements
> Если у бойцов равные статы, первым всегда бьёт тот, кто вызвал на дуэль. Тест допускает до 60 % побед вызывающего. Как ты считаеш это норм или нет?
> 1. Первый удар на дуэли разыгрывается жребием, а не отдаётся вызывающему: при равных статах доля побед вызывающего — 47–53 % на 4000 боях (тест на настоящем движке), а не ~54 %.
> 2. Жребий не трогает поток `mt_rand` боя (RNG-fence ADR-070) и подменяется в тестах; полевой PvP не меняется.
> 3. Роли в журнале боёв и разборе не меняются: вызывающий остаётся вызывающим, кто бы ни ударил первым.
> 4. Карточка дефекта `duel-outcome-not-decided-by-fight` получает фикстуру «жребий всегда за вызывающим», и `check:` на ней падает; `bash scripts/defects-check.sh` зелёный.

## Files
- app/Services/PVE/DuelService.php
- tests/database/DuelOutcomeTest.php
- scripts/defects-duel-knockout-check.php
- docs/defects/duel-outcome-not-decided-by-fight.md
- docs/defects/fixtures/duel-outcome-not-decided-by-fight-first-strike.json
- app/Config/Services.php
- tests/database/ArenaBotParityTest.php

## Non-goals
- Не трогать `PvpRoundOrchestrator`, `determineInitiative` и полевой PvP.
- Не выносить жребий в GameSettings: 50/50 — определение честности, а не баланс.

## Map slice
`memory/map/pve-pvp.md` — дуэль, `DuelService`.

## Acceptance criteria
- [ ] При равных статах доля побед вызывающего на 4000 боях — в 47–53 % (`DuelOutcomeTest`).
- [ ] Монетка всегда за вызывающим (фикстура) — `check:` падает; без фикстуры и на двух старых фикстурах — как раньше.
- [ ] `simulate()` не вызывает `mt_rand` для жребия; полевой `simulateFight` не тронут.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `DuelService::__construct(?GameSettingsService, ?\Random\Randomizer $coin)`: по умолчанию `new Randomizer()` (Secure engine) — поток `mt_rand` боя не тронут; `simulate()` при `getInt(0,1) === 1` подаёт защитника первым аргументом `simulateFight`. При равной инициативе `determineInitiative` (`$i1 >= $i2`) отдаёт удар первому аргументу.
- Журнал: `PvpBattleLogBuilder::build($eqAttacker, $eqDefender, …)` в `ArenaScreenService` строит роли из явных аргументов, не из порядка ударов — роли не меняются; в разборе по раундам первый удар честно показывает того, на кого выпал жребий.
- Замер (seed-ы боя 5000+i, монетка `Mt19937(7)`, 4000 равных дуэлей): с жребием вызывающий выигрывает **51,1 %**, монетка «всегда вызывающий» и код до фикса — **53,9 %**.
- `DuelOutcomeTest::testFirstStrikeIsACoinTossNotTheChallengersRight` — окно 47–53 % + обратная форма > 53 %; остальные серии идут на детерминированной монетке. Окно 40–60 % из старого теста убрано.
- Проверка дефекта меряет долю побед вызывающего на 4000 боях; новая фикстура `…-first-strike.json` (`"first_strike": "challenger"`) падает, `defects-check` GREEN.
- Полный набор (прогон 1) поймал `ArenaBotParityTest`: снимок итога дуэли снят до жребия, а `DuelAction` сам создаёт `ArenaScreenService`/`DuelService` — монетку не подать конструктором. Монетка по умолчанию — `Services::duelCoin()` (shared, криптостойкий движок); тест паритета подменяет её `injectMock` монеткой «за вызывающим». `## Files` дополнен `app/Config/Services.php` и `tests/database/ArenaBotParityTest.php`.
