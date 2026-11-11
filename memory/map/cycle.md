# Scout report: the cycle (build -> council -> repair state contract)
last-verified: 2026-11-11

## Purpose
An agent council replaces the human look. Truth lives in committed files under `docs/specs/<slug>/`, written only by
`scripts/cycle.sh` (~4445 lines). One call, `advance`, runs every mechanical step; agents only build, review and supply
judge inputs. Framework ADRs: `~/.vulyk/src/docs/adr/`. Flow: `/vulyk-plan` -> `/vulyk-build` -> council -> «Выпускаем?» -> `/vulyk-ship`.

## Files, one writer each
- `brief.md ## Asks` <- grill · `plan.md **Briefed:/Approved:/Branch:/Council:/Shipped:**` <- `briefed`/`branch`/`judge`/ship
  (`briefed`/`branch` take `--go`) · `## Needs a human` <- `escalate`/judge · `journal.md` <- `journal.sh` (redacted, exit 0).
- `council/round-N/ROUND` (`head= pack= opened= court= ceiling= tier= seats= since=`, `narrow=1`, `second=risk|none`) <- `open-round`.
- `council/round-N/suite.log` (`head= steps=`, then `STEP`/`PART` lines, or `SUITE: none`) <- `suite`.
- `council/round-N/<seat>.md` <- `record-seat` · `memory/stats/council.jsonl` <- judge · `<spec>/DRIVER` claim lock ·
  `<spec>/manual/<id>` <- `manual-done` · `.vulyk/reports/<slug>/round-N/<seat>.attempt-K.md` (read by `advance --ingest`).
- Gitignored: `PAUSE`, `.vulyk/court/`.

## `cycle.sh` verbs
Exit 0 ok · 1 usage · 2 precondition · 3 paused · 4 malformed report · 5 stale · 6 escalate · 7 verification-call budget
hit (resume mark written). Last stdout line is one JSON object; every `emit` carries `now` (epoch s), mutating verbs end with `status`.
`status [--json]` · `briefed` · `branch` · `close-story <file> [--commit] [--stamp]` · `stamp-red <file>` (red receipt, seals
tests) · `open-round` · `suite <spec> [--commit] [--stamp]` · `record-seat` · `judge` · `escalate [--reason
ceiling|half|env|no-progress|suite-timeout]` · `reopen "<decision>"` · `pause`/`resume` · `claim|release` · `manual-done` ·
`repair` · `advance <spec> [--stamp S] [--claim] [--ingest]` (`--claim` needs `--stamp`).
Guards: mutating verbs -> `pause_guard`; `judge/repair/close-story/open-round/suite` -> `driver_guard`; `suite` -> `branch_guard`;
every spec verb -> `story_status_guard` (status outside todo/in-progress/done/blocked = exit 2). `claim` refuses a dirty tree
outside paperwork; edits inside a todo story's `## Files` come back as `leftover` (re-dispatched, not refused).
`advance`: optional claim -> ingest (missing/empty report spends the attempt; `review2` folded into `review`) -> loop
`branch|open-round|judge|repair --commit` until `next` needs an agent; stops on repeated `next` or after 12 steps.

## The `suite` gate (run by the driver's `gate`, NOT by `advance`)
- One step per call, resumes `suite.log`; a log keyed to another head is discarded.
- **Rows:** each `Full suite ... (round-only)` row of `CLAUDE.vulyk.md ## Commands` is one step, under `VULYK_PART_CAP`
  (default and max 300 s); a part over ~210 s is red. Legacy stepped mode (`--steps`) only with ONE row marked `steps`.
- No row, or a narrow repair round -> `SUITE: none`. Failures write `SUITE: FAIL`; `suite-timeout` counts as FAIL.
- **Project fact:** we have 10 rows (unit1, unit2, db1..db8) -> 10 steps per round. Coverage check:
  `scripts/suite-coverage-check.sh` (pending on `vulyk/evolve-2026-11-11`).
