# Scout report: scripts/
last-verified: 2026-09-30

## Purpose
Every deterministic (model-free) gate and helper VULYK's cycle runs on (framework 0.24.0 since
2026-09-30). Families: the council state machine (`cycle.sh` + `lib.sh` + `journal.sh`), the
report-only gates (`ship-check`, `human-check`, `acceptance-log`, `scope-check`, `wave-check`,
`trace-check`, `release-check`), the defect-library gate (`defects-check.sh`), and project-only
`smoke/` helpers outside VULYK. All bash, `set -u` or stricter, safe to re-run.

## Entry points
- `cycle.sh <verb> <spec-dir> [...]` - the council's CLI; called by `cycle-clerk` (Workflow driver)
  and by `/vulyk-build|review|plan|pause|resume`.
- `journal.sh <spec-dir> <stage> "<what>" "<next>"` - appends one line to `<spec-dir>/journal.md`.
- `lib.sh` - sourced only, no `exit`; consumed by `cycle`, `ship-check`, `human-check`,
  `acceptance-log`, `release-check`.
- `ship-check.sh <spec-dir>` / `--record <spec-dir> <version> [note]` - stage 06, `/vulyk-ship`.
- `human-check.sh <spec-dir> <ACCEPTED|REJECTED> [note]` / `--check` - owner override record.
- `acceptance-log.sh` - **legacy** pre-council ledger; `ship-check` falls back to it only for specs
  with no `council.jsonl` row.
- `scope-check.sh <story-file> [range]` - always called by `cycle.sh close-story`.
- `wave-check.sh <spec-dir>` - `/vulyk-plan` step 7, `/vulyk-build` pre-dispatch, after each repair.
- `trace-check.sh <spec-dir>` - `/vulyk-plan` step 7 and after a plan delta.
- `release-check.sh [target-count]` - 1.0.0 bar meter, by hand.
- `defects-check.sh [<arg>]` - no arg = library audit of `docs/defects/`; with `<arg>` = gate before
  showing work to the owner (runs each `block` card's `check:`). `DEFECTS_DIR` overrides.
- `top-model.sh` / `--explain|--apply|--check|--floor` - resolves `fable`|`opus`; used by
  bootstrap/build/review/status and the `top-model-brief.sh` hook.
- `redact.sh` - stdin->stdout secret mask; `session-end-learnings.sh`, `/vulyk-plan` step 2.
- `state.sh [spec-dir]` - writes gitignored `.claude/state.json`, read by `/vulyk-status` only.
- `vulyk-update.sh [dir] [--check] [--version X] [--constitution replace]` - wraps `install.sh --upgrade`.
- `git-hooks/post-merge` - sample, not auto-installed; stamps `memory/map/.stale`.
- `smoke/testbot-db.sh` (SQL on stdin) and `smoke/testbot-tap.sh <callback_data> [telegram_id]` -
  Tier-3 preprod helpers (evolve 2026-09-29): ssh to the hardcoded testbot host with
  `${WW_DEPLOY_KEY:-~/.ssh/wildworld_deploy}`; `-db` reads creds from the server's `~/shared/.env`;
  `-tap` posts a synthetic `callback_query` to the webhook (default test char 491).

## Key types / contracts
- Every `cycle.sh` verb's last stdout line is one JSON object `{"ok","verb","exit","next","error"}`.
  Exit: 0 ok (RED verdict is still ok), 1 usage, 2 precondition, 3 paused, 4 MALFORMED / red
  verification, 5 stale, 6 escalate. Report-only gates always `exit 0`; refusal is the caller's job.
- `defects-check.sh`: 0 green, 1 red, 2 usage / no library / no python3; last line is the verdict.
  Findings: debt, UNDELIVERABLE (text card without `paths:`), OVERLAP (one key on two live cards),
  ESCAPE (quote after `check:` with no fixture/check change). Only NEW findings are red; new = newer
  than the `docs/defects/README.md` commit (git blame) or uncommitted.
- `lib.sh` exports: `pack_fingerprint`, `is_paperwork_path`, `paperwork_only`, `marker`, `now_ts`,
  `slug_of`, `is_story_file`, `constitution_file` (prefers `CLAUDE.vulyk.md`), `command_cell_exists`,
  `verification_segments`, `profile_value`, `client_path_filled`, `model_floor|model_version|model_below_floor`.
- `is_paperwork_path`: `docs/specs/*/{plan.md,journal.md,council/*,brief.md,smoke-*.md}`,
  7 `memory/stats/*.jsonl`, `memory/stats/skills.json`, `VERSION`, `CHANGELOG.md`, top-level
  `memory/learnings/*.md`.
- `redact.sh` masks ~20 token shapes + PEM blocks (0.24.0 added Telegram bot, glpat, npm, pypi, hf,
  gsk, SendGrid, Stripe live, Slack webhook); degrades to `cat`, exit 0.
- `.claude/hooks/defect-intake.sh --lexicon` prints the correction lexicon as ERE lines for
  `litopys corrections --lexicon` (`/vulyk-evolve`; needs litopys >= 0.4.0).

## Dependencies
- inbound: `cycle-clerk`; `/vulyk-*` commands; `top-model-brief.sh` hook.
- outbound: `git` (worktree, diff, commit); `cycle.sh` reads/writes `memory/stats/{council,human}.jsonl`
  and `docs/specs/<slug>/{plan,brief,journal}.md|PAUSE|council/`; `scope-check` appends `scope.jsonl`;
  `defects-check` needs python3 + git blame.

## Gotchas
- LOCAL PATCHES that `/vulyk-update` REVERTS (ADAPTATION.md §6, re-apply + grep): `lib.sh`
  `smoke-*.md` in `is_paperwork_path`; `scope-check.sh` drops all `memory/stats/*.jsonl` (`LEDGERS=`);
  `wave-check.sh` skips the first word of each command. The older `lib.sh` `memory/learnings` and
  `cycle.sh` `CLAUDE.vulyk.md` patches are upstream since 0.24.0.
- `skills.json` is paperwork for `lib.sh` but still counts as scope in `scope-check` (owner decision).
- `close-story` verification whitelist and `docs/specs/*/` anchoring are security-relevant matches.
- `state.json` is derived, never truth for `cycle.sh`.
- `smoke/*` reach only the testbot by design (2026-06-05 a smoke hit the prod DB). `-tap` ok proves the
  route, not the outcome - check a `testbot-db.sh` delta.
- `defects-check` "block" needs a non-empty `check:` and >= 2 fixtures, else the card is text.
