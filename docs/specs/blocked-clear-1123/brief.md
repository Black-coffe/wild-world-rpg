# A returning player gets messages again (brief)
**Date:** 2026-11-23 · **Owner:** Andrei

## Request (verbatim)
> Вопрос: «Первая спека тонкого VULYK в mmorpg. …» Ответ владельца: «Вернувшийся игрок снова получает
> (Рекомендую)» — «Половина B16: когда от игрока приходит настоящее сообщение из Telegram, пометка «заблокировал
> бота» снимается. Тапы в вебе её не снимают. Одна точка в коде и тесты на БД: именно такие тесты понадобятся для
> антидюпа. Исправляет настоящую потерю для игроков. Минус: кроновые ERROR остаются до второй спеки, спека
> маленькая.»

Scope fixed with the owner in the orchestrator session (relayed, 2026-11-23): only clearing the blocked mark when a
real Telegram update arrives from the player. Web activity must not clear it. Cron senders, their ERROR logging and
the "Forbidden" classification stay untouched: that half of B16 is a later spec.

## Answers
- 2026-11-23, approval of the plan: «Одобрить с проверками Fable (Рекомендую)». The checks, relayed by the
  orchestrator from its cross-check with Fable (not the owner's words): (1) clear only on updates from the player's
  private chat with the bot, not group messages, group `my_chat_member` or inline queries, or confirm in code that
  the bot only receives private-chat updates; (2) check every caller of `UpdatePipeline::run()`: a web caller must
  not reach it as Telegram, and the web test goes through the real web entry point; (3) if the pipeline itself sets
  `blocked_at` on `kicked`, a test shows an unmarked player plus `kicked` ends up marked.
- 2026-11-24, merge question: «Влить, тест до push (Рекомендую)»; vault notes: «Закоммитить локально (Рекомендую)».

## Asks
1. When a real Telegram update arrives from a player whose character is marked "blocked the bot", the mark is
   cleared, so the cron senders that skip blocked players (events, PvE, AntiCamp, node digest, daily tip) reach
   them again.
2. A tap or any action in the web client (`/play`, virtual chat ids) does not clear the mark.
3. The clearing happens in one place in the code, not scattered over commands.
4. Database tests (real `wildworld_tests` schema built from migrations) prove: a Telegram update clears the mark;
   a web action leaves it; a player who was never marked is not written to needlessly.
5. Cron senders, their ERROR logging and the "Forbidden" classification are not changed (diff does not touch them).
6. A `my_chat_member` update where the player blocks the bot (status `kicked`) does not clear the mark.
7. Project gates: the tech-writing note of every touched service is updated in `mmorpg-vault/tech-writing/`;
   no hardcoded balance number; no new table or player column (else `WipeManifest`); verdicts recorded:
   `/guide` — no (no new player mechanic), tips — no (invisible plumbing); no new player surface, so no
   discoverability/onboarding change.
8. The mark is cleared only by an update from the player's private chat with the bot: a group message, a group
   `my_chat_member` or an inline query from a marked player leaves it (or the code shows the bot gets no such
   update to the pipeline, written in the plan).
9. No caller of `UpdatePipeline::run()` can reach it as Telegram while serving the web; the web test goes through
   the real web entry point (`WebActService`), not a hand-made `SOURCE_WEB` call.
