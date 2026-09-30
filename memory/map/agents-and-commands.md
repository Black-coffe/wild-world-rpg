# Scout report: agents and commands (framework 0.24.0)
last-verified: 2026-09-30

## Purpose
Every `.claude/agents/*.md` caste, `.claude/commands/vulyk-*.md` entry point, `.claude/hooks/*` and the one
workflow, as they stand at 0.24.0. `council-sonnet` and `drone-acceptance` are **gone**; `acceptance-log.sh`
survives only as `ship-check.sh`'s fallback (`scripts.md`). Project deviations: `docs/vulyk/ADAPTATION.md` §6.

## Agents (model / tools / maxTurns from frontmatter)
- **council-haiku** (`opus`!, `Bash,Read,mcp__chrome-devtools__*,mcp__claude-in-chrome__*`, 60) - black-box seat,
  named for its angle, not its model; walks the Profile's *Client path*, reads no source. Tier 3-4 only, and only
  when Client path is filled (ours is; Browser MCP = none, so it walks curl / webhook POST).
- **council-opus** (`opus`, medium, `Bash,Read,Grep,Glob`, 60) - intent/edge-case seat, blind at Tier 3-4. Both
  council seats: `disallowedTools: Write,Edit`, `omitClaudeMd`; dispatch names slug/round/court/report path only.
- **cycle-clerk** (`sonnet`, low, `Bash`, 5) - runs ONE `cycle.sh`/`journal.sh` command, returns the last stdout
  line. The Workflow driver's only shell.
- **lead-review** (`opus`, high, `Read,Grep,Glob,Bash`, 60) - reviewer seat (sole seat at Tier 1-2). First line
  `VERDICT: PASS|BLOCK`; only `[ask N]`/`[regression]`-tagged findings hold a BLOCK. Tier 4: two dispatches.
- **drone-coverage** (`opus`, medium, `Read`, 5) - brief.md + plan.md only, reports by ask number.
- **drone-scout** (`sonnet`, low, `Read,Grep,Glob`, 15) - recon; format of every `memory/map/*.md`.
- **drone-docs** (`sonnet`, low, `Read,Write,Edit,Grep,Glob`, 40) - LOCAL "Project path binding": notes go to
  `mmorpg-vault/tech-writing/`, not `docs/wiki/`.
- **worker-code / worker-test** (`sonnet`, medium, full edit + Bash, 90) - one story, closes it with
  `cycle.sh close-story --stamp`. Tier 3-4 only.
- **queen-planner** (`opus`, high, `Read,Write,Grep,Glob`, 40) - Tier 3-4 plans; does NOT cut repair stories.
- **lead-architect** (`opus`, high, `Read,Grep,Glob,Write`, 30) - LOCAL "Project path binding": ADRs go to
  `C:\Projects\mmorpg-vault\decisions\ADR-NNN-<slug>.md`, not `docs/adr/`.
- **librarian** (`opus`, low, `Read,Write,Edit,Glob`, 25) - sole writer merging `memory/learnings/`; ADR harvest.
- **Project-only, not VULYK castes** (bodies not re-read): `community-antispoiler` (ADR-176, `/community`) and
  8 `redkollegiya-*` (designer, editor-council, game-designer, linker, lore-keeper, mmorpg-player, uiux, writer).

## Commands
- **/vulyk-plan** - step 0 deliverable check (study work -> `report.md`, no council), tier, brief (`redact.sh`),
  recon, grill (Tier 2-4), plan (queen-planner at Tier 3-4), stories, `wave-check`+`trace-check`, `drone-coverage`
  (Tier 3-4). Tier 1 -> `briefed --mode mini-brief` + build; Tier 2-4 stop for approval (`--go` opts out).
- **/vulyk-build** - Tier 1-2 solo: Queen loops `advance` -> `build:<W>` -> `dispatch:review` -> `advance --ingest`.
  Tier 3-4 hive: Workflow `scriptPath: ".claude/workflows/vulyk-cycle.js"` (never `name:`), fallback loop without
  Workflow (`advance --stamp S --claim`, `release` on every exit). Terminal `green` (0.22): ONE `AskUserQuestion`
  «<slug>: council GREEN, round <n>. Выпускаем?»; yes -> Skill `vulyk-ship`; no / `claude -p` -> recommend it.
  Also `escalated`, `paused`, `manual:<ids>` (`manual-done`).
- **/vulyk-review** - `advance --claim`, dispatch the seats `next` names, `advance --ingest`, `release`; same green
  question. RED -> repair story built via `/vulyk-build`, never here.
- **/vulyk-ship** - `ship-check.sh` -> release commit -> local merge -> prints publish command -> `--record` ->
  `drone-docs`/`librarian` -> next-brief draft. Never pushes (у нас тег ставим сами, см. CLAUDE.vulyk.md).
- **/vulyk-evolve** - step 0 ledger (`scripts/evolve-ledger.py`; pending branch = stop), step 1 harvest +
  token-report + corrections count (0.24: `defect-intake.sh --lexicon` -> `litopys corrections --lexicon`, needs
  litopys >= 0.4.0, else one line), steps 2-4 council/anomaly check-in and admission, step 5 worktree
  `vulyk/evolve-<date>`, step 6 human gate. Applies nothing to the default branch.
- **/vulyk-gc** - `librarian` pass, then a commit one-liner that **refuses** when `CONSOLIDATED.md` lost more than
  half its entries or bytes (0.23).
- **/vulyk-bootstrap** - interview, Profile, `top-model.sh --apply`, roster, map. Offered by the SessionStart brief
  while the Profile has `<fill in`; decline = Profile row `| Bootstrap | declined <date> |`.
- **/vulyk-pause|resume** wrap `cycle.sh pause|resume`; resume relaunches `/vulyk-build` fresh.
  **/vulyk-status, /vulyk-map, /vulyk-update** - dashboard, scout-batch map, release upgrade (bodies not re-read).
- **/vulyk-handoff** - LOCAL: drives the global `~/.claude/hooks/context_guard.py`, not `handoff.{sh,py}`.

## Hooks (wired in `.claude/settings.json`)
SessionStart: `session-start-brief.sh` (brief, bootstrap offer, `maintenance due: gc/evolve/map`),
`vulyk-update-check.sh`, inline PowerShell git-fetch (PC<->laptop), `community-pull.sh` (ADR-176),
`top-model-brief.sh`, `defects-inject.sh reset`. SessionEnd: `anomaly-scan.sh`. PreCompact: `context-guard.sh`.
PostToolUse: `skill-usage-counter.sh` (Skill), two inline PowerShell Write|Edit hooks (site-drafts ->
redkollegiya; migrations -> WipeManifest). UserPromptSubmit: `defect-intake.sh`. PreToolUse: `defects-inject.sh`.

## Dependencies
inbound: settings.json, the constitution. outbound: `scripts/cycle.sh`, `journal.sh`, `ship-check.sh`,
`top-model.sh`, `evolve-ledger.py`, `token-report.py`, litopys plugin.

## Gotchas
- `--upgrade` restores vanilla files: re-check Path-binding blocks, `vulyk-handoff.md` (`grep -L context_guard`),
  and that `handoff.*`, `example-api.md`, `drone-acceptance.md` stay absent (ADAPTATION §6).
- `.claude/hooks/context_guard.py` does NOT exist in the project, which is correct: the global one fires at user level.
- CAPS in `vulyk-cycle.js` mirror agent maxTurns; change both together.
- Subagents here get empty results from `Glob` and directory-level `Grep`: give scouts file lists.
