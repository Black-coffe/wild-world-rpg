# Scout report: agents and commands (framework 0.50.3)
last-verified: 2026-11-11

## Purpose
Every `.claude/agents/*.md` caste, `.claude/commands/vulyk-*.md` entry point, `.claude/hooks/*` and the one workflow at 0.50.3
(upgraded from 0.44.0 on 2026-11-10). `council-sonnet` and `drone-acceptance` are gone; `acceptance-log.sh` survives only as
`ship-check.sh`'s fallback. Project deviations: `docs/vulyk/ADAPTATION.md` §6.

## Agents (model / effort / tools / maxTurns)
- **council-haiku** (`opus`, no effort, `Bash,Read,mcp__chrome-devtools__*,mcp__claude-in-chrome__*`, 60) - black-box seat,
  named for its angle; walks *Client path*, reads no source. Ours: Browser MCP = none -> curl / webhook POST only.
- **council-opus** (`opus`, medium, `Bash,Read,Grep,Glob`, 60) - intent/edge seat. Both seats: `disallowedTools: Write,Edit,
  NotebookEdit`, `omitClaudeMd`; dispatch names slug/round/court/report path only. 0.50.3: "Finishing work" text - an ordinary
  obstacle is diagnosed before `N/A - why: environment` (~+30% output tokens).
- **Tier 3-4 council (0.49):** ONE blind seat (`haiku` if Client path filled, else `opus`) + `review`.
- **cycle-clerk** (`haiku`, low, `Bash`, 5) - runs ONE `cycle.sh`/`journal.sh` command, returns the last stdout line.
- **lead-review** (`opus`, high, `Read,Grep,Glob,Bash`, 60) - reviewer seat; first line `VERDICT: PASS|BLOCK`; only `[ask N]` /
  `[regression]` hold a BLOCK. Tier 4 `review2` only after a BLOCK or on a risk path.
- **drone-coverage** (`opus`, medium, `Read`, 5) - brief + plan only, reports by ask number.
- **drone-scout** (`sonnet`, low, `Read,Grep,Glob`, 15) - recon; format of every `memory/map/*.md`.
- **drone-docs** (`sonnet`, low, edit tools, 40) - LOCAL "Project path binding": notes -> `mmorpg-vault/tech-writing/`.
- **worker-code / worker-test** (`sonnet`, medium, full edit + Bash, 90) - one story, closes with `close-story --stamp`; Tier 3-4.
- **queen-planner** (`opus`, high, 40) - Tier 3-4 plans; does NOT cut repair stories (`cycle.sh repair` does).
- **lead-architect** (`opus`, high, 30) - LOCAL "Project path binding": ADRs -> `mmorpg-vault/decisions/ADR-NNN-<slug>.md`.
- **librarian** (`opus`, low, 25) - sole writer merging `memory/learnings/`; ADR harvest.
- Project-only (not VULYK): `community-antispoiler` (ADR-176) and 8 `redkollegiya-*` (bodies not re-read).

## Commands
- **/vulyk-plan** - deliverable check (study -> `report.md`), tier, brief, recon (no scout for a fresh slice), `--horizon` only
  on request, grill in batches <=4, plan (queen-planner at Tier 3-4), stories, `wave-check` + `trace-check`, `drone-coverage`.
  Plan committed before the approval stop; Tier 1 -> mini-brief + build; Tier 2-4 stop (`--go` opts out).
- **/vulyk-build** - Tier 1-2 solo loop `advance` -> `build:<W>` -> `dispatch:review` -> `advance --ingest`. Tier 3-4: Workflow
  `scriptPath: ".claude/workflows/vulyk-cycle.js"`; checkpoint after ~50 min -> relaunch. Queen may not close a Tier 3-4 story
  (git-guard). `green`: pastes `human-check.sh --show` diff, then ONE «<slug>: council GREEN, round <n>. Выпускаем?».
