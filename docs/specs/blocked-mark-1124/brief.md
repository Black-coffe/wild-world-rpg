# Cron marks players who blocked the bot instead of logging ERROR (brief)
**Date:** 2026-11-24 · **Owner:** Andrei

## Request (verbatim)
> Orchestrator's proposal: «1. В mmorpg одна спека: тест из Follow-up плюс вторая половина B16, чтобы крон помечал
> заблокированных вместо ERROR. После неё можно делать push на preprod. 2. Потом `s1-atomicity` … 3. Утром прочитать
> строку бота AI_NEWS … 4. Перевести на тонкий VULYK Recall и fibi-next.»
>
> Owner: «Давай по порядку»

Context (not the owner's words): ROADMAP B16 «Рассылки шлют заблокировавшим бота (168 ERROR за 25.10) — не слать,
помечать недоставляемых». The first half shipped as `blocked-clear-1123` (merge `7995be06`): a real private-chat
Telegram update clears `telegram_users.blocked_at`. Its `plan.md` `## Follow-up` holds the owner's condition
before any push («Влить, тест до push»): a direct test of `UpdatePipeline::isPrivateChatSignOfLife` on a group
message in `tests/database/UpdatePipelineTest.php`.

## Answers
<empty for now>

## Asks
1. `tests/database/UpdatePipelineTest.php` has a direct test: `UpdatePipeline::isPrivateChatSignOfLife` returns
   false for a group message from a real Telegram id, and the file runs green.
2. When a cron message to a player fails because the player blocked the bot (or deleted the account), the player
   gets the "blocked the bot" mark (`telegram_users.blocked_at`), the same way `BroadcastService` does it.
3. That failure is no longer logged as ERROR; one blocked player gives at most one log line per send, not two
   (the photo-to-text fallback does not retry a blocked player).
4. Other failures (network, bad request, too many requests, anything not "blocked") stay logged as ERROR as today
   and do not mark anyone.
5. A web-only (virtual) chat id is never marked, even though sends to it answer "Forbidden".
6. Database tests (schema built from migrations) prove asks 2–5.
7. Project gates: the tech-writing note of every touched class is updated in `mmorpg-vault/tech-writing/`; no
   hardcoded balance number; no new table or player column (else `WipeManifest`); verdicts recorded: `/guide` — no
   (no new player mechanic), tips — no (invisible plumbing); no new player surface.
