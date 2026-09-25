# Web bridge, Phase 1: the whole game on `/play` through the bot's own routes (plan)

**Tier:** 3 · **Spec slug:** `web-bridge-p1` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-189 «Игра на сайте через мост» (`mmorpg-vault/decisions/ADR-189-Web-bridge-play.md`, accepted 2026-09-24; amends ADR-188: a web-only character now has a `telegram_users` row) · ADR-188 (F1–F3) · ADR-148/168 (firehose, origin) · ADR-181 (dedup) · ADR-163 (throttle as infra) · ADR-062 · ADR-087 · ADR-024 · ADR-020 · ADR-127 · ADR-134
**Depends on:** `web-accounts-p0` shipped v0.51.672 (89a28cc5); study `docs/specs/web-first-client/report.md` §3 A, §5 B1–B7, §7; recon [recon.md](recon.md); UX reference `docs/specs/web-first-client/webgame-preview.html`

## Goal
A logged-in site player opens `/play` and plays the real game. Every tap, typed reply or command
becomes a synthetic Telegram update. Its `from.id`/`chat.id` come from the server session, and it
runs through the **same** pipeline as the webhook body after secret, dedup and the community gate:
firehose, origin, E6/E8 hooks, `last_seen` and the dispatchers. Sends to the acting player are
captured into a web screen and never reach Telegram. An edit replaces its screen. The reply
keyboard becomes a bottom dock, and the last screens stay visible as a short history. A
web-only character owns a `telegram_users` row with an id in a reserved virtual range. Two guard
layers keep any send to that range, including Worker/cron/spark sends, away from Telegram, and
the message lands in the web inbox instead. Linked players keep their Telegram messages and also
get an inbox copy, shown by a bell with a counter. Everything is gated by `web.play_enabled`
(default off). The same pass fixes the Phase-0 text tail. `/web`, the tip and `/guide` say
«выйди из другого входа и введи код» and describe web play. The header and the cabinet get
«Играть».

**Tier 3, why:** the spec is cross-cutting. It touches the transport (`Request`, Guzzle client,
probe), the webhook controller, Worker delivery, schema, provisioning, site
controllers/views/CSS and bot texts. It does not migrate 200k+ LOC, because the handlers are
reused rather than rewritten. It has 7 stories. The 🟠 classification is covered by ADR-189.

## Assumptions
Confirm or veto at the approval stop:
- **A0. Coverage (drone-coverage, 2026-09-24).** No ask absent; 9 carried. Partials: Ask 3 (photo shown only when the file is under `public/` — A12; caption always full), Ask 4 (bell only in the `/play` top bar — A10; inbox copy narrowed by A5/A6), Ask 9 (header «Играть» has no own flag-off state — clicking it lands on the `/play` stub page, which is the flag-off state). Work without an ask: A3 callback whitelist (ADR-189 §6, supports Ask 6). Owner confirms or vetoes A3, A5, A6, A10 at the approval stop.
- **A1. Virtual id = `−(VIRTUAL_BASE + accounts.id)`, tested by range only**
  (`VirtualChat::is()`, never by sign). Group `community.chat_id` is legitimately negative
  (`CommunityModerationService:170,406`), so "never to a negative id" becomes "never to the
  virtual range" (ADR-189 §3–4). The candidate `VIRTUAL_BASE` is 2^52, which keeps |id| < 2^53.
  The claim that Telegram ids fit in 52 significant bits is **unverified**. Story 01 checks it
  against the Bot API docs before it fixes the constant.
- **A2. The virtual row is created in two places.** `CharacterProvisioningService` creates it for
  a new web character. A migration creates it for P0 characters with `telegram_user_id IS NULL`,
  and in the same migration their NULL `character_tasks`/`explored_cells` keys are backfilled.
  After that the P0 task creators (`$user['id']`, P0 A8) and the `ExploredCells` FK are
  consistent for web acts. `/start` for a web-only character is therefore the ordinary "existing
  character" branch.
- **A3. The callback whitelist was added by ADR-189 §6 and is not in the brief** (owner to
  approve). A web callback runs only if its exact `callback_data` sits on a button of a stored
  screen or inbox item of that character. Text and commands stay free-form, as in Telegram.
  Reason: a browser can post any string, while in Telegram a player can only press what the bot
  sent. This supports Ask 6, «игрок может управлять только своим персонажем».
  *Round 3 (story 12):* the button must sit on the very message whose `message_id` the callback
  carries.
- **A4. "Background" means a send to anyone who is not the actor of the current interactive
  request** (ADR-189 §5). In Worker, cron and spark everything is background. Replies to the
  actor are never copied. A linked player acting on `/play` gets no Telegram message for that
  act. `edit*`/`delete*` are dropped for a virtual chat and not copied for a linked one.
  *Round 3:* this holds for real-range message ids only. Synthetic-range ids follow A16.
- **A5. "Linked player" for the inbox copy means a bot player who has used the site**
  (`accounts.last_login_at IS NOT NULL`). Without this, about 700 bot-only players would
  accumulate unread rows. ADR-189 does not define the term. *Owner to confirm.*
- **A6. Inbox writes happen only while `web.play_enabled` is on.** The guard drops virtual-range
  sends whatever the flag says (Ask 2).
- **A7. Inbox retention is a prune on append** (keep the newest `inboxKeep` per character).
  ADR-189 §5 says "a cron deletes old read rows". The effect is the same without a new
  `Config\Tasks` entry. *Deviation from the ADR, for the architect or owner to veto.*
- **A8. The web-play numbers are infrastructure, so ADR-024 does not apply.** They live in
  `Config\WebPlay`: history 10 screens, inbox keep 200, poll 30 s (server minimum 10 s), acts
  60/min per account, inbox reads 12/min per account, text max 4096, synthetic `message_id`
  from 1 000 000 000. Nothing in the game balance changes.
