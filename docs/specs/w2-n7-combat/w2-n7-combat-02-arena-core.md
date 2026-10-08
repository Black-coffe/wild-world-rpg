---
story: w2-n7-combat-02
spec: w2-n7-combat
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [w2-n7-combat-01]
---

# Ядро арены: ростер, вызов, рейтинг, тумблер дуэлей; бот — рендерер

## Goal
Есть `App\Services\PVE\ArenaScreenService`: модель арены (ростер открытых к дуэлям, мой статус, замок при выключенных
дуэлях), операция вызова (все гейты `DuelAction`, атомарный анти-спам кулдаун, бой, исход, очки рейтинга, запись
`DUEL` в `battle_logs` и текст защитнику), модель рейтинга PvP (вкладки «глобальный / моя фракция», замок при
выключенном рейтинге) и тумблер «открыт к дуэлям». `ArenaAction`, `DuelAction`, `PvpLadderAction` и ветка
`duelsOpenOn/Off` в `SettingsAction` рисуют из ядра то же, что сейчас; на арене и в рейтинге есть вход «📜 Мои бои»,
под итогом дуэли — «📜 Разбор боя».

## Requirements
> 2. Арена в /play нативно: кто открыт к дуэлям, вызов, итог с разбором, «открыться к дуэлям» и рейтинг PvP. Атака игрока на карте, встреча с NPC и Узлы пока идут мостом (N7b).
> 3. Дуэль пишется в журнал обоим с пометкой «дуэль, без потерь». Повтор формы или двойной тап не проводит вторую дуэль.
> 4. В боте есть «📜 Мои бои» из той же модели, сжато в лимит Telegram. Под итогом каждого боя есть кнопка «📜 Разбор боя», а на арене и в рейтинге — вход в журнал. Арену, дуэль и рейтинг бот рисует из тех же моделей, их тексты и кнопки не меняются (паритет проверяется тестом).
> 5. Всё читается без картинок. Если дуэли или рейтинг выключены, виден замок с объяснением. Вердикты: в /guide — да, строка про журнал в разделе «⚔️ Бой и PvE»; совет — да, про разбор боя.
> По выбранному варианту в журнал боёв «📜 Мои бои» можно войти только с арены и рейтинга PvP, а игроки, у которых есть PvE-бои, туда почти не заходят.

## Files
- app/Services/PVE/ArenaScreenService.php
- app/Services/PVE/DuelService.php
- app/Controllers/Telegram/Commands/Actions/PVP/ArenaAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/DuelAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/PvpLadderAction.php
- app/Controllers/Telegram/Commands/Actions/SettingsAction.php
- app/Services/More/MoreSurfaceService.php
- app/Services/PVE/BattleJournalService.php
- tests/database/ArenaScreenServiceTest.php
- tests/database/ArenaBotParityTest.php
- tests/database/ArenaRosterTest.php
- tests/database/DuelServiceTest.php
- tests/database/PvpLadderServiceTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать полевой `duel_<id>` сверх того, что он идёт тем же ядром (смежность клеток для него остаётся).
- Не менять формулы боя, `equalize`, `resolveDuel`, начисление очков рейтинга и значение кулдауна.
- Не трогать `AttackPlayerAction`, окно противостояния, `PvpLadderWeeklyBroadcastHandler`.
- Не менять тексты экрана настроек — только источник записи `duels_open`.

## Map slice
`memory/map/pve-pvp.md` — Entry points (PvP), «Анти-спам-кулдаун платят не все тапы» (Окно противостояния);
`memory/map/telegram.md` — маршрутизация колбэков.

## Acceptance criteria
- [ ] Снимки паритета: текст и кнопки арены, итога дуэли и рейтинга до и после переноса совпадают, кроме добавленных «📜 Мои бои» (арена, рейтинг) и «📜 Разбор боя» (итог дуэли).
- [ ] Вызов пишет одну строку `battle_logs` `DUEL` (вызвавший, соперник, победитель, раунды, пометка дуэли); здоровье, опыт и ресурсы обоих не меняются; оба видят бой в `BattleJournalService`.
- [ ] Два вызова подряд и два одновременных (два процесса/соединения) проводят одну дуэль: одна строка `DUEL`, одно начисление рейтинга, второй получает «подожди N сек.».
- [ ] При `pvp.duel.enabled=false` модель арены — замок с объяснением, вызов — отказ без записи; при `pvp.ladder.enabled=false` — замок рейтинга.
- [ ] Хаб «⚙️ Ещё» несёт «📜 Мои бои» (`battles`) всегда, рядом с «🏟 Арена»; ряды кнопок без одиночек.
- [ ] `duelsOpenOn/Off` в боте и тумблер ядра пишут `duels_open` одним методом.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
