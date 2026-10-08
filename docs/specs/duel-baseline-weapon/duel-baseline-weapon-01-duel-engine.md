---
story: duel-baseline-weapon-01
spec: duel-baseline-weapon
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Ядро дуэли: одна площадка, базовое оружие с весом перевеса, уворот, HP 200

## Goal
`ArenaScreenService::challenge()` проводит дуэль без дистанции: оба бойца стоят на клетке вызывающего. Урон
считается через `DuelEquipmentRepository`: без оружия или со слабым оружием боец бьёт базовым, а своё оружие сильнее
бьёт `база + вес × (своё − база)`. Уравнивание ставит обоим ловкость под настроенный уворот и HP дуэли. Четыре числа
лежат в GameSettings с полным набором полей. Дуэль двух бойцов без оружия на умолчаниях кончается нокаутом.
У карточки дефекта появляется настоящая проверка.

## Requirements
> На preprod дуэль двух новичков без снаряжения шла 150 раундов по 0,01 урона, и победителя выбрал стаж, а не бой. Это точно не то что игшроки ждут в поедимнках
> Фикс дуэли — «Базовое оружие на дуэли» (выбор владельца): арена остаётся открытой новичкам (ADR-124 — главный вход в PvP), билд по-прежнему решает (ADR-071).
> правка ADR-071; карточка дефекта docs/defects/duel-outcome-not-decided-by-fight.md получает check: + две фикстуры.
> 1. В дуэли (с арены и в поле) расстояние между клетками бойцов не режет урон: оба дерутся как на одной площадке.
> 2. Без оружия или со слабым боец бьёт базовым оружием (настройка в админке, по умолчанию 10), своё сильнее даёт перевес, но разница с базовым считается вполовину (настройка в админке, по умолчанию 0,5). Обычный PvP и PvE не меняются.
> 3. На арене уворачиваются чаще: шанс уворота в дуэли — настройка в админке, по умолчанию 40 %.
> 4. HP дуэли по умолчанию 200 вместо 1000; где значение не меняли руками, оно становится 200. Каждая настройка дуэли (новые и HP) несёт в админке объяснение, «что если выше/ниже», границы и сброс к умолчанию.
> 5. Дуэль двух бойцов без оружия на настройках по умолчанию кончается нокаутом до 150 раундов; стаж решает, только если нокаута нет. Небольшой перевес не гарантирует победу: как в выбранном варианте, +10 % выигрывает около 57 % боёв, двойной — около 94 %, Rare — около 99 %. Проверяет тест на настоящем движке боя (оба без оружия; оружие только у одного) и проверка в карточке дефекта.

## Files
- app/Services/PVE/DuelService.php
- app/Services/PVE/DuelEquipmentRepository.php
- app/Services/PVE/PvpEquipmentRepository.php
- app/Services/PVE/ArenaScreenService.php
- app/Database/Migrations/2026-12-18-100000_DuelBaselineWeaponSettings.php
- tests/database/DuelServiceTest.php
- tests/database/DuelOutcomeTest.php
- tests/database/ArenaScreenServiceTest.php
- tests/database/ArenaBotParityTest.php
- scripts/defects-duel-knockout-check.php
- docs/defects/duel-outcome-not-decided-by-fight.md
- docs/defects/fixtures/duel-outcome-not-decided-by-fight-original.json
- docs/defects/fixtures/duel-outcome-not-decided-by-fight-neighbour.json

## Non-goals
- Не трогать `PvpRoundOrchestrator`, `PvpDamageCalculator`, `PvpFormulaService` и `Config\GameBalance`: обычный PvP и
  PvE обязаны остаться байт-идентичными (ADR-070, fixture-fence). Дуэль меняет только вход движка.
- Не добавлять в движок новых `mt_rand`: разброс даёт уворот через ловкость, а не новый бросок.
- Не менять тай-брейк ADR-073 (HP → билд → стаж): он остаётся крайним случаем.
- Не менять тексты арены, рейтинг, кулдаун, двойной тап и запись журнала — это хвосты W2.N7 вне спеки.
- Не пересчитывать старые строки `DUEL`.

## Map slice
`memory/map/pve-pvp.md` — `## Entry points`, `## Gotchas` (журнал и арена, дуэль = строка `DUEL`).

## Acceptance criteria
- [ ] В дуэли бойцов с реальных клеток в 457 клетках друг от друга урон удара тот же, что на одной клетке: множитель
      дистанции 1.0.
