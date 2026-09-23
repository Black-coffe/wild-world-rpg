# ADR harvest — bugs-info-0923 (proposed-only)

Result: **no ADR proposed.**

Plan deltas:
- `phpstan-baseline.neon` → story 04 (plus story 03's removed entries): bookkeeping, not architecture.
- Story 06 cut after ask 5 went RED in round 1: a scope correction that only this spec needed.
- Opus-seat UNASKED items (admin map generator 1..1000, faction lock on the quest card, markdown in the quest list): owner's call on a separate spec. Nothing was decided here.

Assumptions:
- Food marker: an interim step under ADR-185, which already covers it. The marker gets removed when `vulyk/craft-shelf-coverage` merges.
- Rewriting an unreleased migration in place with a no-op `down()`: only this spec needed it (the migration never shipped).
- The `## Verification` = full suite rule, forced by cycle.sh byte-matching: framework friction, not product architecture. It recurs (multibase-picker), so it could become a learning or `/vulyk-evolve` material.
- `questInfo_id<N>` callback plus the legacy `questInfo_<title_en>`: a contract only this spec needed.

Human decision (not a delta, lives in the plan's Goal section): "quest card rendered from the `quests` row for every quest, no hard-coded per-quest cards". It has a reason and a rejected option, and it constrains future quests. It is a candidate for an ADR or an amendment to ADR-088. Check whether ADR-088 already covers it.
