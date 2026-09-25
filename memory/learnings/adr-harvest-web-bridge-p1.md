---
type: adr-harvest
spec: web-bridge-p1
source: docs/specs/web-bridge-p1/plan.md (## Plan deltas, ## Tradeoffs, ## Assumptions A0-A18)
against: mmorpg-vault/decisions/ADR-189-Web-bridge-play.md (accepted 2026-09-24)
date: 2026-09-25
status: accepted 2026-09-25 — all 9 entries written into ADR-189 «Поправки 2026-09-25» (Claude по поручению Andrei); A17 confirmed
---

# ADR harvest - web-bridge-p1

## Proposed (amendments to ADR-189, or a new ADR) - proposed, owner accepts

1. **Sign guards on player-notice paths read "positive OR `VirtualChat::is()`".** Hooks/last_seen (`LastSeenService`, `LoginStreakService`, `ReturnDigestService`, `DailyTaskService`) and notices (`BeaconCaptureService:106`, `ReferralService:158`). Why recorded: dropping hooks breaks Ask 1 parity; parallel inbox call skips seam flag/mirror rules. Constrains every future `> 0` guard. Pointer: delta 2026-09-24 (story 05) + delta 2026-09-25 round 5; Tradeoffs (16); Contracts VirtualChat round 5. ADR-189 inv. 1 covers only Telegram sends. - proposed, owner accepts
2. **Webhook error behaviour kept per source:** `UpdatePipeline::run` rethrows for `telegram`, swallows only for `web`. Why: 500->200 on the webhook would break Ask 5. Pointer: delta 2026-09-24 (story 05). - proposed, owner accepts
3. **Actor capture lives at app `Request::send()` seam; `BridgeClient` is the bypass catcher.** Rejected: capture only in `BridgeClient` (literal ADR-189 §2) - Longman short-circuits `send()` under PHPUnit (`Request.php:698-702`). Cost: two layers, pinned by "one inbox row per background message" test. Pointer: Tradeoffs (1st bullet). - proposed, owner accepts
4. **Background edit/delete of a synthetic-range `message_id` never reaches Telegram, even for a linked chat; patches web copy + upserts one unread inbox item (A16).** Contradicts ADR-189 §5 ("edit* for linked not copied"). Consequence recorded: linked player follows a `/play`-started march on the site, not in Telegram. Rejected: id mapping, patching MarchingTaskHandler. Pointer: A16; Tradeoffs (11); delta round 3. Owner confirmation of A16 still open (round-5 review Major #2). - proposed, owner accepts
5. **Callback whitelist is message-scoped:** `data` must sit on the message its `message_id` names (A3 round 3). Narrows ADR-189 inv. 3 ("recent screens or inbox"). Why: review #5/#7/#11, Ask 6. Pointer: A3; story 12. - proposed, owner accepts
6. **Transient photos copied to `public/uploads/web/<sha1>` at record time, 168 h prune, `is_file` view guard (A18).** Constrains any future sender that unlinks after send. Rejected: deferring `MapService` unlink, `data:` URIs. Pointer: A18; Tradeoffs (14); delta round 4. Owner confirmation open. - proposed, owner accepts
7. **Inbox retention = prune-on-append (`inboxKeep`), not a cron** (A7) - explicit deviation from ADR-189 §5. Why: same effect without a `Config\Tasks` entry. Pointer: A7. - proposed, owner accepts
8. **"Linked player" for inbox mirror = `accounts.last_login_at IS NOT NULL`** (A5); ADR-189 leaves the term undefined. Why: ~700 bot-only players would pile unread rows. Pointer: A5 (approved 2026-09-24). - proposed, owner accepts
9. **Player texts about a flag-gated feature are worded true in both flag states** (not flag-aware variants). Why: tips are stored rows shown verbatim; `GuideCatalog` stays read-only. Pointer: Tradeoffs (10); story 10. Candidate for a general ADR (applies to any future flag rollout). - proposed, owner accepts

## Judged not ADR-worthy
- 24 h intent window (A17): infra config value inside ADR-189 §6's dedup table; lives in `Config\WebPlay`. Owner still confirms A17 as an assumption.
- Non-integer `chat_id` treated as chatless (#4): already covered by ADR-189 «Revisit when» (group/channel send).
- `web_play_state` row created by mirror id allocation (#8): harmless detail, this spec only.
- Session return target consumed by cabinet (story 08), history-edit promotion (story 09), bell in `/play` top bar (A10): UI details of this spec.
- `#3` ALTER sizing, round-6 text-only story 17 instead of reopen: one-time / process, not architecture.

## Reason missing
- none: every harvested delta carries its why. Open: A16/A17/A18 await owner confirmation (Andrei).

## Session learnings (for /vulyk-gc)
- Council seats returned empty on a 68-file spec: review seat empty rounds 1-3 (round 3 ESCALATE env), sonnet report empty in round 4 - turn cap too low for spec size; split specs or raise seat turn budget before round 1.
- `## Verification` lines must be the exact `## Commands` table cell (quiet variants), or gates/council cannot match them.
- Six council rounds cost ~4M tokens per 3-round circuit; for a text-only red ask, fix story + human-check beat reopening (delta 2026-09-25).
