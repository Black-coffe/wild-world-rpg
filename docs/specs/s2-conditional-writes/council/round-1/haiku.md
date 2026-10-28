<!-- seat: haiku · model: claude-fable-5-1 · round: 1 · head: 9c65d3f0 · pack: 189875507fef · attempt: 1 · recorded: 2026-10-28T21:41:45Z -->
COUNCIL: s2-conditional-writes · round 1 · seat haiku
MODEL: claude-fable-5-1
COURT: C:/laragon/www/mmorpg/.vulyk/court/s2-conditional-writes/round-1
VERDICT: RED
ASSUMED CONFIG: Preprod-testbot / public site wildworld.fun (Browser MCP = none; player door only via unauthenticated curl or autonomous webhook POST)
RAN: curl -sS -o /dev/null -w '%{http_code}' https://wildworld.fun/ (200); curl ... https://testbot.wildworld.fun/ (200); ls writable/secrets (empty); grep -n "^## \|S2" ROADMAP.md; sed -n '52,65p;293,400p' ROADMAP.md
PATH: Public site reachable (200) but exposes no game-action surface. Webhook door on testbot: host answers 200, but the secret header is not in COURT (writable/secrets empty) and testbot serves a deployed release, not the commit under review; no deploy from this seat. Telegram door: no test profile (Browser MCP none). Walked as far as HTTP liveness only.
ASK 1: N/A - F4 seed decrement ≥1 on planting - why: requires stale-snapshot race inside bot DB; no reachable client entry for this commit (no webhook secret, testbot not on this build)
ASK 2: N/A - F8 fight/rob under NamedLock - why: same; concurrent double-tap on NPC spawn not reachable without bot access to this build
ASK 3: N/A - F9 repair/drone/faction gold decrementIfAtLeast - why: same; needs DB-forced gold < price on a build serving this commit
ASK 4: N/A - F10 ruin/anomaly cooldown reserve before loot - why: same; cooldown race not reachable from outside
ASK 5: N/A - F14 pharmacy dose decrement before healing - why: same; dose=0 race not reachable
ASK 6: N/A - F16 event deltas via adjust()/relative writes - why: cron-driven handler; no external trigger or observation surface
ASK 7: N/A - F21 interrupt penalty only on in_work→interrupted - why: same; double-interrupt race not reachable
ASK 8: N/A - F22 VehicleActivationService rejects non-vehicle - why: internal service; no client entry point for this build
ASK 9: N/A - F23 death roulette under NamedLock - why: cron handler; no external trigger/observation
ASK 10: N/A - F24 robot handlers no status rewrite, relative resource grant - why: cron handler; no external trigger/observation
ASK 11: N/A - bulkSellResources checks increaseGold; double «Забрать всё» - why: bot action race; not reachable for this build
ASK 12: N/A - teleport charge + move in one transaction - why: bot action; teleport failure injection not reachable from outside
ASK 13: N/A - sellResource() untouched - why: no-change ask; nothing client-observable to verify
ASK 14: RED - verdicts/tech-notes/ROADMAP (S2 removed from §2/§4, row in §6) - run: grep -n "S2" ROADMAP.md saw: §2 line 57 still «1. **S2** — пакет условной записи…», §4 line 164 still «- **S2** · Tier 2 · Пакет условной записи…», no S2 row under §6 «Журнал» (line 293+). Tech-writing notes and /guide/GameSettings/WipeManifest verdicts not judged (would need source).
ASK 15: N/A - full PHPUnit/phpstan/exploit-poc regression - why: this seat never runs the test suite
UNASKED: none
BREACH: none
