# ADR harvest — `multibase-picker` (shipped v0.51.669)

Source: `docs/specs/multibase-picker/plan.md` `## Plan deltas`, `## Открытые хвосты`, `## Descoped`.
Cross-checked against `C:\Projects\mmorpg-vault\decisions\ADR-187-Multibase-screen-base-scope-resolver.md`.

## Verdict

ADR-187 was updated in-place as part of this spec's own Assumptions/Descoped (`## Ask 6`), and its
"Обновление 2026-09-15" section already quotes and absorbs nearly every architectural decision this
spec's deltas record: the `_b<id>` callback-suffix codec, the 64-byte guard, `resolveForBase()`,
legacy-button behaviour, the tower-branch change to "first covered base by id", the robot
launch-base rule, and the beacon "any base" carve-out. Harvesting a second ADR for any of these
would be a duplicate of an ADR this same build already wrote.

One item earns a proposal below. Everything else is either already in ADR-187 or fails the
three-part test.

## Proposed

### ADR-P1 — Tower coverage is scoped to its own base's cell, not shared across a character's bases

**Status:** proposed
**From delta:** `## Plan deltas`, 2026-09-15, "Вышка покрывает только свою базу — проверено на проде":

> Триггер: воркер 01 сообщил смену поведения — Вышка считается для базы, только если
> `character_buildings.map_cell_id` = клетке этой активной базы (раньше — любая Вышка персонажа).
> Прод, read-only SELECT: 12 Вышек, 12 — на активной базе владельца, `NULL`/чужая клетка — 0;
> регрессии покрытия у живых игроков нет. Решение: принять.

**Context (verbatim above).** Before this build, `CommunicationTowerCoverageService` treated any
Communication Tower belonging to the character as covering any of that character's bases. The
per-base coverage rewrite (`coverageByBase()`) narrowed this silently to "a base's coverage comes
only from a Tower standing on that base's own cell" — a behaviour change validated against prod
data before being accepted, not merely an implementation detail of the rewrite.

**Decision.** A Communication Tower covers only the base it physically stands on
(`character_buildings.map_cell_id` must equal the base's `claimed_cells` cell). It never
contributes coverage to a different base owned by the same character.

**Why this earns an ADR and ADR-187 doesn't already say it.** ADR-187's update item 5 and
invariant 6 document *that* coverage is now computed per base in `id` order (the `first()` removal)
but do not state the narrower fact that a Tower's coverage is bound to its own cell. A future
feature (e.g., a "network relay" item, or a "shared coverage" perk) would have to know this
boundary exists before deciding whether to cross it — that is exactly the re-decision test. The
reason is recorded (prod SELECT, 12/12 towers matched, 0 regressions), satisfying the evidence bar.

**Options:** none recorded — the delta states the verified/chosen shape only (it does not weigh
"any tower covers any base" as a live alternative, only names it as the prior, now-replaced
behaviour).

**Consequences:** not recorded beyond what the delta states — coverage math changed for the ~0
mismatched towers found on prod (none), so the change was validated to be zero-regression at ship
time. Longer-term consequences (e.g., whether this makes a future "shared coverage" building
desirable) are not discussed in the delta.

**Revisit when:** not recorded in the delta.

## Considered and rejected

- **`_b<id>` callback-suffix convention as a project-wide pattern** (handler-side parsing, router
  not stripping, 64-byte guard). This is the actual subject of ADR-187's 2026-09-15 update
  (`BaseCallbackSuffix`, invariants 2–5). Nothing in the delta text generalizes it beyond base
  callbacks to a project-wide convention — inventing that generalization would be adding an option
  the delta never weighed. Covered by ADR-187; no new ADR.
- **"A Communication Tower covers only its own base"** — see ADR-P1 above; the one candidate that
  passed.
- **Legacy `resolve()` tower branch now picks the first *covered* base by `id`, not the first
  *active* one** — already written into ADR-187's own decision table (`| tower | ... | первая по
  `id` база, которую накрывает её собственная Вышка (с 2026-09-15...) |`). Duplicate, no new ADR.
- **Robot launch-base rule** (stand on base, or own Tower covers it and it has its own Workshop) —
  already ADR-187 update item 6, verbatim. Duplicate, no new ADR.
- **`phpstan-baseline.neon` — single edit after wave 2, no two workers touching the same file** —
  fails test 1 and 2: it is bookkeeping about how this spec sequenced its own stories, not an
  invariant that constrains code that does not exist yet. It also restates an existing standing
  rule (`feedback_never_two_workers_on_one_file` in `claude-memory/`), not a new one.
  Descope/process note, not architecture.
- **Story 08 owns the upgrade-confirmation formatter + the one baseline cleanup** — same reason:
  sequencing/ownership bookkeeping for this build, not a lasting invariant.
- **`## Открытые хвосты` entries (R1/R2/R3 minors, UNASKED items)** — every one of these is recorded
  as "known gap, not fixed in this spec" (e.g., robot launch tie-break on `id` when two covered
  bases both have a Workshop; bare "back" buttons losing the base suffix; base-screen buttons
  without a suffix). They are bug backlog, not decisions: nothing was chosen, a fix was deferred.
  Test 1 fails — there is no decision to re-decide, only a future fix to eventually do.
- **fopen test-double (`BuildingCardBaseScopeTest::$fopenShimActive`) mentioned in the R3 Major 1
  note** — a test-infrastructure technique already in use, not a new architectural decision; fails
  test 2 (constrains no product code). Better suited to a `memory/learnings/` entry than an ADR,
  and this task's protocol reserves `memory/` writes to `librarian` — not applied here.

## Not found in this spec's deltas

The task brief listed two additional test-infrastructure candidates to consider —
`BuildingModel::$byNameEnCache` process-wide static cache leaking across tests, and MyISAM used to
prove `orderBy` behaviour. Neither appears anywhere in `docs/specs/multibase-picker/plan.md`'s
`## Plan deltas`, `## Descoped`, or `## Открытые хвосты`. Per this task's scope (`plan.md` deltas +
ADR-187 only, nothing else), I did not search story files or the diff to substantiate them, and I
am not fabricating decisions that are not in the read source. If these are real, they likely belong
to a different spec's memory (`angela-second-base-bugs`, which this one depends on) — worth the
dispatcher checking there directly rather than assuming they are misattributed.
