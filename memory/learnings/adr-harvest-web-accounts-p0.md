# ADR harvest: web-accounts-p0 (proposed only; the owner accepts)

Harvested 2026-09-24 from these sources: `docs/specs/web-accounts-p0/plan.md` (Assumptions, Plan deltas, Descoped) and `council/round-1/review.md`. Checked against ADR-188 (accepted). No ADR file was edited.

## 1. A2 merges reversed to refusal (F1, story 09)
- Decision: codes and the widget never merge accounts. If the visitor is logged into another account, the code is refused before it is spent. `mergeInto` is removed.
- Evidence: plan.md:28 (A2 superseded), :42 (F1, rejected option: an amendment that authorises merge), :124; review.md:23-25 (#2).
- ADR-188 already says refuse: invariant 4 (ADR-188:83-84) and Revisit (:118, «слияние — отдельный ADR; сейчас это отказ»). The build fell back into line with the ADR. It did not change it.
- Action: **nothing** (covered by ADR-188). Optional one-line amendment for the owner: invariant 4 names only "привязка Telegram". Widen it to "любая привязка (код, widget, OAuth) к чужому аккаунту — отказ". The code path is currently covered only by the Revisit line.

## 2. Session-bound widget link (single-use `tg_link_nonce`)
- Decision: a logged-in widget callback can attach an identity only if it carries a nonce that the cabinet minted into this session. A bare callback can at most log in to the payload's own account.
- Evidence: plan.md:83, :156; review.md:14-19 (critical #1, the attack is written up there).
- It meets all three tests. Future link surfaces (the Phase 1 web client, any new provider callback) would have to decide this again. It constrains code that does not exist yet. Its reason is recorded.
- Action: **amend ADR-188**: add invariant 10, "every attach-identity callback is bound to the session by a single-use nonce/state; without it, no change to a logged-in account". Also extend the «Безопасность» bullet (ADR-188:103-105). Options: F1 recorded one rejected alternative (plan.md:42). Nothing else was weighed.

## 3. Reset page gives one body for every outcome (F3, story 10)
- Decision: `/account/reset` gives the same response for unknown, sent and mail-failed. It always shows the honest caveat plus the bot-code alternative. The timing gap of the synchronous SMTP send is accepted.
- Evidence: plan.md:44, :84, :153, :200 (Descoped), :204 (delta 2026-09-24); review.md:27-29 (#3, enumeration oracle).
- It constrains future account-existence responses (registration, a future email verification, magic-link).
- Action: **amend ADR-188**: add invariant 11, "no response of an account endpoint reveals whether an email/identity exists". Add to Revisit: "a mail queue (Phase 1) closes the timing gap". The reason for that Revisit line is recorded at plan.md:200.

## 4. One character per account, guard in provisioning (F2, story 11)
- Decision: the bot never attaches a second character. If the Telegram identity's account already owns a web character, `/start` creates a fresh account with no identity for the new character.
- Evidence: plan.md:27 (A1), :43 (F2, rejected option: the bot adopts the web character), :189 (tradeoff); review.md:31-34 (#4).
- ADR-188 does not state one-character-per-account at all.
- Action: **amend ADR-188**: add the invariant "≤1 character per account in P0; enforced in `CharacterProvisioningService`". Add to Revisit: "Phase 1 or multi-character". Consequences: plan.md:42-43 records the cost. The orphan account still holds the email/identity until a merge ADR exists.

## 5. Unlink Telegram ≠ shadow account (`accountForTelegramLogin`, story 09)
- Evidence: plan.md:30 (A3), :125; review.md:36-39 (#5).
- Action: **nothing**. This is a P0 implementation detail of A3, and nothing new needs to be decided later.

## 6. Verification must be an exact `## Commands` cell
- Evidence: plan.md:208 (delta 2026-09-23). The rejected alternative was editing the constitution's Commands table.
- Not an ADR: it is framework process, not product architecture.
- Action: **VULYK learning** for the next GC or `/vulyk-evolve`. `close-story` accepts only byte-exact Commands cells. Per-file phpunit and curl lines fail it. curl exits 0 on HTTP 500, which makes it vacuous as a gate. Stories should name full suite + phpstan + migration lint and iterate on per-file tests off the record. A related recurring hazard (plan.md:204, :206): a council seat left `php -S` serving, and workers ran DB tests at the same time on `wildworld_tests`. Both caused false-red suites.

## Needs a human decision (not harvested; no decision recorded)
- review #8: widget login creates an account while `web.open_registration=false`. This conflicts with ADR-188 invariant 8. Either amend the ADR with an explicit exemption or fix the code. The owner or lead-architect knows which.
- review #7: pre-account-takeover through an unverified email (A4). It is not recorded as an accepted risk in ADR-188. It is needed before the beta is lifted.
- review #6 (reset token in a URL on a page with Statable), #9 (code posted into non-private chats), #14 (password change without the current password): the plan says they wait for a Queen or owner call (plan.md:209). No outcome is recorded.
