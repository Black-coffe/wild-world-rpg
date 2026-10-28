<!-- seat: review · model: claude-fable-5-1 · round: 1 · head: 9c65d3f0 · pack: 189875507fef · attempt: 1 · recorded: 2026-10-28T21:41:50Z · verdict: BLOCK -->
VERDICT: BLOCK
MODEL: claude-fable-5-1
SUITE: TIMEOUT 606s budget 590s

## Critical
None.
## Major
1. tests/database/PlantCropSeedConditionalWriteTest.php:193 [ask 1] the ask-1 test must build its own schema: it instantiates `App\Database\Migrations\CreateTasksTable` without `require_once APPPATH . 'Database/Migrations/2024-03-22-111828_CreateTasksTable.php'` (migration files are not autoloadable), so on a stand where `tasks` is absent the story's own verification errors `Class "App\Database\Migrations\CreateTasksTable" not found` before any assertion - repro: `vendor/bin/phpunit --no-coverage --no-progress tests/database/PlantCropSeedConditionalWriteTest.php` on `wildworld_tests` without a `tasks` table (current local stand) -> `Tests: 1, Errors: 1`; it only passes inside the full suite because `tests/database/BuildOrderServiceTest.php:40` and siblings load that file first.
## Minor
- tests/database/VehicleActivationNonTransportTest.php:36 drops the shared `characters`/`crafted_items`/`crafted_items_log` in setUp and leaves its minimal copies behind with no tearDown; out of suite order (Vehicle then GoldDecrementIfAtLeastTest) the latter errors `Unknown column 'name'`/`'type'` - suite-order emulation (Vehicle -> exploit-poc -> unit) passes, so no anchor.
- app/Controllers/Telegram/Commands/Actions/NPC/NpcActionChoiceAction.php:81 and NpcDialogueAction.php:154,195 never read `ok`/`summary` of `fight()`, so on a busy lock the player reads «ты отступил» instead of «NPC уже исчез.» (no reward is given; caller behaviour unchanged from base).
- app/Controllers/Telegram/Commands/Actions/Camp/TeleportUseAction.php:275,304 answer a rolled-back move with `baseNotFound()` («База не найдена…»), a misleading text for a transport failure.
- Ask 14 (tech-writing notes, ROADMAP §2/§4/§6) is not in this diff; plan.md routes it to `drone-docs` after merge and the Queen at ship per the constitution - confirm at ship-check.
- Ask 15 evidence: phpstan L9 OK (2s); PoC per-file gate = exactly the 7 baseline reds, 0 environment errors; all 13 new test files pass standalone except the PlantCrop error above; the full suite exceeded the 590s budget and could not be completed here.
