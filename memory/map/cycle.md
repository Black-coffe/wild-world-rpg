# Scout report: the cycle (build -> council -> repair state contract)
last-verified: 2026-09-30

## Purpose
An agent council replaces the human look. Truth lives in committed files under `docs/specs/<slug>/`, written
only by `scripts/cycle.sh` (~2940 lines). Since 0.18 (ADR-013) one call, `advance`, runs every mechanical step;
agents only build, review, judge-inputs. Framework ADRs live in `~/.vulyk/src/docs/adr/` (001 state contract,
013 light VULYK). Flow: `/vulyk-plan` -> `/vulyk-build` -> council -> «Выпускаем?» on GREEN -> `/vulyk-ship`.

## Files, one writer each
`brief.md ## Asks` <- plan grill · `plan.md **Briefed:/Approved:/Branch:/Council:/Shipped:**` <-
`briefed`/`branch`/`judge`/ship · `## Needs a human` <- `escalate`/judge · `council/round-N/ROUND`
(`head= pack= opened= court= ceiling= tier= seats= since=`) <- `open-round` · `council/round-N/<seat>.md` <-
`record-seat` · `memory/stats/council.jsonl` <- judge · `journal.md` <- `journal.sh` · `<spec>/DRIVER` (claim
lock, `stamp=`/`claimed=`) · `<spec>/manual/<id>` <- `manual-done` · `.vulyk/reports/<slug>/round-N/<seat>.attempt-K.md`
(seat-written, read by `advance --ingest`). Gitignored: `PAUSE`, `.vulyk/court/`.

## `cycle.sh` verbs
Exit 0 ok · 1 usage · 2 precondition · 3 paused · 4 malformed report · 5 stale · 6 escalate; the last stdout line
is always one JSON object.
`status [--json]` · `briefed [--commit] [--mode mini-brief|assumed]` · `branch` · `close-story <file> [--commit]
[--stamp]` · `open-round` · `record-seat <spec> <N> <seat> [--model] [--stamp] [--file]` · `judge` ·
`escalate [--reason ceiling|half|env|no-progress]` · `reopen "<decision>"` · `pause`/`resume` ·
`claim|release <spec> <stamp>` (DRIVER lock; claim refuses a dirty tree outside paperwork and a foreign stamp) ·
`manual-done <spec> <id> [note]` · `repair [--commit]` · `advance <spec> [--stamp S] [--claim] [--ingest]`.
Mutating verbs call `pause_guard` (exit 3); `judge/repair/close-story/open-round` also `driver_guard`.
`advance`: optional claim -> ingest (missing seat recorded from its report file, or empty = spends the attempt;
Tier 4 folds `review-top`+`review-second`, BLOCK if either) -> loop `branch|open-round|judge|repair --commit`
until `next` needs an agent; stops on a repeated `next` or 12 steps.

## `status --json` and `next`
`next`, first match wins: `shipped` -> `paused` -> `briefed` -> `branch` -> `build:<wave>` -> `close-story:<file>`
-> `manual:<ids>` -> [open round: `open-round` if stale, `dispatch:<seats>`, else `judge`] -> `escalated` (unless
reopened) -> `green` (GREEN, pack matches, not stale) -> `repair` (RED; also a reopened non-`env` ESCALATE) ->
else `open-round`. Extra keys: `since, seat_attempt{seat:k}, seats[], manual[]`.

## Seats, tiers, verdict (`required_seats_for_tier`, `cmd_judge`)
Tier 1-2: `review`. Tier 3-4: `opus review`, plus `haiku` when the Client path is filled (ours is). **`sonnet` is
in no roster.** The roster is frozen as `seats=` at open-round; GREEN/N/A blind seats of round n-1 carry forward,
`review` never. Ceiling: 1/2/3 by tier; `council/CEILING` overrides.
Verdict, first match (A = asks, `half = max(2, ceil(A/2))`): 1. REJECTED `human.jsonl` row >= `ROUND.opened` -> RED.
2. required seat ABSENT, no RED, review != BLOCK -> ESCALATE `env`. 3. `|red| >= half` -> ESCALATE `half`.
4. review BLOCK or any RED: same ask RED as round N-1 -> ESCALATE `no-progress`; else `red_rounds+1 >= ceiling` ->
ESCALATE `ceiling`; else RED. 5. else GREEN. An unanchored review BLOCK is recorded PASS with a note; its findings
go to ship's next-circle draft. `repair` writes `<slug>-NN-repair-round-N.md` (`worker-code`, `model: opus`).

## Drivers
Solo (Tier 1-2): no Workflow, clerk or court; the Queen runs `advance`, builds, dispatches lead-review.
Hive (Tier 3-4): `.claude/workflows/vulyk-cycle.js` decides nothing, every shell call goes via `cycle-clerk`;
`args {spec, stamp (>=12 chars), top_model, second_model}`; claims itself, releases in `finally`; stops on a story
missed twice, a seat dispatched a 3rd time in a round, `manual:`, Tier 4 without a distinct `second_model`,
iteration cap 40, an unreadable clerk line twice. The fallback in `/vulyk-build` mirrors it.

## Gotchas
- The GREEN ship question is asked by the command, not cycle.sh; ship never pushes (our tag step is ours).
- Stamp only in `--stamp`/DRIVER; blind seats never see it or `round_dir`.
- Local `lib.sh` puts `docs/specs/*/smoke-*.md` in paperwork, so the preprod proof does not stale a GREEN
  round. `/vulyk-update` rolls it back (ADAPTATION §6). `cycle.sh` itself equals vanilla since 0.24.0.
- Not re-read 2026-09-30 (carried over): staleness details, `reopen` (+3 ceiling, `## Answers`), taint rules,
  court stripping, MALFORMED re-ask.
