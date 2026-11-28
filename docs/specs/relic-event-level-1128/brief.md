# Relic event ignores character level — hotfix (brief)
**Date:** 2026-11-28 · **Owner:** Andrei

## Request (verbatim)
> Question: "Open specs: none. What next? Option A from the relic report has three parts: honest texts, a GameSettings multiplier, and the IntentApplier bug where an event can hand a level-100 relic to a level-2 character. (Checked with Sonnet: it agrees, and it was Sonnet that pointed out the bug should not wait in a feature spec. I haven't checked yet whether a real player can reach it.)"
> Answer: "Hotfix first (Recommended)" — "Per the «Дыра не ждёт» rule, the IntentApplier bug goes out as a separate small hotfix, and the texts and multiplier follow as an ordinary spec. Downside: two releases instead of one. If the code shows players can't reach the bug, I'll fold it back into the spec."

Source finding: `docs/specs/relic-economy-1127/report.md:48-49,124` — event `RevealingHiddenCameras`
(`app/Config/WorldEvents.php:552-567`) grants 1–3 units at 10% per tick while gathering in caves;
`app/Services/Events/IntentApplier.php:167-186` does not check level, so a level-2 character can get relic 71.

## Answers
- 2026-11-28, plan approval: «Approve, leave as is (Recommended)» — the 13 under-level relic units on prod stay with their holders; a deliberate decision, not a deferral (source not provable; bank purchase and boss loot are legitimate). They cannot be taken back later without new evidence.

## Asks
1. An event resource grant never gives a character a resource whose required level is above the character's level;
   when no candidate fits, the character gets nothing (no error, no other side effect).
2. A character whose level meets the requirement still gets the event grant as before (same candidates, same amounts).
3. Reachability on prod is stated with SELECT evidence: how many characters below the relic's required level hold it
   or got it from the event; what happens to those proceeds is decided by the owner (left as is unless told).
4. No new balance number in code: the level rule uses the resource's own required level, not a constant.
5. Tech-writing note for `IntentApplier` updated; guide verdict and tips verdict stated (expected «нет»: backend fix,
   no new player surface).
