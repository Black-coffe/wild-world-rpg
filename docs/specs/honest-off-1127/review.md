# HONEST-OFF — review

## Round 1 - 2026-11-27 - head 42bbc541f937e5b15a357f6207aa94f8041c45df

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding tests/unit/Content/NavigationLabelsInTextsTest.php tests/unit/Web/WebPlayTextsTest.php tests/database/TipServiceTest.php tests/database/DailyTipBroadcastHandlerTest.php tests/database/CommunityChatTipWithoutRobiAnswersTest.php -> pass, 310 tests, 2276 assertions (2 PHPUnit deprecations)

## Asks
1. delivered: the `tribute` section carries `flag => 'tribute.enabled'` and is dropped from the index, the direct `guide_tribute` callback and the "Дальше" chain while the flag is off; it returns when the flag is on. Bot and `/play` share the one `GuideService`. C:\laragon\www\mmorpg\app\Services\Onboarding\GuideCatalog.php:759, :1092; C:\laragon\www\mmorpg\app\Services\Onboarding\GuideService.php:58, :100
2. delivered: `pickForCharacter()` excludes `TributeDominance` while `tribute.enabled` is off or its row is missing, and it is the only selection path for `/tips` and "Совет дня"; there is no other player-facing tips list. C:\laragon\www\mmorpg\app\Services\Player\TipService.php:39, :78
3. delivered: the chat section and tip №143 no longer say Роби answers; where the chat is, who helps and where to take a personal question remain. C:\laragon\www\mmorpg\app\Services\Onboarding\GuideCatalog.php:1073; C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-31-100000_CommunityChatTipWithoutRobiAnswers.php:25
4. delivered: neither promise appears in `app/Views/site/*`, `public/`, `GAME_DESCRIPTION.md`, or in `site_pages`/`site_posts` of the local database, so the diff has nothing to fix there. Prod site content was not reachable from here.
5. delivered: asterisks are paired, the rendered tip is under 1024 characters, there are no digits, and the bot-lexicon check and `WebPlayTextsTest` pass. A live render pass in the bot and `/play` cannot be seen from the branch. C:\laragon\www\mmorpg\tests\database\CommunityChatTipWithoutRobiAnswersTest.php:55
6. delivered: no new settings keys or balance numbers; the migration only updates `game_tips.content`, so there are no new tables or columns.
7. delivered: two existing guide sections and tip №143 edited, №81 gated, nothing new added; vault notes for `GuideCatalog`, `GuideService`, `GuideAction`, `TipService` and `DailyTipBroadcastHandler` are updated (vault commits 79dc2d2, b048ff8).

## Findings
- [minor] [correctness] The chat section now states "Роби в чате не пишет" unconditionally, C:\laragon\www\mmorpg\app\Services\Onboarding\GuideCatalog.php:1073-1074. Once `community.enabled` is turned on the guide says the opposite of the truth (the local database already has it at 1), and unlike `tribute` nothing ties the text to the flag. Smallest fix: drop that sentence and keep only what is true in both states ("Живые выжившие — и владелец игры, когда заглядывает"), or gate the bullet on `community.enabled`. Test: with `community.enabled` on, the `chat` body does not contain "Роби в чате не пишет".
```

Reviewer note: also green — Community guard/voice/contract tests, `tests/unit/Defects`, related DB content tests (207), phpstan on touched services, `php -l` on the new migration. Prod `site_posts`/`site_pages` were checked by the main session with SELECT on 2026-11-27 (no matches).

Owner: fix the minor, round 2 - "Убрать фразу, раунд 2 (Рекомендую)", 2026-11-27

## Round 2 - 2026-11-27 - head daf62071e431391c55b5b2eb9c736b6ae38aeb96

```
VERDICT: PASS
TESTS: vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding tests/unit/Content/NavigationLabelsInTextsTest.php tests/unit/Web/WebPlayTextsTest.php tests/database/TipServiceTest.php tests/database/DailyTipBroadcastHandlerTest.php tests/database/CommunityChatTipWithoutRobiAnswersTest.php -> pass, 311 tests, 2279 assertions (2 PHPUnit deprecations)

## Asks
1. delivered: the `tribute` section carries `flag => 'tribute.enabled'` and all player screens go through the filtered list, C:\laragon\www\mmorpg\app\Services\Onboarding\GuideCatalog.php:759 and :1091-1105; index, section and "next" use it at C:\laragon\www\mmorpg\app\Services\Onboarding\GuideService.php:58, :100, :108. Bot and `/play` share `GuideAction`/`GuideCommand`, the only two constructors of `GuideService`.
2. delivered: `TributeDominance` is excluded in the single pick path while the flag is off, C:\laragon\www\mmorpg\app\Services\Player\TipService.php:39-41 and :78-85; off and on are both tested at C:\laragon\www\mmorpg\tests\database\TipServiceTest.php:217 and :237.
3. delivered: the chat section no longer promises Robi's answers and keeps where, who answers and the private route, C:\laragon\www\mmorpg\app\Services\Onboarding\GuideCatalog.php:1065-1078; tip 143 is rewritten by C:\laragon\www\mmorpg\app\Database\Migrations\2026-12-31-100000_CommunityChatTipWithoutRobiAnswers.php:26-28.
4. delivered: tracked `app/Views/site/*` and C:\laragon\www\mmorpg\GAME_DESCRIPTION.md contain neither promise (grep for tribute, wanted board, community chat and Robi answering found nothing). `site_pages` and posts are database rows and cannot be checked from the code.
5. delivered: both texts have paired markdown and no balance numbers, and the section tests pass (C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\GuideCatalogTest.php:284-299). The live render check in the bot and `/play` leaves no trace in code, so I could not confirm it.
6. delivered: no new numbers; the migration only updates `game_tips.content`, with no table or column added. `FLAGGED_TIPS` maps a title to an existing flag key.
7. delivered: no new guide section or tip; the notes `GuideCatalog.md`, `GuideService.md`, `TipService.md`, `GuideAction.md` and `DailyTipBroadcastHandler.md` under C:\Projects\mmorpg-vault\tech-writing\ describe the change, the first and third dated 2026-11-27.

## Findings
(none)

## Past findings (round 2 and later)
F1: fixed - the sentence "Роби в чате не пишет…" is gone; the bullet is exactly the owner's wording from `## Answers` (C:\laragon\www\mmorpg\app\Services\Onboarding\GuideCatalog.php:1074), so it is true in both states of `community.enabled`. `testChatSectionHasNoRobiClaimWhenCommunityOn` (C:\laragon\www\mmorpg\tests\unit\Services\Onboarding\GuideServiceFlagTest.php:91-102) fails on the old text and passes now.
```
