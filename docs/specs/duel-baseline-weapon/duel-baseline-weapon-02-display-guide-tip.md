---
story: duel-baseline-weapon-02
spec: duel-baseline-weapon
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [duel-baseline-weapon-01]
---

# Разбор боя без «−0», фраза в гайде арены, совет дня про базовое оружие

## Goal
Разбор боя в боте (`BattleJournalAction::num()`) и в `/play` (`native_battle.php`, `$num`) показывает урон и
остаток HP меньше 1 двумя знаками («−0.01», «−0.4»), а не «−0». Остальные значения форматируются как раньше. Гайд
«🏟 Арена и PvP» одной фразой без чисел объясняет базовое оружие на арене. Новый совет дня говорит о том же.

## Requirements
> форматтер «−0» → «−0.01» в обоих клиентах (BattleJournalAction::num() и native_battle.php $num)
> 6. В разборе боя урон меньше 1 не показывается как «−0» — ни в боте, ни в /play.
> 7. Гайд «🏟 Арена и PvP» одной фразой говорит, что без оружия на арене бьёшь базовым, а своё сильнее даёт перевес; совет дня — про то же. Экран арены не меняется.

## Files
- app/Controllers/Telegram/Commands/Actions/PVP/BattleJournalAction.php
- app/Views/site/_play/native_battle.php
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-18-100010_SeedDuelBaselineWeaponTip.php
- tests/database/BattleJournalServiceTest.php
- tests/unit/Views/PlayViewsTest.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php

## Non-goals
- Не выносить два форматтера в общий хелпер: оба меняются точечно, их паритет держит тест.
- Не трогать экран арены (`ArenaAction`, `native_arena.php`), его тексты и снимок паритета.
- Не писать чисел баланса в гайд и совет (ни 10, ни 40 %, ни 200 HP): они меняются из админки.
- Не переписывать раздел гайда целиком: добавляется одна фраза к пункту про арену.

## Map slice
`memory/map/pve-pvp.md` — `## Gotchas` (журнал боёв); `memory/map/onboarding.md` — `/guide`, «Совет дня».

## Acceptance criteria
- [ ] Бот и `/play` для 0.01 / 0.04 / 0.4 / 2.36 / 12.6 показывают «0.01» / «0.04» / «0.4» / «2.4» / «13» (одинаково в обоих
      клиентах, тест на каждый). Ноль остаётся «промах».
- [ ] Раздел `arena` в `GuideCatalog` содержит фразу про базовое оружие на арене и перевес своего. Markdown-safe, без
      чисел баланса, ключ раздела не меняется, `GuideCatalogTest` зелёный.
- [ ] Seed-миграция совета идемпотентна по `title_en`, категория — одна из ENUM `game_tips`, тон Роби, без чисел
      баланса, не дублирует совет `SeedBattleJournalTip`. Повторный запуск не дублирует, `down()` удаляет.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Форматтеры: `BattleJournalAction::num()` (бот) и `$num` в `native_battle.php` (веб) — одна и та же ветка
  `0 < v < 1` → два знака, `max(v, 0.01)` (крохотный урон не схлопывается в «0»); ≥ 10 и 1–10 — как раньше. Паритет —
  одинаковый набор значений в `BattleJournalServiceTest::testBotCardShowsDamageBelowOneNotAsZero` и
  `PlayViewsTest::testWebBattleCardShowsDamageBelowOneLikeTheBot` (0.01/0.04/0.4/2.36/12.6, 999.99 → «1000», 0 → «промах»).
- Гайд `arena`: одна фраза после «решают билд и удача» — «Без оружия на арене бьёшь базовым, а своё, если оно
  сильнее, даёт перевес — но не гарантию победы». Тест: фраза есть, цифр в разделе нет.
- Совет `ArenaBaselineWeapon` (категория «бой»): с соседним `PvpDuels` (летальное поле, «не лезь безоружным») не
  спорит — тот про поле, этот про арену; путь «⚙️ Ещё → 🏟 Арена» и «⚔️ Бои» сверен с `MoreSurfaceService`/доком.
  Локально: migrate → 1 строка, rollback → 0, migrate → 1 (байты `tip_type` = «бой» как у `BattleJournal`).

## Findings
