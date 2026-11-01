<!-- seat: review · model: claude-opus-5-5 · round: 1 · head: b920d2ea · pack: c838b68ea4c9 · attempt: 1 · recorded: 2026-11-01T21:01:19Z · verdict: BLOCK -->
VERDICT: BLOCK
MODEL: claude-opus-5-5
SUITE: FAIL 553s

## Critical
1. tests/database/PlayViewControllerTest.php:812 [regression] the full suite must be green: `testCraftWalksBenchCategoryCardAndShowsTheQueue` expects the /play bench to read `💊 Лекарства · 8`, but the head renders 12 because app/Config/CraftCatalog.php:50 now lists four more keys; the base catalog lists 8 (`git show c449b5b1:app/Config/CraftCatalog.php`, line 50), so the test passes on the base and fails on the head - repro: `vendor/bin/phpunit --no-coverage --no-progress --filter testCraftWalksBenchCategoryCardAndShowsTheQueue tests/database/PlayViewControllerTest.php`
## Major
1. app/Config/Consumables.php:29 [ask 2] the four new drugs (Antivenom, ChitinPatch, SulfurOintment, MineralTablets) must be on the 💊 Аптечка shelf, which means listing them in `Consumables::MEDICINE`. They are in neither catalog, so `shelfOf()` sends them to Провизия: PharmacyAction.php:51 does not list them, and a player who holds only these drugs sees "у тебя нет медицинских предметов". The tip (2026-12-23-100020_SeedFieldMedicineTip.php:763) tells players to take them from the 💊 Аптечка screen. `ConsumablesCatalogMatchesDbTest::testEveryDrugRowIsClassifiedInExactlyOneCatalog` fails on every DB where the migration has run (testbot/CI). It only skips on the empty local test DB; the dev DB `mmorpg` already holds all four `type='drug'` rows - repro: `grep -c "Antivenom\|ChitinPatch\|SulfurOintment\|MineralTablets" app/Config/Consumables.php` gives 0
## Minor
- app/TaskHandlers/Craft/GenericCraftCompletionHandler.php:668 the craft-finished caption for the new drugs gives the name, quantity and zone but no heal numbers; ask 6 lists "итог крафта" together with the effect, so a heal line there would close the reading fully.
- app/Database/Migrations/2026-12-23-100000_DeadResAddFieldMedicineItems.php:464 craft time lives in `tasks.min/max_duration`, while plan.md `## Assumptions` says "Лечение и время крафта — в GameSettings, как у S19"; the departure is explained in the story notes but has no `## Descoped` / Plan deltas line.
- tests/unit/Config/CraftRecipesDeadResourcesTest.php:39 the no-gold-printing check uses a hard-coded prod `sell_price` snapshot, so if a resource's price later changes in admin or prod, the drift goes unnoticed.
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php:54 the menu buttons carry no 🔒/level mark; the level requirement only appears after the tap, on the card's level line.