- Solo / hand-run flow must call `suite` itself, or no gate runs.

## `status --json` -> `next` (first match wins)
`shipped` · `paused` · `briefed` · `branch` · `build:<wave>` · `close-story:<file>` · `manual:<ids>` · open round:
`open-round` if stale, else `dispatch:<seats>`, else `judge` · `escalated` (unless reopened) · `green` · `repair` (reopened
ESCALATE too, except `env` or lone `suite-timeout` -> fresh `open-round`) · else `open-round`.
`done` counts only with close-story's own commit in the spec log; otherwise the story reads in-progress.

## Seats, tiers, verdict
- Tier 1-2: `review`. Tier 3-4: `review` + exactly ONE blind seat (`haiku` when Client path is filled — ours is — else `opus`).
- Tier 4 `review2`: never in the frozen roster; `round_needed_seats` adds it on `second=risk` (auth/secret/crypt/permission,
  `.env`, migrations, `schema`, `*.sql`) or after a `review` BLOCK. Never on a narrow round.
- Roster frozen as `seats=` at open-round; GREEN/N/A blind seats carry forward, `review` never does. Ceiling 1/2/3 by tier.
- Verdict: REJECTED in `human.jsonl` -> RED; absent seat -> ESCALATE `env`; `|red| >= half` -> `half`; same ask RED twice ->
  `no-progress`; ceiling -> `ceiling`; else RED/GREEN.
- `repair` writes `<slug>-NN-repair-round-N.md` (worker-code, opus); one RED item -> `narrow: true`, next round review-only
  (no court, no second reviewer, `suite` runs nothing).

## Verification runner (`verify_run`: close-story and stamp-red)
- Each `## Verification` line under `VULYK_VERIFY_TIMEOUT` (default 300; above 300 or invalid acts as 300). Call budget
  `VULYK_VERIFY_CALL` 540: a line starts only if its cap fits, else resume mark + exit 7.
- Red receipt seals only test-shaped paths (`is_test_path`) plus each line's program (`verify_programs`); none test-shaped -> all sealed.

## Drivers
- **Solo (Tier 1-2):** Queen runs `advance`, builds, dispatches lead-review. No Workflow, clerk or court.
- **Hive (Tier 3-4):** `.claude/workflows/vulyk-cycle.js` decides nothing; every shell call via `cycle-clerk`. Args `{spec, stamp,
  top_model, second_model, worker_model, review_model, seat_model, checkpoint_min=50, launched_at}`. Loop `build` -> `council`
  (`gate` = `suite --commit --stamp` until done, <=32 calls -> seats -> `advance --ingest`).
- Stops: story missed twice; seat dispatched 3rd time same round+head; `manual:`; Tier 4 without distinct `second_model`;
  40 iterations; unreadable clerk line twice; `checkpoint` after `checkpoint_min` (clock = `now` in verb lines) -> relaunch with
  fresh stamp. Fable-limit refusal reruns once on opus; missed-story retry uses `retryModel`.

## Gotchas
- GREEN ship question is asked by the command, not cycle.sh. Ship never pushes tags (our tag step is ours).
- Stamp appears only in `--stamp`/DRIVER; blind seats never see it or `round_dir`.
- Smoke evidence `docs/specs/*/smoke-*.md` is paperwork via hive-owned `.claude/vulyk-paperwork` (not `lib.sh`); upgrades keep it.
- `commit_subject`: with Conventional Commits, `story(x): t` becomes `chore(story-x): t`.
- Stale round -> `suite` exit 5; `suite` is DRIVER-guarded, a manual run needs the stamp.
- Windows: `run_capped` probes `timeout 1 true` (System32 `timeout.exe` breaks caps) and falls back to `run-capped.py`.
- Not re-read 2026-11-11: verdict internals, staleness, `reopen`, taint rules, court stripping, MALFORMED re-ask.
