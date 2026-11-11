# Scout report: scripts/
last-verified: 2026-11-11 (delta vs 2026-10-26; framework 0.50.3)

## Purpose
Every deterministic gate and helper the VULYK cycle runs on, plus project-only defect checks and `smoke/` helpers. Families: council
state machine (`cycle.sh` + `lib.sh` + `journal.sh`); report-only gates (`ship-check`, `human-check`, `acceptance-log`, `scope-check`,
`wave-check`, `trace-check`, `release-check`); defect-library gates (`defects-check`, `defects-index`, `portability-check`,
`injection-check`, `leak-check`, `prompt-check`, `suite-parts-check`); project checks. All bash/python, safe to re-run.

## Ownership
- Framework-owned: every `scripts/` path in `.claude/vulyk-manifest` (incl. new `suite-parts-check.sh`, locally patched
  `wave-check.sh`, sample `git-hooks/post-merge`, helpers `py.sh run-capped.py symbol-map.* telemetry.sh token-report.py
  trace-autopsy.* memory-triage.sh model-profile.sh evolve-ledger.py prompt-check.*`).
- Project-owned: `defects-duel-knockout-check.php`, `defects-task-result-foreign-check.php`, `defects-bot-lexicon-check.php`,
  `defects-audience-surfaces-check.php`, `defects-confirm-once-check.php`, `defects-preprod-parity-check.php`,
  `smoke/testbot-db.sh`, `smoke/testbot-tap.sh` (`check:` scripts of `docs/defects/` cards; `preprod-parity.sh` sits beside).
- Pending, NOT on develop: `suite-coverage-check.sh`, `adaptation-check.sh` (branch `vulyk/evolve-2026-11-11`).

## Entry points
- `cycle.sh <verb> <spec-dir> [...]` - council CLI (~4400 lines, see `cycle.md`). Callers: `cycle-clerk`, `/vulyk-*`.
- `journal.sh <spec-dir> <stage> "<what>" "<next>"`; `lib.sh` - sourced only (cycle, wave/scope/ship/human/release-check).
- `ship-check.sh <spec-dir>` / `--record ...` - `/vulyk-ship`; has a `ci` line (`gh run list`, newest run per workflow).
- `human-check.sh <spec-dir> <ACCEPTED|REJECTED> [note]` / `--check` / `--show` (diff --stat + diff, capped at 300 lines).
- `scope-check.sh <story-file> [range]` - from `close-story`. `wave-check.sh <spec-dir>` - report-only, exit 0; checks collision,
  order, dangling, manual, no-files, missing, empty-glob, no-verify, verify-gap, repeat, verify-cell, verify-round-only,
  red-no-test, red-seals-impl, red-value.
- `defects-check.sh [<arg>]` - library audit / gate before showing work; `--files <path>...` runs block checks in `close-story`.
- `suite-parts-check.sh <file>` (NEW) - reads `PART <name> <ok|FAIL|SKIP|CAP> <N>s cap <C>s` lines from `suite.log`; flags CAP,
  caps over 300 s and parts past 70% of cap (exit 1); 0 if none; 2 on no/unreadable path.
- `portability-check.sh`, `injection-check.sh`, `leak-check.sh`, `prompt-check.sh` - `check:` scripts / CI gates.
- `top-model.sh`, `model-profile.sh` (`opus-builds|sonnet-builds|economy`), `telemetry.sh`, `memory-triage.sh`, `symbol-map.sh`,
  `trace-autopsy.sh`, `token-report.py`, `evolve-ledger.py`, `py.sh`. `redact.sh` (stdin->stdout mask). `state.sh` -> gitignored
  `.claude/state.json`. `vulyk-update.sh [dir] [--check] [--version X] [--constitution replace]`.
- `git-hooks/post-merge` - sample, not auto-installed; stamps `memory/map/.stale`.
- `smoke/testbot-db.sh` (SQL on stdin), `smoke/testbot-tap.sh <callback_data> [telegram_id]` - Tier-3 preprod helpers; ssh to the
  hardcoded testbot host only; `-tap` posts a synthetic `callback_query` to the webhook (default char 491).

## Contracts
- `cycle.sh` last stdout line = one JSON object (`ok, verb, exit, next, error, now`). Exit codes: `cycle.md`. Report-only gates exit 0.
- `defects-check.sh`: 0 green, 1 red, 2 usage; only NEW findings are red. A block card without file scope never runs under `--files`.
- Paperwork: `lib.sh` `is_paperwork_path` built-in list (plan/journal/council/brief, `memory/stats/*` ledgers, `skills.json`,
  `VERSION`, `CHANGELOG.md`, `CITATION.cff`, `memory/learnings/*.md`) + globs from hive-owned `.claude/vulyk-paperwork`
  (ours: `docs/specs/*/smoke-*.md`, `memory/stats/*.jsonl`); upgrades never touch that file.
- `lib.sh` helpers: `constitution_file` (prefers `CLAUDE.vulyk.md`), `command_cell_exists|round_only`, `verification_segments`,
  `verify_path_tokens`, `is_test_path`, `verify_programs`, `sealed_tests`, `client_path_filled`, `run_capped`, `load_sample`, ...
- Gate split (ADR-029): each `Full suite ... (round-only)` row is one part under 300 s, red past ~210 s; each `## Verification`
  line also capped at 300 s.
- `redact.sh` masks tokens, PEM/PGP, cookies, OTP, passphrase, `*_KEY`; degrades to `cat`, exit 0.

## Gotchas
- ONE local patch left that `/vulyk-update` reverts: `wave-check.sh` strips the first word of each command segment (`args=` line
  before the loop) - the program is never a scope path. Hand-ported onto 0.50.3's rewritten loop; next upgrade drops a
  `.vulyk-new`. Check: `grep -c 'first word of each command' scripts/wave-check.sh` = 1.
- The old `lib.sh` smoke patch and `scope-check.sh` `LEDGERS=` patch are gone - both live in `.claude/vulyk-paperwork`.
  `skills.json` is paperwork for `lib.sh` and `scope-check` (owner decision 2026-10-08).
- `close-story` is a commit and reads gate blocks from the story's approved commit; `git-guard.sh` denies main-session close at Tier 3-4.
- `state.json` is derived, never truth for `cycle.sh`.
- `smoke/*` reach only the testbot by design (2026-06-05 a smoke hit the prod DB). `-tap` ok proves the route, not the outcome:
  check a `testbot-db.sh` delta.
- Project checks: `defects-duel-knockout-check.php` (200 seeds, challenger share 47-53% over 4000 equal duels);
  `defects-task-result-foreign-check.php` (`WebTaskResultPicker::pick()`); `defects-bot-lexicon-check.php` (Telegram-only phrases
  in `app/` string literals fail unless in `ALLOWED` with a reason); `defects-audience-surfaces-check.php <plan|story>`.
- A defects "block" card needs a non-empty `check:` and >= 2 fixtures, else it is text.
- Shell must pass `portability-check.sh` (bash 3.2; bypass: same-line `# portable: <reason>`).
- Subagent `Glob`/`Grep` over `scripts/` return nothing here; the listing above is from the manifest plus files opened by path.
