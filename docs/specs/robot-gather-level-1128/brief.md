# Robot gathering ignores character level — hotfix (brief)
**Date:** 2026-11-28 · **Owner:** Andrei

## Request (verbatim)
> Question: "Robot gathering has no level check: a level-10 workshop (the maximum) brings in rarity-1 resources, relics included, whatever the character's level. On prod 4 workshops are at level 10. Their owners are at levels 289, 659, 208 and 59, and only the level-59 one is below the relic thresholds (70 → lvl 75, 71 → lvl 100); they hold none of 70/71. Close it now or in spec A? (Checked with Sonnet: agrees with the hotfix now, and asks to put the rule into a shared query rather than copy it, and to check the empty-selection case.)"
> Answer: "Separate hotfix now (Recommended)" — "Per «Дыра не ждёт»: the same `level_required <= level` rule in the robots' query through a shared scope, a DB test first, Fable review, preprod smoke, tag. Same bug class as the one just fixed, so it's quick. Downside: one more release, and nothing has been lost yet."

Context: follows `relic-event-level-1128` (v0.51.721), which put the rule for events into
`ResourceModel::eventGrantCandidates` (`app/Models/ResourceModel.php:215`). Robot query:
`app/TaskHandlers/CompleteRobotGatheringHandler.php:232-236`.

## Answers
- 2026-11-28: nothing to take back — prod SELECT shows no under-level holder of 70/71 among workshop-10 owners.

## Asks
1. Robot gathering never brings a resource whose `level_required` is above the character's level.
2. A character whose level meets the requirement gets the same resources and amounts as before.
3. The level rule lives in one shared place on `ResourceModel`, used by both the event query and the robot query
   (no third copy of the condition).
4. When the level filter leaves a biome with no resources, robot completion still finishes normally (existing
   "nothing gathered" path), no error.
5. No new balance number; tech-writing notes updated; guide and tips verdicts stated.