- **/vulyk-review** - one round on demand; RED -> repair via `/vulyk-build`. Narrow repair: one RED item in one non-test file ->
  next round review-only.
- **/vulyk-ship** - `ship-check.sh` (with a `ci` line via `gh`) -> release commit -> local merge -> prints publish command ->
  `--record` -> `drone-docs`/`librarian`. Pushes only the spec branch for CI; the tag is ours (CLAUDE.vulyk.md).
- **/vulyk-evolve** - ledger (`evolve-ledger.py`; pending branch = stop), harvest + token-report + corrections, check-in,
  admission, worktree `vulyk/evolve-<date>`, human gate. Applies nothing to the default branch.
- **/vulyk-gc** - librarian pass; refuses when `CONSOLIDATED.md` lost more than half its entries/bytes.
- **/vulyk-bootstrap** - facts from the repo, asks only on gaps. **/vulyk-pause|resume**, **/vulyk-status**, **/vulyk-map**,
  **/vulyk-update** - semaphore, dashboard, scout-batch map, release upgrade.
- **/vulyk-handoff** - LOCAL: drives global `~/.claude/hooks/context_guard.py`, not `handoff.{sh,py}`.

## Hooks (wired in `.claude/settings.json`)
- SessionStart: `session-start-brief.sh` (brief, `maintenance due`, mods handling `tool.check`), `vulyk-update-check.sh`, inline
  PowerShell git-fetch (PC<->laptop), `community-pull.sh` (ADR-176), `top-model-brief.sh`, `defects-inject.sh reset`, `ysk-offer.sh`.
- SessionEnd: `anomaly-scan.sh` (timeout 30). PreCompact: `context-guard.sh`. UserPromptSubmit: `defect-intake.sh`.
- PostToolUse: `skill-usage-counter.sh` (Skill), two inline PowerShell Write|Edit reminders (site-drafts -> redkollegiya;
  migrations -> WipeManifest), `defect-intake.sh answer` (AskUserQuestion).
- PreToolUse: `defects-inject.sh` (Edit|Write|MultiEdit|NotebookEdit|Read|Bash|WebFetch|WebSearch|Agent|mcp__.*) and
  **`git-guard.sh`** (`Bash|PowerShell`, 0.48): denies git verbs that discard uncommitted work (stash, checkout ./-f/-- path,
  restore, reset --hard, clean -f, switch -f, branch -D, worktree remove --force). Subagents: always denied; main session: only
  when the paths are dirty. Also denies main-session `close-story` at Tier 3-4. Fails open; not covered: `rm`, `bash -c`, scripts,
  aliases, abbreviated long options (`--har`) - reported upstream-worthy 2026-11-10.
- Defect hooks (0.50): block cards checked in `close-story` via `defects-check.sh --files`; `defect-intake.sh` asks first for
  correction words inside quotes.

## Dependencies
inbound: settings.json, the constitution. outbound: `cycle.sh`, `journal.sh`, `ship-check.sh`, `top-model.sh`, `model-profile.sh`,
`human-check.sh`, `evolve-ledger.py`, `token-report.py`, litopys plugin.

## Gotchas
- `--upgrade` restores vanilla files: re-check Path-binding blocks, `vulyk-handoff.md`, absence of `handoff.*` and
  `drone-acceptance.md` (ADAPTATION §6; `scripts/adaptation-check.sh` pending on `vulyk/evolve-2026-11-11`). Never wire handoff hooks
  even though `--upgrade` prints their `--wire` commands.
- No project `context_guard.py` - correct, the global one fires at user level.
- `git-guard` blocks the Queen's `git stash` / `checkout --` on a dirty tree: commit first or run it by hand.
- Gate split (ADR-029): see `cycle.md` and the Commands table (10 Full suite rows).
- CAPS in `vulyk-cycle.js` mirror agent maxTurns; change both together.
- Subagents here get empty results from `Glob` and directory-level `Grep`: give scouts file lists.
