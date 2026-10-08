---
story: w2-n7-combat-02
spec: w2-n7-combat
status: done
returned: DONE
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
- app/Controllers/BattlesController.php
- tests/database/BattlesPublicViewTest.php
- docs/defects/record-exposed-beyond-participants.md

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
- [ ] Поправка владельца: строка `DUEL` не хранит координат бойцов; `/battles/view/{id}` отдаёт только `PVP` — дуэль, PvE и несуществующий номер дают одинаковый 404 без имён (`BattlesPublicViewTest` падает на старом контроллере).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- Ядро `ArenaScreenService`: `arena()` / `roster()` / `challenge()` / `ladder()` / `setDuelsOpen()`, плюс чистые
  `resultText()` (имена экранируются под HTML — раньше `<`/`&` в имени ломали parse_mode) и `reasonLabel()` для веба.
  Замки выключенных разделов — `LOCK_ARENA` / `LOCK_LADDER` в модели; бот оставил свои прежние тексты замков (паритет).
- Атомарность: проверка и взятие кулдауна и сам бой — под `GET_LOCK('ww-duel-<db>-<attackerId>', 3)`. Второй
  одновременный вызов ждёт первого и упирается в кулдаун («Подожди N сек.»); не дождался — «Подожди — твоя дуэль уже
  идёт.». Отвергнуто: `GET_LOCK(…, 0)` (второй тап получал бы «идёт» вместо «подожди N сек.» из критерия) и колонка
  «последняя дуэль» в `characters` (миграция + WipeManifest ради того, что блокировка даёт без схемы).
- Кулдаун читает `DuelService::cooldownSec()` — тот же `pvp.attack_cooldown_sec`, ключ кэша прежний `pvp_duel_cd_<id>`.
- Запись `DUEL`: `PvpBattleLogBuilder` по уравненным бойцам + `duel: true` + `outcome.reason` тай-брейка; сбой записи
  не роняет дуэль (`battle_id=null`, кнопки разбора нет).
- Бот: `ArenaAction`, `DuelAction`, `PvpLadderAction` — рендереры моделей; `SettingsAction` пишет `duels_open` через
  `setDuelsOpen`. «📜 Мои бои» — в нижнем ряду арены и рейтинга; «📜 Разбор боя» под итогом у вызвавшего (раскладку
  `[◀️ Я|🗺|🏆]/[📜]` нормализатор доводит до `[◀️ Я|🗺]/[🏆|📜]`) и у защитника (`PveNotificationSender::keyboard`).
- Хаб «⚙️ Ещё»: строка и кнопка «📜 Мои бои» всегда, сразу за «🏟 Арена». Прод (SELECT 2026-10-08):
  `navigation.more_hub.enabled=1`, `pvp.duel.enabled=1`, `pvp.ladder.enabled=1`, `pvp.attack_cooldown_sec=30`.
- Тесты: `ArenaBotParityTest` — снимки сняты со старых handler'ов (дуэль — настоящий бой под `mt_srand(42)`), до
  переноса падал только на «добавленные кнопки»; кнопки сравниваются плоским списком, ряды — на выход
  `KeyboardNormalizer` без одиночек; плюс тумблер через `SettingsAction` и перебор состояний хаба «Ещё».
  `ArenaScreenServiceTest` — запись `DUEL` обоим в журнале, статы не тронуты, повтор подряд и одновременный (второе
  соединение MySQL держит блокировку), замки, тумблер. `ArenaRosterTest` переведён на ядро; `DuelServiceTest` — `cooldownSec`.
- Не тронуты (были в списке): `BattleJournalService` (дуэль уже читалась с story 01), `PvpLadderServiceTest`,
  `phpstan-baseline.neon` (записей под эти файлы нет, phpstan L9 чистый).
- Tech-writing: новые `services/ArenaScreenService.md`, `handlers/pvp/ArenaAction.md`; обновлены `DuelAction`,
  `PvpLadderAction`, `SettingsAction`, `DuelService`, `MoreSurfaceService`, `BattleJournalService`, `apps/pve/index.md`.
- Вердикты: /guide и совет — в story 03 (раздел `combat` и `*SeedBattleJournalTip.php` по плану); здесь новой
  player-механики нет, кроме входов в журнал.

- **Поправка владельца 2026-10-08** («Дуэли теперь пишутся в журнал боёв, похоже, вместе с координатами обоих
  бойцов, а публичная страница боя открывает любую запись по номеру.»): `writeLog` вырезает `coords` из обоих блоков
  лога; `BattlesController::publicView` — только `PVP`, остальное тот же 404, что «нет боя» (существование записи не
  раскрывается). Читатели `battle_logs` пройдены: админка (за логином), публичный список (PVP), профиль (PVP), дайджест
  возвращения (PVE), TTL-чистка, WipeManifest — `DUEL` видят только участники (журнал) и владелец в админке.
  Попутно закрыта уже открытая на проде выдача PvE-записей по номеру. Класс — `docs/defects/record-exposed-beyond-participants.md`.
- Полная проверка №1 поймала `TelegramSenderBridgeCoverageTest`: ядро слало защитнику без `TelegramBridge::ensure()`
  (из `/play` сообщение молча не ушло бы) — мост поднимается, сбой — лог error без отправки.

## Findings