- [ ] Боец без оружия и боец с оружием `damage_value × K_rar` ≤ базового бьют базовым (`D_equip` = база при
      F_type = 1). Своё сильнее бьёт `база + вес × (своё − база)`, тип урона и крит сохраняются.
- [ ] `getDodgeChance()` от уравненного бойца равен `pvp.duel.dodge_percent` (тест-мост), HP обоих =
      `pvp.duel.baseline_health`.
- [ ] Миграция сеет `pvp.duel.baseline_weapon_damage` (10), `pvp.duel.weapon_advantage_weight` (0.5),
      `pvp.duel.dodge_percent` (40), категория `combat`. У каждой есть rationale, effect, above, below, мягкие и жёсткие
      границы, `default_value_text`. `pvp.duel.baseline_health` получает умолчание 200, новые тексты и мягкий минимум,
      в который 200 входит. Значение 1000 → 200 меняется, только если его не правили руками. Повторный запуск ничего
      не дублирует, `down()` откатывает.
- [ ] `DuelOutcomeTest` на настоящих `PvpRoundOrchestrator` и `PvpDamageCalculator` со стаб-репозиторием, ≥ 500
      сидов. Оба без оружия на умолчаниях — нокаут в 100 % боёв, медиана раундов < 150, исход `seniority` не
      встречается. Оружие только у одного (Rare 24) — нокаут в 100 %, вооружённый побеждает ≥ 95 %. Своё оружие 11 против базового 10
      (+10 % до веса) побеждает в 50–65 % боёв, своё 20 (×2 до веса) — в 88–98 %. Равные бойцы — доля побед
      вызывающего 40–60 %.
- [ ] Обычный PvP не изменился: существующие PvP-тесты и снимки зелёные без правок.
- [ ] Карточка дефекта: `status: block`, `check: php scripts/defects-duel-knockout-check.php <arg>`, две фикстуры
      (original — оба без оружия, дистанция 457, настройки до фикса; neighbour — оружие только у одного, та же
      дистанция). Проверка гоняет настоящий движок, а не копию формулы. Она падает на обеих фикстурах и проходит без
      аргумента на текущих умолчаниях. `bash scripts/defects-check.sh` зелёный.
- [ ] Ветка phpstan L9 чистая.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `DuelService`: `prepare($a, $b)` — пара уравнена и стоит на клетке вызывающего; `simulate($eqA, $eqB, $biome, $repo)` —
  неизменный `simulateFight` на `PvpDamageCalculator` поверх `DuelEquipmentRepository`. Этим путём ходят арена
  (`ArenaScreenService::challenge/realFight`), `DuelOutcomeTest` и проверка дефекта — один код, не копия.
  `equalize($char, ?int $cell)`: ловкость = `dodge_percent / 0.25`, HP по умолчанию 200; три новых геттера с зажимом
  (вес 0..1, уворот 0..75, урон ≥ 1), умолчания — `DEFAULT_*`.
- `DuelEquipmentRepository extends PvpEquipmentRepository` (у родителя снят `final`): декоратор, родительский
  конструктор не вызывается, поэтому переопределены все 4 публичных метода — держит рефлексивный тест.
  `duelWeapon()` — чистая статика: слабее базового (урон × редкость) → базовое Common/Physical/крит 0; сильнее →
  `база + вес × (своё − база)`, тип и крит своего оружия, редкость Common (уже в уроне).
- Миграция `2026-12-18-100000`: 3 ключа `float` (`combat`), `baseline_health` — тексты, умолчание 200, мягкие 100–1000,
  значение 1000 → 200 только при `updated_by IS NULL` (testbot и прод — NULL, проверено SELECT); `baseline_stat` —
  тексты без ловкости. `down()` откатывает всё.
- Замер на настоящем движке (600 сидов, оружие поровну у вызывающего и защитника): безоружные — 100 % нокаут,
  медиана ~46 раундов, стаж 0; доли побед — в границах story. Снимок `ArenaBotParityTest` («150, по очкам» →
  «42, нокаут») обновлён — это и есть фикс.
- Проверка дефекта: `scripts/defects-duel-knockout-check.php` (CI4 test-bootstrap, кэш `dummy`, настройки из
  фикстуры через заглушку модели). Умолчания — OK 200/200; обе фикстуры (настройки до фикса) — FAIL, 0 нокаутов.
  `defects-check.sh` — GREEN: 2 blocking checks.

## Findings
