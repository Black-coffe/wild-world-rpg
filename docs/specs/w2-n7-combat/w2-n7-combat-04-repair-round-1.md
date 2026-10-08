---
story: w2-n7-combat-04
status: done
returned: DONE
worker: worker-code
model: opus
wave: 4
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
No brief ask is RED in round 1 - the findings below are the whole task.

## Findings
1. app/Services/Quest/DailyTaskService.php:102 [regression] a `DUEL` row in `battle_logs` must not count toward the daily «⚔️ Боец — Победи в боях» (`d_battle_win`, 120-320 gold) - `counter()` counts every row with `winner_id = me` regardless of `battle_type`, so risk-free equalized arena duels between two consenting players now complete a paid combat daily; base evidence: `git show 7ddfa8e6:app/Controllers/Telegram/Commands/Actions/PVP/DuelAction.php` line 33 "0 DB-записей по результату" (duels wrote no row, so the same query at `git show 7ddfa8e6:app/Services/Quest/DailyTaskService.php` line 102 saw only PvE/PvP wins); head writes the row at app/Services/PVE/ArenaScreenService.php:398 (the class in docs/defects/record-exposed-beyond-participants.md: a new row type in a shared table must pass every reader)

## Files
- app/Services/Quest/DailyTaskService.php
- tests/database/DailyTaskServiceTest.php
- app/Services/PVE/BattleJournalService.php
- app/Controllers/Telegram/Commands/Actions/PVP/BattleJournalAction.php
- app/Config/CallbackRoutes.php
- app/Database/Migrations/2026-12-17-100000_WidenBattleLogsType.php
- app/Services/PVE/PveBattleLogWriter.php
- app/Services/Player/PvEService.php
- app/Services/PVE/PveNotificationSender.php
- app/Controllers/Telegram/Commands/Actions/PVP/AttackPlayerAction.php
- tests/database/BattleJournalServiceTest.php
- tests/unit/Services/PVE/PveNotificationSenderTest.php
- tests/unit/Services/Player/PvEServiceNotifyFailureTest.php
- tests/database/AttackPlayerPostBattleKeyboardTest.php
- phpstan-baseline.neon
- app/Services/PVE/ArenaScreenService.php
- app/Services/PVE/DuelService.php
- app/Controllers/Telegram/Commands/Actions/PVP/ArenaAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/DuelAction.php
- app/Controllers/Telegram/Commands/Actions/PVP/PvpLadderAction.php
- app/Controllers/Telegram/Commands/Actions/SettingsAction.php
- app/Services/More/MoreSurfaceService.php
- tests/database/ArenaScreenServiceTest.php
- tests/database/ArenaBotParityTest.php
- tests/database/ArenaRosterTest.php
- tests/database/DuelServiceTest.php
- tests/database/PvpLadderServiceTest.php
- app/Controllers/BattlesController.php
- tests/database/BattlesPublicViewTest.php
- docs/defects/record-exposed-beyond-participants.md
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_battles.php
- app/Views/site/_play/native_battle.php
- app/Views/site/_play/native_arena.php
- app/Views/site/_play/dock.php
- public/assets/css/wildworld-ui.css
- app/Views/site/_layout/meta.php
- public/ui-kit.html
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-17-100010_SeedBattleJournalTip.php
- tests/database/PlayViewControllerTest.php
- tests/unit/Views/PlayViewsTest.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php
- ROADMAP.md

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `DailyTaskService::counter('d_battle_win')` считает только `battle_type IN ('PVE','PVP')` (константы `BattleJournalService`): дуэль на арене — спорт без потерь двух согласных игроков, в оплачиваемое задание дня «⚔️ Боец» не идёт. До W2.N7 дуэль строк не писала, поэтому поведение для PvE/PvP прежнее; на проде строк `DUEL` ещё нет — снятые сегодня `baseline` заданий не сдвигаются.
- `## Files` дополнен `DailyTaskService.php` и `DailyTaskServiceTest.php`: находка называет именно этот файл, а нарезанная repair-story несла только файлы спеки.
- Остальные читатели `battle_logs` сверены (класс `record-exposed-beyond-participants`: новый тип строки — все читатели): `BattlesController`, `ProfileController`, `TributeService` фильтруют `PVP`, `ReturnDigestService` — `PVE`; `DashboardAnalyticsService` (админка) считает по типам; `IslandPulseService` «стычек» считает и дуэли — minor ревью, вне находки, не трогал.
- Тест: фикстура `battle_logs` получила `battle_type` (раньше без него дыра не ловилась), новый `testBattleWinCountsPveAndPvpButNotArenaDuels` — дуэли не засчитаны (исходный случай), PvE и PvP засчитаны (соседняя форма). Без фикса падают оба теста счётчика (5 вместо 2, 3 вместо 2).
