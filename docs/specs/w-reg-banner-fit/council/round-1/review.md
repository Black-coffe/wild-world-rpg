<!-- seat: review · model: claude-fable-5-1 · round: 1 · head: d4cb0b05 · pack: a4c87b275913 · attempt: 1 · recorded: 2026-10-26T19:01:17Z · verdict: BLOCK -->
VERDICT: BLOCK
MODEL: claude-fable-5-1

## Critical
None.
## Major
1. public/assets/js/wildworld-play.js:78 [ask 1] the fit check must judge the newest message at its final laid-out height, so a photo message is measured with its image occupying its real height and not 0 px: line 78 reads `#play-state` synchronously right after `first.open = true` (at `defer` time on page load, right after `replaceWith` on poll), while app/Views/site/_play/banner.php:66 renders the photo as `<img loading="lazy">` with no width/height and public/assets/css/wildworld-ui.css:952 sizes it `height: auto; max-height: 420px`, so an image not yet loaded contributes 0 px, the check passes, the message stays open, and when the image arrives (up to 420 px) `#play-state` drops below ¾ of the window - exactly the "длинное с фото" case the brief names as the one that must stay collapsed; from the smoke table (1440×900, threshold 675 px) a single new photo message leaves `#play-state` at roughly 600 px when measured and roughly 1000 px once the image loads - repro on preprod: make the newest unread a photo message, open /play at 1440×900, after the `load` event evaluate `!!document.querySelector('#play-banner details[open]') && document.getElementById('play-state').getBoundingClientRect().top + scrollY > innerHeight * 0.75` → `true` (expected `false`).
## Minor
- `timeout 540 vendor/bin/phpunit --no-coverage --no-progress` was killed by the cap before printing a verdict (known suite length, `VULYK_VERIFY_TIMEOUT=1500`); the story's file tests/database/PlayInboxBannerTest.php passes (9 tests, 270 assertions).
- docs/specs/w-reg-banner-fit/w-reg-banner-fit-01.md acceptance boxes are unticked and `## Findings` is empty: the 1440×900 preprod measurement ask 1 names has no evidence yet (expected after the preprod deploy per the flow), and that smoke should use a photo message as the newest one - the case the fit check exists for.
- public/assets/js/wildworld-play.js:21-22 header comment still describes W-REG.5 as "самое новое раскрыто" with no fit condition; the inline comment at 66-69 carries it.
