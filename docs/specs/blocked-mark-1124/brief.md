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
- 2026-11-24, plan questions: Вопрос 1: «Два общих отправщика (Рекомендую)». Вопрос 2: «Да, только в личке
  (Рекомендую)». Вопрос 3: «Нет, только помечать (Рекомендую)».
  (Q1 = only `BaseTaskHandler` and `BaseObjectHandler` safe senders; Q2 = a `my_chat_member` `kicked` update from
  the player's private chat sets the mark; Q3 = senders do not skip marked players, they only mark.)
- 2026-11-24, plan approval: «Одобрить с добавками Fable (Рекомендую)». The additions (relayed, not the owner's
  words) are listed in plan.md `## Approval additions`; asks 4, 6 and 2 below follow them.

## Asks
1. `tests/database/UpdatePipelineTest.php` has a direct test: `UpdatePipeline::isPrivateChatSignOfLife` returns
   false for a group message from a real Telegram id, and the file runs green.
2. When a cron message sent through the common senders (`BaseTaskHandler::safeSendMessage` / `safeSendPhoto`
   and their copies in `BaseObjectHandler`) fails because the player blocked the bot (or deleted the account), the
   player gets the "blocked the bot" mark (`telegram_users.blocked_at`), the same way `BroadcastService` does it.
3. That failure is no longer logged as ERROR (a WARNING instead); one blocked player gives at most one log line per
   send, not two (the photo-to-text fallback does not retry a blocked player).
4. Other failures (network, exception, too many requests, other refusals such as "chat not found" or "bot can't
   initiate conversation", anything not "blocked") stay logged as today and do not mark anyone; a non-block photo
   failure still falls back to text; an already-marked player's mark date is not rewritten; a failed mark write is
   logged, not swallowed.
5. A web-only (virtual) chat id or a group id (negative chat id) is never marked, even when the send answers
   "Forbidden".
6. Database tests (schema built from migrations) prove asks 2–5 and 8.
7. Project gates: the tech-writing note of every touched class is updated in `mmorpg-vault/tech-writing/`; no
   hardcoded balance number; no new table or player column (else `WipeManifest`); verdicts recorded: `/guide` — no
   (no new player mechanic), tips — no (invisible plumbing); no new player surface.
8. A `my_chat_member` update from the player's private chat with new status `kicked` sets the mark; the same
   update from a group does not. Tests: unmarked + `kicked` → marked; marked + `kicked` → stays marked (date not
   rewritten); `kicked` then a private message or `member` → cleared.
9. Only the two common senders change; the ~18 handlers that send by other routes, `BroadcastService` and the
   classifier's word list are not touched.
10. The senders do not skip players who already have the mark: they still try to send, and only mark on failure.
