<!-- seat: review · model: claude-opus-5-5 · round: 1 · head: fd74302f · pack: b10881080dee · attempt: 1 · recorded: 2026-10-11T19:18:50Z · verdict: BLOCK -->
VERDICT: BLOCK
MODEL: claude-opus-5-5

## Critical
None.
## Major
1. app/Controllers/Play.php:202 [ask 1] a native web action the core refuses on business grounds (craft start, build start, quest start or branch: WebNativeScreenService.php:1128/1135/1145/834/1272/1300 call logRejected, whose markRejected is a no-op, then return an alert) must be written with status `rejected` and the refusal reason, as the bot writes it via BaseAction.php:275; today the controller only flags rejected() (invalid form or stale intent), so these rows land as `ok` - repro: POST `/play/view` `view=craft&op=craft_start&recipe=<hidden or unaffordable>` then `SELECT status FROM player_action_log ORDER BY id DESC LIMIT 1` returns `ok`.
2. app/Services/Logging/PlayerActionLogger.php:101 [ask 1] a bridge tap (`op=bridge`) whose callback the pipeline marks error, rejected or unrouted must reach the single web row with that status; with the pipeline suppressed, markError (UpdatePipeline.php:153/158, where web dispatch exceptions are swallowed), BaseAction markRejected and CallbackqueryCommand markUnrouted all hit an inactive logger, so Play::view records `ok` for every bridge tap that does not raise InvalidArgumentException.
## Minor
- tests/database/PlayViewControllerTest.php:172 the bridge case runs on fakeAct(), which never enters UpdatePipeline, so "one row per bridge tap" is shown only by the unit test of suppressPipeline; no test covers a refused or failed action's status.
- app/Controllers/Play.php:169 a battle tap from `/play/act` that throws out of battleScreen() writes no row (view() writes `error` for the same case).
- app/Controllers/Play.php:169 without JS, a battle tap writes its own row and then the 303 to `/play?view=…` writes a `play` row too: two rows per tap (the plan accepts this for view-form PRG only).
- app/TaskHandlers/LowHealthWarningHandler.php:51 `web` is not in PLAYER_INITIATED_SOURCES, so the new web rows do not count as player reaction for the low-health warning decay. This predates the branch and no ask covers it.
