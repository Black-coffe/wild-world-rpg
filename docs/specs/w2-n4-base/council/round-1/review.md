<!-- seat: review · model: claude-opus-5-5 · round: 1 · head: 37856a59 · pack: 10bcc90aa632 · attempt: 1 · recorded: 2026-09-27T20:20:56Z · verdict: BLOCK -->
VERDICT: BLOCK
MODEL: claude-opus-5-5

## Critical
None.
## Major
1. app/Services/Buildings/BuildOrderService.php:302 [ask 4] start() must check «уже строится» under the character-row lock: it has to refuse a second build of the same building at the same base while one is in_work, so a bot double-tap or a second client cannot create a second task when the backpack holds materials for two (today only the base slot limit and the conditional material write stand between them, and no such check exists) - repro: in `tests/database/BuildOrderServiceTest.php::testSecondStartOnStockForOneIsRefusedWithoutPartialDeduction`, double the fixture stock; the second `start(1, null, 'Workshop')` returns ok and `taskCount()` is 2.
2. app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php:122 [ask 5] when the base is known, the upgrade confirmation's back button «❌ Отмена» must carry `_b<id>`; it still sends bare `Base`, so a multi-base player lands on the picker instead of the same base. Story 02 logged this as a Finding, and plan.md `## Descoped` has no line for it.
## Minor
- app/Services/Web/WebNativeScreenService.php:625 web `upgrade()`/`buildStart()` and the catalog skip the relocation block (`ActiveTasksService::checkRelocationAndBlock`) that the bot applies in UpgradeBuildingAction:65 and BuildListAction, so the web can upgrade during an active relocation.
- app/Services/Player/BuildingUpgrade/BuildingUpgradeMessageFormatter.php:114 `confirm_upgrade_building_<id>` carries no level: a bot double-tap that arrives after the first commit re-validates and pays for the next level too; the `WHERE level = n-1` guard only catches overlapping requests.
- native_base.php:257 the web upgrade card shows no effect now/next (plan contract `effect_now`/`effect_next`); only the build card shows `info_text`.
- ask 7 (375/768/1440 without horizontal scroll) and ask 10 (live preprod pass) have no evidence on the branch yet; both are Queen-owned after merge.
