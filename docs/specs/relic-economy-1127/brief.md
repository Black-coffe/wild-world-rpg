# Relic economy: why veterans hold thousands of «rare» relics (brief)
**Date:** 2026-11-27 · **Owner:** Andrei

## Request (verbatim)
> Делай отдельной спекой, начни с отчёта

(Context the owner answered to, 2026-11-27: prod holders have 6229 / 5990 / 4258 units of relics 69/70/71
(«Изотопное ядро „Дракон-4“», «Архивный носитель Предтеч», «Релейный венец Глубины»), ~40.5M gold at bank sell
price. The relics are ordinary cave gather resources gated by level 55/75/100 (`GatherCellResourceQuery.php:60-61`),
not only the «once in a lifetime» event drop. Options offered: keep and make canon honest; make them truly rare
(event-only, ADR, decide on existing stock); keep the drop, cut the bank price. Recommended: a separate spec that
starts with a read-only report on how much gold relics have already brought in via the bank.)

## Answers
- 2026-11-27, option: «A: честный канон + рычаг (Рекомендую)» — next code spec; merge: «Мерджить (Рекомендую)».

## Asks
1. A report (`report.md`) with facts from prod (SELECT only) and code `file:line`: how relics are obtained (gather
   rate and weighting, event, bosses, quests, bank purchase), what they are used for (craft, collection, bank sale),
   and how much gold they have brought in through bank sales so far.
2. The report says whether other resources share the same pattern («rare» by text, farmable by level gate).
3. Options with the recommended one first and why, each with what happens to existing stock, and which ones need
   an ADR / `GameSettings` change.
4. Nothing on prod or testbot is changed by this spec.
