# H6.2 review

## Round 1 - 2026-11-27 - head 1c3780de1560be845af4e896fd46a34795423ccf

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Database/FixEndgameCraftTipNoT4Test.php -> pass, 2 tests, 17 assertions (1 PHPUnit deprecation); php -l on all migrations -> pass (exit 0); vendor/bin/phpstan analyse --memory-limit=512M --no-progress -> pass, no errors

## Asks
1. delivered: five claims corrected with code/ADR citations, discrepancies row 3 updated — GAME_DESCRIPTION.md:400 (T4), :563 (food order), :585 (Northern Lights), :806 (faction modifier), :857 (strategic objects), :879 (row 3). Citations checked against code; one off by a line (see minor).
2. delivered: seven sections with prod state and `game_settings` key — GAME_DESCRIPTION.md:47 (transport), :83 (settlements), :283 (nodes), :299 (PvP ladder), :308 (tribute, described as built but off), :518 (Oracle), :674 (referrals). Headline states match the brief's Answers; sub-switch states `pvp.ladder.broadcast_enabled`=1 and `oracle.weekly_market_enabled`/`oracle.daily_market_enabled`=1 are not in the brief and I had no prod access to confirm them.
3. delivered: W11/W12 described as «готовится, на проде выключено» (`quests.branching_enabled`=0) — GAME_DESCRIPTION.md:426-435.
4. partly: the five directly affected lore notes are fixed, seven new notes added and linked from mmorpg-vault/lore/index.md:26-70, all wiki-link targets exist; three stale lines remain under lore/website (see minor).
5. delivered: no setting-breaking words in added text (no «T4», «мистич», «магич» left in the file); the only quoted balance number, +500, names `Config\EndgameScoring::$strategicObjectDiscoveryPoints` — GAME_DESCRIPTION.md:857; other values referenced by `GameSettings` key only.
6. delivered: no rename in the diff; the canon line naming the artifacts is unchanged — GAME_DESCRIPTION.md:554.
7. delivered: verdicts for `/guide`, new tip, WipeManifest and tech-writing recorded in discrepancies row 9, GAME_DESCRIPTION.md:885; stamp names v0.51.718 at :887, and that tag is the branch base.
8. delivered at head: UPDATE keyed by `title_en='EndgameCraftT4'`, idempotent, no «T4», no digits, `down()` restores the seed text verbatim — app/Database/Migrations/2027-01-02-100000_FixEndgameCraftTipNoT4.php:23-53. The prod SELECT is a post-deploy step and cannot be judged at head.

## Findings
- [minor] [ask 8 | correctness] New tip text reads as if all of «Профессиональный крафт» needs a faction and the capture quest (FixEndgameCraftTipNoT4.php:27-29); in code 17 of 25 Professional-workbench recipes need only the workbench, only the 8 faction recipes carry `required_quest` + `required_faction` (app/Config/CraftRecipes.php:1861-2108). Same conflation in GAME_DESCRIPTION.md:401-403. Smallest fix: scope the sentence to the faction items, e.g. «вершина Профессионального крафта — фракционное оружие и броня. Для них нужны три вещи: …». Test: assert `NEW_CONTENT` contains «Для них» and not «Для него».
- [minor] [ask 4] Three lore lines still contradict the corrected canon: lore/website/index.md:33 and lore/website/Devblog-list.md:50 say the strategic objects are not in the code; lore/website/Sobytiya-list.md:16 maps Полярная ночь to `NorthernLightsHandler.php` while the corrected lore/events/Мировые-события.md:16 says PolarNight is a separate event. Smallest fix: mark each line with the correct claim. Test: `grep -n "Не найдены\|которого в коде" lore/website/index.md lore/website/Devblog-list.md` returns no line about strategic objects without a correction note.
- [minor] [ask 1 | correctness] Three small canon inaccuracies, each repeated in the matching lore note: GAME_DESCRIPTION.md:587 cites `AttributeBoostEffect.php:46`, the pick is on line 47 (46 is blank); GAME_DESCRIPTION.md:91-92 says «нейтральная» is a caption on the settlement screen, `SettlementHubAction.php:139-143` prints nothing for neutral (only safe, hostile and the anomaly type have a line); GAME_DESCRIPTION.md:520-521 says the Oracle button is shown with a lock below `oracle.min_level`, the button is unlocked-looking (`EntertaimentAction.php:42-46`) and the lock screen appears after the tap (`OracleAction.php:63`). Smallest fix: correct the three phrases.
- [minor] [correctness] tests/unit/Database/FixEndgameCraftTipNoT4Test.php checks the constants only; `up()`/`down()` are never run against a `game_tips` row. I read the query rather than ran it: the columns exist in `CreateGameTipsTable` and it matches the sibling `2026-12-31-100000_CommunityChatTipWithoutRobiAnswers.php:48-49`. Smallest fix: a database test that seeds the row, runs `up()` twice and asserts `title_ru`/`content`, then `down()`.
```

Owner: fix minors, round 2 - "Починить minor, раунд 2 (Рекомендую)", 2026-11-27

## Round 2 - 2026-11-27 - head e9442b1295bd0fcf4417229fb4b917e07f382d7e

```
VERDICT: PASS
TESTS: FixEndgameCraftTipNoT4Test.php -> pass, 2 tests, 19 assertions; tests/database/FixEndgameCraftTipNoT4MigrationTest.php -> pass, 1 test, 13 assertions; php -l on all migrations -> pass; phpstan -> no errors

## Findings
(none)

## Past findings
F1: fixed - tip says «Для них нужны три вещи»; canon scopes the three gates to the 8 faction recipes (CraftRecipes.php:1861-2108); unit test asserts «Для них».
F2: fixed - lore/website/index.md:33, Devblog-list.md:50, Sobytiya-list.md:16 corrected (vault 0253b7e).
F3: fixed - AttributeBoostEffect.php:47; only «враждебная» has a caption (SettlementHubAction.php:139-143); Oracle lock after the tap (OracleAction.php:63), in canon and lore.
F4: fixed - database test seeds row 47 and a neighbour, runs up() twice, asserts the fields and the untouched neighbour, then down().
```
