---
description: Save session state to .claude/handoff/ before /clear or a restart
allowed-tools: Bash(bash scripts/state.sh:*), Bash(python:*), Read, Edit
---

<!-- ADAPTED FOR THIS PROJECT (см. docs/vulyk/ADAPTATION.md).
     VULYK ships .claude/hooks/handoff.{sh,py}. This machine already runs the identical
     guard globally — ~/.claude/hooks/context_guard.py, wired at user level for
     SessionStart/Stop/UserPromptSubmit/PreCompact/SessionEnd, tuned for the 1M window
     and writing to the SAME <project>/.claude/handoff/ directory. Installing the VULYK
     copy on top produced two dumps, two banners and two SessionStart injections per
     session, so the project-local pair was removed and this command drives the global
     guard instead. Re-check after a `/vulyk-update`: the upgrade restores those two files. -->

The build-state view, regenerated first so the handoff sits beside a current one rather than
whatever was last written:

!`bash scripts/state.sh`

The mechanical handoff skeleton has already been written to disk:

!`python "$HOME/.claude/hooks/context_guard.py" dump`

The first line of the output above is the absolute path to the handoff `.md` file; the second line is the context size at dump time. If the output is empty, the dump failed (usually: no Python 3 on PATH) — tell the user instead of guessing.

Your job is to turn that skeleton into a document the next session can resume from without re-asking anything:

1. Read the file at that path.
2. Rewrite the `## Summary` section from what actually happened in THIS session. Invent nothing — if something did not happen, do not write it. Structure:
   - **What we are doing and why** — the task in 2–4 sentences, including the goal, not just the actions.
   - **Where we stopped** — the current factual state: what is done and verified, what is written but not yet tested.
   - **Next step** — the concrete first action for the new session, not a general direction.
   - **Decisions made** — the chosen options and *why* (the most valuable part: this reasoning cannot be reconstructed later).
   - **Dead ends** — what was already tried and did not work, including false diagnoses and wrong assumptions that had to be discarded, so the next session does not step on the same rake.
   - **What you will need** — key files, commands, paths, environment variables, external resources.
3. In the YAML frontmatter, flip `enriched: false` to `enriched: true`.
4. Touch nothing except the `## Summary` section and the `enriched` field — everything else was assembled mechanically and must stay as-is.

Additionally, if the session produced state VULYK owns — an active spec under `docs/specs/`, story `status:` lines, a stale map slice — name it in `## Summary` under **Next step**, since `scripts/state.sh` output above is regenerated, not archived.

Finish with one short line to the user: the file path plus a reminder that `/clear` (or exit + restart) is now safe — the next session in this project picks the handoff up automatically.
