<!-- seat: haiku · model: claude-fable-5-1 · round: 2 · head: db1afbce · pack: fa4a0b78582d · attempt: 1 · recorded: 2026-10-28T22:36:20Z · carried: round 2 · carried: round 3 -->
COUNCIL: s2-conditional-writes · round 2 · seat haiku
MODEL: claude-fable-5-1
COURT: C:/laragon/www/mmorpg/.vulyk/court/s2-conditional-writes/round-2
VERDICT: N/A
ASSUMED CONFIG: Локальное (Laragon) — the only configuration the court checkout could be judged in; preprod-testbot and prod run deployed code, not this commit
RAN: ls/cat of COURT root, brief.md and CLAUDE.vulyk.md Profile; `ls vendor` (missing), `ls .env` (missing), `php --version` (8.3.10); `curl -sS -o /dev/null -w '%{http_code}' https://wildworld.fun/` -> 200; grep of COURT/memory for the autonomous-webhook reference (absent)
PATH: Browser MCP is `none`, so no Telegram Web / admin walk. Public site door: https://wildworld.fun/ answers 200, but that is prod's deployed code, not this commit, and none of the asks surface on the website. Player door via autonomous POST to the testbot webhook: the secret header / URL reference is not inside COURT, the testbot runs its own deployed build (not the court commit), and the asks are race conditions (stale-snapshot double writes, busy locks) that need direct DB state manipulation to provoke — not reachable by a single client update. Running the court commit locally is impossible: no vendor/ (composer deps absent) and no .env, and writing into COURT is forbidden. Got as far as a live 200 from the public site; no ask-specific surface reached.
ASK 1: N/A - F4 seed decrement >= 1 on planting - why: Telegram-only flow needing a stale-snapshot DB race; no runnable build of this commit (no vendor/, no .env) and no browser/webhook access
ASK 2: N/A - F8 fight/rob under NamedLock, "NPC уже исчез." - why: Telegram-only flow needing a held lock / dead spawn in DB; not reachable from outside this commit
ASK 3: N/A - F9 gold decrementIfAtLeast before repair/contribution - why: Telegram-only flow needing gold-below-price race in DB; no runnable build
ASK 4: N/A - F10 ruin/anomaly cooldown reserved before loot - why: Telegram-only flow needing a second reservation inside the cooldown window; no runnable build
ASK 5: N/A - F14 pharmacy dose consumed before healing - why: Telegram-only flow needing dose=0 race in DB; no runnable build
ASK 6: N/A - F16 world-event deltas via adjust(), gold >= 0 - why: cron/Worker-driven, no client entry point; no runnable build
ASK 7: N/A - F21 interrupt penalty only on in_work -> interrupted transition - why: Telegram-only flow needing a double call on one task; no runnable build
ASK 8: N/A - F22 activate() returns false for non-vehicle - why: internal service contract with no client-observable surface beyond Telegram; no runnable build
ASK 9: N/A - F23 death roulette under NamedLock - why: cron/Worker handler, no client entry point; no runnable build
ASK 10: N/A - F24 robot handlers relative increments, no status rewrite - why: cron/Worker handlers, no client entry point; no runnable build
ASK 11: N/A - bulkSellResources checks increaseGold; concurrent "Забрать всё" once - why: Telegram-only flow needing concurrent requests against this commit; no runnable build, no webhook reference in COURT
ASK 12: N/A - teleport charge + move in one transaction - why: Telegram-only flow needing an injected move failure; no runnable build
ASK 13: N/A - sellResource() untouched - why: a non-change; nothing client-observable to exercise
ASK 14: N/A - no /guide, no council, no GameSettings/WipeManifest; tech-writing + ROADMAP updates - why: documentation/governance verdicts, no client-observable surface
ASK 15: N/A - full PHPUnit, phpstan L9, exploit-poc baseline - why: test-suite/static-analysis claims; this seat never runs the suite, and no vendor/ exists in COURT anyway
UNASKED: https://wildworld.fun/ serves 200 (prod, not this commit). Nothing else observed.
BREACH: none