- **A9. The throttle reuses `AccountThrottleFilter` with a per-account argument** (ADR-189 §6
  names `accountThrottle`). Its P0 limit of 10 POST/min per IP would stall normal play, so the
  `play`/`inbox` arguments get their own `Config\WebPlay` budgets.
- **A10. The bell sits in the `/play` top bar.** The site header carries a plain «Играть» link.
  `/play` shows the stub while the flag is off.
- **A11. The texts describe web play conditionally** («если в шапке сайта есть «Играть»…»),
  because the tip and the guide ship while the flag is still off.
- **A12. A photo is shown only if its file is under `public/` or the send carried an http(s)
  URL.** Otherwise the screen shows the caption alone, which is complete under media-off.
  *Amended in round 3 (manual review #13):* this includes a photo sent as a Telegram `file_id`
  string. The seam cannot resolve a `file_id` to a file, so that screen shows the caption alone.
  A `src` is never protocol-relative (story 13).
  *Amended in round 4 (story 14):* a photo whose file sits under a transient prefix
  (`uploads/tmp/`) is copied at record time (A18). A site-relative `src` is rendered only when its
  file exists; otherwise the caption alone.
- **A13. First visit with no stored state dispatches a synthetic `/start`** to get the dock and a
  first screen. If Q7 finds side effects for an existing character, the bootstrap becomes
  `/menu` (plan delta).
- **A14. Out of scope:** Web Push/SSE/PWA, native screens (Phase 2), HUD, B7 automated smoke,
  the P0 review minors, and the fix that keeps virtual rows out of broadcast "reachable in
  Telegram" statistics (ADR-189 «−», open risk). Ask 12 is the Queen's preprod walk.
- **A15. F1–F3 stay as they are** (brief Answer 4). Only the texts change.
- **A16. A synthetic-range `message_id` exists only on the web (round 3, story 11; *owner to
  confirm*).** A background `edit*`/`delete*` whose `message_id` is ≥ `firstMessageId` never goes
  to Telegram, whether the chat is virtual or linked. An edit is applied to that character's
  web copy in place (screen or history). With the flag on, it is also upserted as one unread
  inbox item per `message_id`. The caller gets `ok:true`. Consequence: a linked player who
  starts a march on `/play` follows its progress on the site (the bell, the inbox, the screen)
  and not in Telegram. Any new `send*` the caller makes still goes to Telegram and gets the
  mirror (Ask 4).
- **A17. `web_play_intents` keeps a 24 h dedup window (round 3, story 12).** Older rows are pruned
  on insert, and the window is measured on the DB clock. The value is infrastructure
  (`Config\WebPlay::intentRetentionHours`, A8). A double-submit or a network retry arrives
  within seconds, so 24 h is ample.
- **A18. Transient photos are copied for the web and kept 7 days (round 4, story 14; *owner to
  confirm*).** A recorded photo whose file is under `uploads/tmp/` (the map today) is copied to
  `public/uploads/web/<sha1>.<ext>` before the sender deletes it. Copies older than
  `Config\WebPlay::photoKeepHours` = 168 are pruned on the next copy. That value is
  infrastructure (A8). An older history or inbox item then shows its caption alone (A12), which
  Ask 3 allows («экран понятен без картинки»). If the deploy rsync wipes `public/uploads/web/`,
  the same caption-only fallback applies after a release.

### Recon questions for `drone-scout` (answer before the named wave)
- **Q2 (W2, 04/05).** What does the group filter at `BotController.php:77` test? List every place
  in `app/` that classifies a chat as a group by id **sign** instead of `getType()`. A handler
  like that would treat a virtual id as a group.
- **Q4 (W2, 04).** Does `MediaSender` cache Telegram `file_id`s and resend them instead of the
  file? Do photo files live under `public/` or `writable/`?
- **Q5 (W2, 05).** Is Longman MySQL (`enableMySql`) on in `BotController`? Which tests build,
  subclass or spy on `BotController`?
- **Q6 (W3, 07).** Is CSRF `regenerate` on? What are the token field and header names? Is
  `play/*` under the global CSRF filter? Can `AccountThrottleFilter` take route arguments today?
- **Q7 (W3, 07).** What does `StartCommand` do for an existing character (re-greet, referral,
  onboarding reset)?
- **Q8 (W1).** Is the newest migration before `2026-12-11-100001`?
- **Q9 (W2, 04).** List the site, cabinet and admin code that treats a non-NULL
  `characters.telegram_user_id` or a `telegram_users` join as "has Telegram". A virtual row
  would show up there as linked.
- **Q10 (W4, 08).** Where does each successful login door redirect: `AccountAuth::attempt`, the
  `AccountOAuth` callback, `TelegramLogin` login, `AccountLink` (code), `AccountRegister`
  (register/character)? Story 08 assumes they all land on `/account` (`AccountCabinet::index`),
  which is its single return-to consumer. List any door that lands elsewhere. Also: how does
  `Play::gate()` order its login and flag checks, and does `site/play_stub` read any session
  data?
- **Q11 (W7, 14).** List every photo sender in `app/` that deletes its file after the send
  (`unlink(` near `MediaSender`/`sendPhoto`) and the directory of that file. Story 14 assumes
  `public/uploads/tmp/` is the only one. Is `public/uploads/web/` already covered by `.gitignore`,
  and does the deploy rsync (`deploy/`) keep or delete untracked files under `public/uploads/`?
  Does `site/_play/inbox.php` render `photo_url`?
- **Q12 (W7, 15).** How does `Play` compute the stub's `can_register` (which setting or check)?
  Does `AccountCabinet::index` already pass that condition, or the character state, to
  `site/account_cabinet`? Quote the current no-character lock line and the «Привязать персонажа
  из бота» notice with their line numbers.

**Recon answers (Queen + Explore, 2026-09-24, `git grep`):**
- Q2: the group gate tests `chat.type` (`BotController.php:76,273,282-306`; `TelegramRateLimitFilter.php:361-370`). Nothing in `app/` classifies a group by id sign; sign checks are "valid positive id" guards (`SilentNotificationPolicy.php:58`, `ReferralService.php:158`, `TelegramChatResolver.php:78` returns null for non-positive ids — story 04 must route virtual ids before this resolver drops them).
- Q4: MediaSender caches no `file_id`; it reopens the file stream each send (`MediaSender.php:183-209`). Photos live under `public/` (`FCPATH . $imageRel` in `DefensiveBuildingHandler.php:158`, `SeasonalRecipePreviewAction.php:149`; `base_url('uploads/telegram/…')` in `BuildListAction.php:113`) — A12 covers them.
- Q5: `enableMySql` is never called. Tests subclassing `BotController` and overriding `dispatchToTelegram()`: `BotControllerChatTypeGateTest`, `BotControllerChannelEnvelopeTest`, `BotControllerCommunityWiringTest`, `BotControllerModerationWiringTest`, `BotControllerUpdateDedupTest` (tests/unit/Controllers/Telegram/) — story 05 keeps that seam working.
- Q6: CSRF cookie mode, `csrf_test_name`, header `X-CSRF-TOKEN`, `regenerate=true`, global except `telegram/webhook` (`Security.php:18-83`, `Filters.php:88`) — `/play/*` is covered; with regenerate on, the JS must take the fresh token from every response. `AccountThrottleFilter::before($request, $arguments)` accepts but ignores arguments (`:28-49`) — story 07 adds reading them.
- Q7: `/start` for an existing character: no referral, no reset; sends «🧭 Меню ниже.» + reply keyboard, `ensureChainAssigned()` (idempotent), character card (`StartCommand.php:265-291`). `/menu` (`MenuCommand.php:20-34`) only sends the menu + `markAlreadyFresh`. A13 keeps `/start` (it yields dock + card).
- Q8: newest migration `2026-12-10-100020_SeedWebLinkTip` — the `2026-12-11-*` prefixes are free.
- Q9: `AccountSession.php:93-96,182-192` and `AccountService.php:69,90-92,225` read non-NULL `characters.telegram_user_id` as "has Telegram"; the cabinet uses identities (`AccountCabinet.php:124-135`), not the column. → plan delta below.

The column-type/signedness check and the 52-bit claim move into story 01, and the count of
direct `Longman\…\Request` imports into story 04 (ADR-189 «не проверено»).

## Stories

**Wave 1**
- `web-bridge-p1-01-virtual-identity` (tracer): migrations (`web_inbox`, `web_play_state`,
  `web_play_intents`, `web.play_enabled`, firehose ENUM `web`, virtual-row backfill, signed ids
  if needed), WipeManifest, `Config\WebPlay`, `VirtualChat`, `VirtualIdentityService`,
  `DeliveryContext`, `PlayerActionLogger` source override, `CharacterProvisioningService` creates
  the virtual row.
- `web-bridge-p1-02-render-kit`: `TelegramMarkupRenderer` (Markdown/HTML → safe HTML) and the
  `/play` components in `wildworld-ui.css` + `ui-kit.html` + a `?v=` bump.
- `web-bridge-p1-03-truthful-texts`: the `/web` code text, a tip UPDATE migration, the `/guide`
  `web` section, «Играть» in the header, and a cabinet entry with a lock state.

**Wave 2**
- `web-bridge-p1-04-delivery-capture`: the `Request::send()` seam (actor capture, virtual →
  inbox, linked mirror), `WebScreenStore`, `WebInboxService`, `BridgeClient`, and the
  virtual-range guard middleware in the base client (`TelegramDeliveryProbe` getter + always
  guarded, `TelegramBridge`).
- `web-bridge-p1-05-update-pipeline`: the webhook body is extracted into `UpdatePipeline::run($update, $source)`,
  plus `SyntheticUpdateFactory`. `BotController` behaviour does not change.
- `web-bridge-p1-06-play-views`: the `/play` shell, the stub, the state and inbox fragments, and
  `wildworld-play.js` (PRG fallback, bell polling).

**Wave 3**
- `web-bridge-p1-07-play-endpoint`: `Play` controller, `WebActService` (session identity,
  whitelist, intent dedup, Probe → BridgeClient → pipeline → `finally` restore), throttle
  arguments, routes.

**Wave 4 (council round 1 fixes)**
- `web-bridge-p1-08-play-entry-flag-first` (Ask 9): `Play::gate()` checks the flag before the
  login, so the anonymous flag-off visitor gets the stub. With the flag on, an anonymous visitor
  gets a one-shot, whitelisted `/play` return target that the cabinet consumes after login.

**Wave 5 (council round 2 fixes)**
- `web-bridge-p1-09-history-edit-becomes-screen` (Ask 3): an edit whose target sits in history
  makes the edited message the current screen; the old screen moves to history and the stale copy
  leaves its history entry. Files: `WebScreenStore`, `WebDelivery`, `WebDeliveryTest`.
- `web-bridge-p1-10-texts-true-in-both-flag-states` (Ask 8): `/web`, the `/guide` `web` section and
  the tip (new UPDATE migration `100011`) are reworded so they hold with the flag off and on: no
  «откроют / пока заглушка», «Играть» in the header named as the way in. Files:
  `WebLinkCodeAction`, `GuideCatalog`, the migration, `WebPlayTextsTest`.

**Wave 6 (round 3 repair: manual review, owner decision 2026-09-24)**. All three stories run
`model: opus`, and their files are disjoint.
- `web-bridge-p1-11-background-edit-of-web-message` (Asks 2, 3, 4, 5; review #1, #6): a
  background edit or delete of a synthetic-range id never reaches Telegram (A16). An edit patches
  the web copy in place and upserts one unread inbox item. `applyCapture` and the new
  `patchMessage` write under one concurrency guard. Files: `WebDelivery`, `WebScreenStore`,
  `WebInboxService`, `WebDeliveryTest`, `WebInboxServiceTest`, new `WebScreenStoreTest`.
- `web-bridge-p1-12-act-guard-message-scoped` (Ask 6; review #5, #7, #11): a callback is accepted
  only when its `data` is on the message its `message_id` names. The whitelist test covers
  another character's message. Intents are pruned on insert with a 24 h window (A17). Files:
  `WebActService`, `Config\WebPlay`, `WebActServiceTest`.
- `web-bridge-p1-13-play-client-error-path` (Asks 1, 3, 6; review #2, #9, #10): JSON 400/429
  bodies are handled in place with the fresh token. PRG is reached only on a network or parse
  failure, after a token refresh. A failed bootstrap still renders `/play` with the alert. A
  photo `src` is never protocol-relative. Files: `Play`, `AccountThrottleFilter`,
  `_play/state.php`, `wildworld-play.js`, `PlayControllerTest`, `PlayViewsTest`.

**Wave 7 (council round 4 fixes)**. One story per red ask; files are disjoint.
- `web-bridge-p1-14-transient-photo-kept-for-web` (Ask 3): a photo whose file is under
  `uploads/tmp/` (the map) is copied to `public/uploads/web/` at record time, before the sender's
  `unlink`, and pruned after `photoKeepHours` (A18). A site-relative `src` renders only when the
  file exists. Files: `WebDelivery`, `Config\WebPlay`, `_play/state.php`, `_play/inbox.php` (only if
  it renders photos), `.gitignore` (only if needed), `WebDeliveryTest`, `PlayViewsTest`.
- `web-bridge-p1-15-cabinet-lock-names-a-real-path` (Ask 9): the cabinet's no-character lock and
  notice stop telling a logged-in player to link a bot character by code (F1 refuses it). They
  offer `/account/character` when registration is open and «выйди из другого входа и введи код»
  for a bot player. Files: `account_cabinet.php`, `AccountCabinet` (only to pass the registration
  condition), `PlayControllerTest`.

**Ask coverage:** 1→05,07,13 · 2→01,04,07,11 · 3→02,04,06,09,11,13,14 · 4→04,06,07,11 · 5→04,05,11 ·
6→01,05,07,12,13 · 7→01,03,07 · 8→03,10 · 9→03,06,08,15 · 10→02,06,14 · 11→01 · 12→07 + integration
gate (Queen Tier-3).

**Verdicts (already Asks):**
- guide: yes, section `web` extended (Ask 8).
- tip: yes, an UPDATE of the existing `SeedWebLinkTip` row, category «настройки» (Ask 8).
- onboarding step: no. Web play is an opt-in second client, and its entries (header, cabinet,
  `/web`, settings button) are always visible.
- media-off: the caption is rendered in full (Ask 3).
- balance: no new numbers.
- WipeManifest (Ask 11): `web_inbox` PLAYER_DATA; `web_play_state` PLAYER_DATA;
  `web_play_intents` TRANSIENT. Virtual rows fall under `telegram_users` IDENTITY_RESET.
- Round 3: none of the three repair stories adds a player-visible feature, table or column, so the
  guide, tip, onboarding and WipeManifest verdicts are unchanged. Tip: no, it is a fix to web
  play delivery.
- Round 4: no new table or column. The `public/uploads/web/` copies are files, not DB rows; a wipe
  leaves them to the age prune. Guide and tip: no, both stories fix existing web-play surfaces
  (the map image, the cabinet lock text). Onboarding: unchanged.

## Contracts

**Migrations.** The worker runs `ls app/Database/Migrations | tail` first and, if a prefix is
taken, shifts to the next free one and reports it.
- 01:
  - `2026-12-11-100001_CreateWebPlayTables.php`
  - `2026-12-11-100002_WebPlayEnabledSetting.php` (pattern
    `2026-12-10-100003_WebOpenRegistrationSetting.php`: bool, default 0, category world,
    rationale/effect/above/below)
  - `2026-12-11-100003_PlayerActionLogWebSource.php`
  - `2026-12-11-100004_BackfillVirtualTelegramUsers.php`
  - `2026-12-11-100005_SignedTelegramIdColumns.php` (**only if the story-01 column check finds
    an UNSIGNED or too-narrow column on the path**)
- 03: `2026-12-11-100010_UpdateWebLinkTipForWebPlay.php` (UPDATE by the `title_en` of
  `SeedWebLinkTip`, idempotent; `down()` restores the old text)
- 10: `2026-12-11-100011_WebPlayTipTrueInBothFlagStates.php` (UPDATE by `title_en='WebLinkCode'`,
  idempotent; `down()` restores `UpdateWebLinkTipForWebPlay::NEW_CONTENT`)
- Round 3 (11–13): no migrations.
- Round 4 (14–15): no migrations.

**Schema (01)**
- `web_play_state`: `character_id` PK FK→characters CASCADE, `next_message_id` BIGINT NOT NULL
  DEFAULT 1000000000, `screen` JSON (list<Msg>), `history` JSON (list<list<Msg>>, newest first),
  `dock` JSON (list<list<string>>), `input` JSON NULL (`{placeholder, reply_to}`), `updated_at`.
- `web_inbox`: `id` PK, `character_id` FK CASCADE, `message_id` BIGINT, `source`
  ENUM('virtual','mirror'), `payload` JSON (Msg), `created_at`, `read_at` NULL;
  INDEX(character_id, read_at); UNIQUE(character_id, message_id).
- `web_play_intents`: `id` BIGINT PK AI, `account_id` INT UNSIGNED, `intent_id` VARCHAR(64),
  `created_at`; UNIQUE(account_id, intent_id). The synthetic `update_id` is `−id`. Rows are never
  written to `telegram_updates_seen`.
- `Msg` = `array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}`.

**Services**
- `App\Services\Web\VirtualChat` (01, pure):
  - `const VIRTUAL_BASE` (value per A1)
  - `static is(int $chatId): bool`
  - `static idForAccount(int $accountId): int`
- `App\Services\Web\VirtualIdentityService` (01):
  - `ensureForAccount(int $accountId, string $firstName): int`: returns the `telegram_users.id`.
    It is idempotent, reuses an existing row with that `telegram_id`, and never creates an
    `account_identities` row.
  - `identityForCharacter(int $characterId): ?array{telegram_user_id:int, telegram_id:int, first_name:string, username:?string, language_code:?string, virtual:bool}`
- `CharacterProvisioningService::create(string $name, ?int $telegramUserId, ?int $chatId, ?int $accountId): int`
  (01): the signature is unchanged. When `$telegramUserId === null && $accountId !== null`, it
  calls `ensureForAccount` first and continues with the virtual row id and chat id. The bot path
  is untouched.
- `App\Services\Web\DeliveryContext` (01): a static holder with `setActor(?int $chatId)`,
  `actor(): ?int` and `reset()`. `UpdatePipeline` sets it and `WebDelivery` reads it.
- `PlayerActionLogger::begin(array $update, ?string $source = null)` (01): a non-null source
  overrides the derived one. `web` is in `VALID_SOURCES`.
- `Config\WebPlay` (01): `historySize=10`, `inboxKeep=200`, `inboxPollSeconds=30`,
  `inboxPollMinSeconds=10`, `actsPerMinute=60`, `inboxReadsPerMinute=12`, `textMaxLength=4096`.
  Round 3 (12) adds `intentRetentionHours=24` (A17). Round 4 (14) adds
  `transientPhotoPrefixes=['uploads/tmp/']`, `photoDir='uploads/web/'` and `photoKeepHours=168`
  (A18).
- `App\Services\Web\TelegramMarkupRenderer` (02): `static toHtml(?string $text, ?string $parseMode): string`.
  It escapes first and allows only `b i u s code pre a[href=http(s)]` and `<br>`. It never
  throws.
- `App\Services\Web\WebDelivery` (04): the seam's state.
  - `beginCapture(int $actorChatId, int $characterId): void`
  - `endCapture(): array{sent:list<Msg>, edited:array<int,Msg>, deleted:list<int>, alert:?string, dock:?list, dock_removed:bool, input:?array}`:
    idempotent, called from `finally`.
  - `isCapturing(): bool`
  - `reset(): void`
  - `App\Services\Telegram\Request::send()` calls it after `KeyboardNormalizer` and before
    `parent::send()`, with three cases:
    - actor chat while capturing: record the send and return a synthetic `ServerResponse`
      `{ok:true,result:{message_id,…}}`;
    - virtual chat: write to the inbox (or drop an edit/delete) and return a synthetic ok;
    - otherwise: `parent::send()`, then the linked-mirror hook.
  - Round 3 (11), which runs before the virtual/otherwise cases: a background `edit*`/`delete*`
    with `message_id ≥ firstMessageId` to a chat that resolves to a character never reaches
    `parent::send()`. An edit calls `WebScreenStore::patchMessage` and, with the flag on,
    `WebInboxService::upsertEdit`. The seam returns a synthetic `ok:true` (A16).
  - Round 4 (14): whenever the seam builds a `Msg` from a photo whose local file is under a
    `transientPhotoPrefixes` dir, it copies the file to `public/<photoDir><sha1>.<ext>` (atomic,
    content-deduplicated) and stores the site-relative `/uploads/web/…` path as `photo_url`. Each
    new copy prunes copies older than `photoKeepHours`. Other photos keep their URL.
- `App\Services\Web\BridgeClient` (04): `new BridgeClient(ClientInterface $delegate, int $actorChatId)`.
  Actor-chat or chat-less requests that bypassed the seam become caption-only captures and are
  not sent. Every other request goes to the delegate.
- The virtual-range guard middleware (04) sits in every client `TelegramDeliveryProbe::install()`
  builds. It is installed always, with the probe middleware only when the firehose is on.
  `TelegramDeliveryProbe::client(): ?ClientInterface` is the getter ADR-189 asks for.
  `TelegramBridge::ensure()` calls `install()`. A guard hit drops the request and logs `error`.
- `App\Services\Web\WebScreenStore` (04):
  - `nextMessageId(int $characterId): int` (atomic)
  - `state(int $characterId): array{screen, history, dock, input}`
  - `applyCapture(int $characterId, array $capture): void` (09: an edit of a history message
    promotes it to the current screen; an edit on the current screen stays in place)
  - `findMessage(int $characterId, int $messageId): ?Msg`
  - `callbackAllowed(int $characterId, string $data): bool` (screens + inbox)
  - Round 3 (11): `patchMessage(int $characterId, int $messageId, Msg $msg): bool` patches the
    message in place in the screen or history and never promotes it. It returns false when the
    message is not there. `applyCapture` and `patchMessage` do their read-modify-write under one
    guard, so concurrent writers of one character lose no update.
- `App\Services\Web\WebInboxService` (04):
  - `append(int $characterId, array $msg, string $source): int`
  - `unreadCount(int $characterId): int`
  - `latest(int $characterId, int $limit): list<array{msg, created_at, read}>`
  - `markAllRead(int $characterId): void`
  - `findMessage(int $characterId, int $messageId): ?Msg`
  - Round 3 (11): `upsertEdit(int $characterId, int $messageId, Msg $msg, string $source): void`
    keeps one row per `(character_id, message_id)`. It replaces the payload and sets
    `read_at = NULL`, and inserts the row when there is none.
- `App\Services\Telegram\UpdatePipeline` (05):
  - `static telegram(): Telegram` (built as `BotController` does)
  - `run(array $update, string $source): void`: `'telegram'|'web'`. It never throws. It sets
    `DeliveryContext` actor, firehose, origin and `last_seen`, and resets them in `finally`.
- `App\Services\Web\SyntheticUpdateFactory` (05):
  - `callback(array $identity, Msg $message, string $data, int $updateId): array`
  - `message(array $identity, string $text, ?Msg $replyTo, int $updateId): array`
  - Both set `chat.type='private'`, and `from`/`chat` come from `$identity` only.
- `App\Services\Web\WebActService` (07): `act(int $accountId, int $characterId, array $intent): array{state, alert:?string, unread:int}`,
  where `$intent = {intent_id, kind: callback|text|command, data, message_id?}`.
  Round 3 (12): a callback's `data` must be on a button of the `Msg` that its `message_id`
  resolves to for that character. Each new intent row prunes the rows older than
  `intentRetentionHours`.
- `App\Services\Web\AccountSession` (08): a one-shot return target. It stores only the exact path
  `/play`, and `AccountCabinet::index` is its only reader. Story 08 reports the method names on
  its INTERFACES line.

**Transport order in one `/play` act (ADR-189 §2, invariant 4):**
1. `TelegramDeliveryProbe::install()`.
2. `$c = TelegramDeliveryProbe::client()`.
3. `WebDelivery::beginCapture()`.
4. `Request::setClient(new BridgeClient($c, $actorChat))`.
5. `UpdatePipeline::run($update, 'web')`.
6. `finally`: `Request::setClient($c)` and `WebDelivery::endCapture()`.

Worker, cron and CLI never install `BridgeClient`.

**Routes (07)**

Every route requires the session character and checks `web.play_enabled` server-side. Story 08
checks the flag first. While the flag is off, every visitor gets the stub or 403, whether logged
in or not. The login redirect applies only while the flag is on.

| Method + path | Controller::method | Response |
|---|---|---|
| GET `/play` | `Play::index` | `site/play`, or `site/play_stub` (flag off, logged in or not / no character); logged out with flag on → `/account/login` + return target `/play` (08); a failed bootstrap still renders `site/play` with the alert (13) |
| POST `/play/act` | `Play::act` | with `Accept: application/json` → 200 `{html, unread, alert, csrf}`; 400 reject `{html, unread, alert, csrf}`; 429 throttle `{alert, csrf}` (13); otherwise 303 → `/play` |
| GET `/play/inbox` | `Play::inbox` | JSON `{unread, html, csrf}` (`csrf` added in 13) |
| POST `/play/inbox/read` | `Play::markRead` | JSON `{unread:0}` |

Filters: `accountThrottle:play` on both POSTs and `accountThrottle:inbox` on GET inbox. CSRF is
the global filter.

**View data (06 renders, 07 passes)**
- `site/play`: `{state, unread, poll_seconds, character_name}`
- `site/_play/state`: `{state, alert}`
- `site/_play/inbox`: `{items}`
- `site/play_stub`: `{reason: 'flag_off'|'no_character', can_register:bool}`
- `site/account_cabinet` (15): its no-character lock reads the same registration condition as the
  stub's `can_register`. Story 15 reports the variable name on its INTERFACES line.

The views call `TelegramMarkupRenderer::toHtml()` on `text`/`caption`. The forms post `kind`,
`data`, `message_id`, `intent_id` (random per rendered form) and the CSRF field. No telegram or
chat id ever appears in HTML or JSON (ADR-189 invariant 6). Round 4 (14): a site-relative
`photo_url` renders `<img>` only when `is_file(FCPATH . path)`; otherwise the caption alone.

**CSS classes (02):** story 02 adopts the existing `wildworld-ui.css` prefix and reports its class
names on its INTERFACES line. The Queen pastes them here before wave 2.

## Tradeoffs
- **Chosen: the capture buffer is filled at the app `Request::send()` seam, with `BridgeClient`
  and the guard middleware under it.** This is ADR-189's two layers, refined so the actor
  capture also lives in layer (a). **Rejected: capture only inside `BridgeClient`**, which is
  the literal reading of ADR-189 §2. Longman short-circuits `send()` under PHPUnit before any
  client (`Request.php:698-702`), so capture, edit→replace and inbox routing could only be
  proven on testbot. The seam runs in tests and sees the photo stream URI before Longman closes
  it. `BridgeClient` keeps the ADR's ordering and catches callsites that bypass the app
  `Request`. The cost is two layers to keep consistent, pinned by a test: exactly one inbox row
  per background message.
- **Chosen: extract the webhook body into `UpdatePipeline`.** **Rejected: calling
  `processUpdate()` straight from `/play`.** That skips the firehose, origin, E6/E8 and
  `last_seen`, leaves the ADR-188 «−» open, and the two paths would drift. The cost is Ask 5
  risk in `BotController`, bounded by its existing tests staying unchanged.
- **Chosen: the virtual row is created at provisioning and by a backfill migration** (ADR-189
  §3). **Rejected: lazy creation on the first `/play`.** A lazy row would leave cron
  notifications for web-only characters skipped until their first visit, and it puts a write on
  the read path.
- **Chosen: server-rendered screens with PRG and thin JS.** **Rejected: client-side rendering
  from JSON.** That means two Markdown renderers, and it breaks the "every view works without
  JS" site rule.
- **Chosen (08): the return target is stored in the session and consumed only by the cabinet.**
  **Rejected: a `?return=` parameter threaded through every login door.** That would touch five
  controllers and three auth flows (password, OAuth, Telegram widget), and it opens a redirect
  surface. The cost: if some door does not land on `/account` (Q10), the player there lands
  where they land today and still finds «Играть» in the header and the cabinet.
- **Chosen (09): an edit of a history message is promoted to the current screen**, the same rule
  the inbox already follows. **Rejected: making history read-only** (no live buttons in history,
  `callbackAllowed` limited to the current screen and inbox). That stops taps on past screens, but
  a stored-id edit (`last_map_message_id` fallbacks) would still patch a dimmed entry, so Ask 3
  would stay red. It also touches the views and the whitelist. The cost: a tap on an old screen
  pulls it forward, which is what Telegram does visually when you scroll up and tap.
- **Chosen (10): wording that is true in both flag states.** **Rejected: flag-aware texts**
  (`WebLinkCodeAction` and `GuideCatalog` read `web.play_enabled` and pick a variant). The tip is
  a stored row that `TipService` shows verbatim, so it would still need neutral wording or a
  `TipService` change. `GuideCatalog` would lose its "read-only catalog" shape, and every text
  would need two variants and two tests. The cost: while the flag is off, the texts cannot say
  "not yet"; the `/play` stub and the cabinet lock line say that instead.
- **Chosen (11): the seam absorbs every background edit of a synthetic-range id into the web copy
  and the inbox (A16).** It fixes the march and also any edit-first caller that review did not
  grep (review "Not verified" item 2), with no handler edits. **Rejected: id mapping.** The first
  background edit would send one real Telegram message and store a synthetic→real id map, and
  later edits would be rewritten to the real id. That keeps a linked player's march progress in
  Telegram, but it needs a new table or column (WipeManifest, retention). It also still leaves the
  web-only player with nothing unless the web path is built anyway. **Rejected too: patching
  `MarchingTaskHandler`/`MarchAction`** to store the fallback id. That covers one caller, and the
  virtual-chat `ok` still hides the edit from a web-only player. The cost of the choice: a linked
  player follows a march started on `/play` on the site, not in Telegram.
- **Chosen (14): the seam copies a transient photo into `public/uploads/web/` at record time**,
  with a content-hash name, an age prune and an `is_file` guard in the view. It covers every
  sender that deletes its file after the send, with no handler edits, the same shape as (11).
  **Rejected: stop `MapService` from deleting its temp file while a web capture runs** (or defer
  the `unlink`). That fixes one caller, and it moves the cleanup of `uploads/tmp/` into the web
  code anyway: those files would then need their own prune. **Rejected too: storing the image as
  a `data:` URI in the `Msg`.** A map image in every history and inbox JSON row would multiply the
  row size. The cost of the choice: a copy directory with its own retention (A18), and old
  entries fall back to caption-only.
- **Chosen (15): the cabinet lock offers the two paths that work** (`/account/character`, and
  log out then enter the `/web` code). **Rejected: let `link()` accept a code while another
  account is signed in.** That is the merge F1 forbids (A15, brief Answer 4).

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
plus `bash scripts/wave-check.sh docs/specs/web-bridge-p1` before each wave. Close-story suites
run sequentially on the shared `wildworld_tests`. After wave 3:
- **Queen Tier-2 (Ask 10):** `/play`, the stub, the cabinet and the header at 375/768/1440, with
  no horizontal scroll and a clean console.
- **Tier-3 on preprod-testbot with the flag on (Ask 12):** the linked player
  (`telegram_user_id=25`) and a web-only character each walk the character, map, gathering,
  crafting and inbox screens. The linked player then taps the map in Telegram, which covers Ask
  5 and R5 (`editOrSend` fallback after synthetic ids). Nginx and app logs show no Telegram
  request with a virtual-range chat id and no guard `error` line (Ask 2).

After wave 6 (round 3), the Tier-2 and Tier-3 walks add:
- **Tier-2:** a stale button (made stale from a second tab) and a throttle hit each show the
  alert in place. Neither shows the 403 page.
- **Tier-3:** both characters start a march from `/play`. The web-only character's bell and inbox
  show one item that updates as the march advances. The linked player gets no new Telegram
  message per step. Photo screens render on preprod (see the round-3 note in plan deltas).

After wave 7 (round 4), the walks add:
- **Tier-3:** both characters open «Карта» from the dock on `/play`. The map image shows, and the
  console has no 404. A reload of `/play` still shows it in the screen or the history.
- **Tier-2:** a logged-in account with no character opens `/account`. The play block offers
  `/account/character` (when registration is open) and the log-out-then-code instruction, and no
  «привяжи персонажа из бота» text.

## Descoped

*(empty)*

## Plan deltas

- 2026-09-24 · trigger: story 05 NEEDS_CONTEXT (twice) — E6/E8 hooks and the last_seen stamp reject non-positive telegram ids (`LastSeenService:45,125`, `LoginStreakService:46`, `ReturnDigestService:40`, `DailyTaskService:50`). Decision: those four files join story 05 `## Files`; guard becomes "positive OR `VirtualChat::is()`". Webhook error behaviour unchanged (rethrow for `telegram`, swallow only for `web`). Rejected: dropping hooks/stamp for web play (breaks Ask 1 parity: streak/daily/digest), 500→200 on the webhook (Ask 5).

- 2026-09-24 · recon Q9: a virtual `telegram_users` row would make `AccountSession`/`AccountService` treat a web-only character as Telegram-linked (session `tg_user_id`, `ensureForTelegram`). Decision: `app/Services/Web/AccountSession.php` and `app/Services/Web/AccountService.php` join story 01 `## Files` with one acceptance line (virtual range = no Telegram). No other story names them. Rejected: leaving it to story 07 (wave 3) — wave-1 backfill already creates the rows.

- 2026-09-24 · trigger: council round 1 RED on Ask 9 (seat opus: `Play::gate()` checks the login before the flag, so an anonymous flag-off visitor gets `/account/login` instead of the stub, and nothing returns them to `/play` after login; seat haiku: prod header has no `/play`, because the reviewed commit is not deployed there). Decision: fix story `web-bridge-p1-08` in wave 4, which puts the flag first and adds a session return target consumed by the cabinet. Haiku's finding is environmental (it walked prod), so no story addresses it. Rejected: amending story 07 in place (it is `done`, and a new story keeps the scope gate and the review slot honest).

- 2026-09-24 · trigger: council round 2 RED on Asks 3 and 8 (seat opus; sonnet GREEN, haiku N/A environmental, review ABSENT). Ask 3: `WebScreenStore::applyCapture` patches an edit of a history message in place, so a tap on a past screen answered by an edit leaves the main screen unchanged. Ask 8: the story-03 texts are static and say «когда откроют / пока там заглушка», so they turn false the moment the owner flips `web.play_enabled` without a deploy. Decision: wave 5, one story per ask — `web-bridge-p1-09` (promote a history edit to the screen) and `web-bridge-p1-10` (wording true in both flag states, new tip migration `100011`). Files are disjoint. Rejected: amending stories 04/03 in place (both `done`); read-only history (see Tradeoffs); flag-aware texts (see Tradeoffs). Opus's UNASKED notes (no header bell, no backlog while the flag is off, Telegram-only flows for web-only players) are covered by A6/A10/A14 or out of scope and get no story.

- 2026-09-24 · trigger: council round 3 ESCALATE (env; the review seat was empty in all rounds), owner reopen, and the manual lead-review `council/manual-review.md` (PASS, findings 1–13). Owner decision (Andrei, brief «After escalation»): fix before ship. Decision: wave 6 with three disjoint `model: opus` stories. `web-bridge-p1-11` takes #1 and #6: both write `web_play_state`, so the concurrency guard has to cover the new background patch. `web-bridge-p1-12` takes #5, #7 and #11, all in `WebActService`. `web-bridge-p1-13` takes #2, #9 and #10, all on `Play`, the throttle and the client. New assumptions A16 and A17 go to the owner. A3, A4 and A12 are amended in place. Rejected: one story per finding (nine stories, and #6/#1 plus #9/#2 would collide on `WebScreenStore` and `Play.php`); a single repair story (it mixes the transport with the client JS and serializes three independent fixes).
  - **#3 dropped** (owner): prod `player_action_log` = 68 741 rows / 20 MB and `action_log` = 9 592 rows, so the `100005` ALTER takes seconds and needs no scheduling.
  - **#4 accepted:** a non-integer `chat_id` (`@channel`) is treated as chatless by `BridgeClient`. No game code sends to a channel username today, and ADR-189 «Revisit when» already requires a range check when a group or channel send appears.
  - **#8 accepted, dependency documented here:** a mirror copy for a linked player allocates its id through `WebScreenStore::ensureRow`, which creates an empty `web_play_state` row for a player who never opened `/play`. `bootstrap()` keys on an empty screen and history, not on whether the row exists, so this is harmless. The row costs one small row per site-using linked player (A5).
  - **#12 recorded on story 01 `## Files`:** `tests/database/CharacterProvisioningServiceTest.php` is the test that goes with story 01's provisioning change. Story 01's glob `tests/database/*Provisioning*Test.php` already matches it. It is named explicitly here for the Law 3 trace.
  - **#13:** A12 is amended to name `file_id` photos as caption-only.
  - **Note (Queen local Tier-2, 2026-09-24):** the bridge works end to end for a web-only character. Register → character → `/play` → daily-task screens, history, dock. No horizontal scroll at 375/768/1440, and the console is clean. Photo screens fail locally only because the single-threaded `php spark serve` cannot `fopen` its own URL. This is to be proved on the preprod Tier-3 (integration gate, after wave 6). No story.

- 2026-09-25 · trigger: council round 4 RED on Asks 3 and 9 (seat opus; haiku N/A environmental, sonnet report empty, review PASS). Ask 3: the map photo is a temp file under `public/uploads/tmp/` that `MapService:176` deletes right after the send, so the stored `photo_url` 404s on `/play` (also a console error, Ask 10). Ask 9: the cabinet's no-character lock and notice tell a logged-in player to link a bot character by `/web` code, which `link()` refuses (F1), and they never offer `/account/character`. Decision: wave 7, one story per ask, disjoint files — `web-bridge-p1-14` (copy transient photos at record time, age prune, `is_file` view guard; new A18 to the owner, A12 amended) and `web-bridge-p1-15` (cabinet lock names the two paths that work). Recon Q11/Q12 answer before dispatch. Rejected: amending stories 04/13 and 03/08 in place (all `done`); the rejected fixes for 14 and 15 are in Tradeoffs. Opus's UNASKED notes (stale mirror copies of Telegram-side edits, no bell outside `/play`, the stub repeating «скоро») are A16/A10 territory or cosmetic and get no story. The round-4 review (PASS) findings are not asks and get no story here; its Major #1 (A16/A17 confirmation) and Minor #14 (tech-writing notes) are open items for the owner and `drone-docs` before ship.

**Approved:** Andrei, 2026-09-24 (A0–A15 as written, incl. A3, A5, A6, A10)
**Briefed:** <written by scripts/cycle.sh briefed - alternative to **Approved:**>
**Branch:** vulyk/web-bridge-p1
**Checked:** <written by scripts/human-check.sh>
**Council:** RED round 1, 2026-09-24, at 57e1a0dd, pack bcab1c9c706e - red: 9
**Council:** RED round 2, 2026-09-24, at 8dbc8746, pack bd05f7270b7a - red: 3,8
**Council:** ESCALATE round 3, 2026-09-24, at cbcb710a, pack f9d606eb6f6c
**Council:** RED round 4, 2026-09-24, at ce0c2be8, pack be09bd1085fa - red: 3,9
**Council:** RED round 5, 2026-09-25, at 151fc51b, pack 8c08891b6ac9 - red: 4
**Shipped:** <written by scripts/ship-check.sh --record>

## Needs a human
- reason: env · round 3 · 2026-09-24
- review: docs/specs/web-bridge-p1/council/round-3/review.attempt-1.md
- review: docs/specs/web-bridge-p1/council/round-3/review.attempt-2.md
- seats: docs/specs/web-bridge-p1/council/round-3/
