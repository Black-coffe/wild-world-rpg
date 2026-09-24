# Web accounts, Phase 0: player identity outside Telegram (plan)

**Tier:** 3 · **Spec slug:** `web-accounts-p0` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-188 «Web-аккаунты: корень игрока вне Telegram» (`mmorpg-vault/decisions/ADR-188-Web-accounts-identity-root.md`, lead-architect, in parallel) · ADR-062 (public-web flat) · ADR-087 (WipeManifest) · ADR-024 (GameSettings) · ADR-103 (menu/commands) · ADR-127 (`/guide`) · ADR-134 (tips) · ADR-020 (media-off)
**Depends on:** study `docs/specs/web-first-client/report.md` §5 «Фаза 0» (commit 76688934); recon [recon.md](recon.md)

## Goal
Make the account, not the Telegram id, the root of a player. After this spec an `accounts` row
owns one character and any number of login methods (email+password, Google, Yandex, Telegram).
Every existing character gets an account automatically. A bot player takes a one-time code from
the bot and uses it on the site to get into the account. From the cabinet they can add or unlink
login methods, but never the last one. A character with no Telegram at all can be registered and
created on the site, gated by `web.open_registration` (off = closed beta). The background layer
(Worker handlers, crons) stops assuming every character has a chat id. Bot players see no change.
The site gets a long session, logout, throttled forms, a Statable counter on every page, and the
web-client prototype goes into the repo. It is Phase 0 only: there is no playable web client yet
(Phase 1).

**Tier 3, why:** this cuts across schema/migrations, Worker/cron handlers, the Telegram command
layer, site controllers and views, and a new auth surface (🟠, needs an ADR). It is multi-module
but has no architecture migration of 200k+ LOC, so it is not Tier 4. There are 8 stories, plus 3
round-1 fix stories.

## Assumptions
Confirm or veto at the approval stop:
- **A0. Coverage (drone-coverage, 2026-09-23).** No ask absent. Partials on Asks 6, 9, 11 fixed by explicit acceptance lines in stories 03/05/06/07/08. Ask 5 vs A7: tasks and crons never lose a reward; daily tasks/streak/digest are *not assigned* to web-only characters in P0 (not lost — never granted) — owner to confirm. Work without an ask behind it, kept deliberately: honest password-reset failure (Ask 2 email login needs recovery), header auth state (Ask 11 discoverability on the site side), upgrade of legacy `tg_user_id` sessions (Ask 14: current widget users must not be logged out).
- **A1. One character per account in P0.** `AccountService::characterForAccount()` returns at most one row.
- **A2. The bot code doubles as a login.** A valid code entered while logged out logs the visitor into the character's account. Entered while logged in to an account *without* a character, it merges that account's login methods into the character's account. Entered while logged in to an account *with a different* character, it is refused with an explanation. The same merge rule applies when a Telegram Login Widget identity is linked from the cabinet. An OAuth identity that already belongs to another account is always refused. **Superseded by F1 (round-1 fix): there are no merges anymore.**
- **A3. Unlinking the Telegram identity only removes widget login.** The character keeps its `telegram_user_id` and the bot keeps working.
- **A4. No email verification in P0.** It is not in the brief. An email identity is usable right after it is set.
- **A5. Security numbers are infrastructure, not balance (ADR-024 does not apply).** They live in `Config\Accounts`: code TTL 600 s, code length 8 (alphabet without 0/O/1/I/L), remember-me 30 days, password min 8, reset token 1 h, throttle 10 POST/min per IP and 20/h per identifier.
- **A6. WipeManifest classes pending ADR-188.** `accounts`, `account_identities` → IDENTITY_RESET (they follow `telegram_users`). `account_tokens`, `account_link_codes` → TRANSIENT. A CHARACTER_RESET wipe keeps accounts, and `/start` re-attaches the new character through `ensureForTelegram`.
- **A7. Accepted gap.** Daily tasks, login streak, return digest and `last_seen` come only from the webhook and are **not ported** for web-only characters in P0. Web-only characters get **no Telegram notifications** (the Phase 1 outbox will cover that). A web-only character exists and is processed by crons, but it cannot act until Phase 1.
- **A8. Task creators stay as they are.** Starters such as `$user['id']` in `GatherAction` are reachable only from Telegram in P0, where `$user['id']` == `characters.telegram_user_id`. Only the Worker/cron read side is made NULL-tolerant (see Tradeoffs).
- **A9. Manual steps outside the build.** (a) The Queen registers the site in Statable before wave 1 and writes the snippet below. (b) The owner registers the Google Cloud and Yandex ID OAuth apps and puts the env vars into prod `.env`. Until then the buttons show as unavailable. (c) Post-deploy `php spark bot:setcommands` (ADR-103). (d) `STATABLE_SITE_HASH` goes into prod `.env`.
- **A10. SMTP on prod is unverified.** `app/Config/Email.php` has `SMTPCrypto 'ssl'` with port 587, which looks like a mismatch. This spec does **not** touch `Email.php`. If the reset mail fails, the user gets an honest message plus the bot-code alternative. Fixing SMTP is a separate owner decision.
- **A11. Prototype.** `public/webgame-preview.html` is untracked. Story 03 copies it to `docs/specs/web-first-client/webgame-preview.html`. The `.gitignore` line for the old path stays, which is harmless. If the worker's checkout lacks the file, the story returns NEEDS_CONTEXT.
- **A12. Out of scope.** Admin `users` and the publicly routed `Signup` (recon §B) are not touched. There is no "log out on all devices", because Ask 6 asks only for logout. There is no referral on web registration.
- **A13. Reachability is unverified.** Nobody has checked that Google/Yandex OAuth works from Russia without a VPN, so email+password must stay the always-on path.

### Round-1 fix assumptions (owner to confirm; they change approved A2)
- **F1. No merges; ADR-188 stays as written (story 09).** The planner recommends refusal. Any code or widget path that A2 made a merge now refuses. A widget link needs a single-use nonce that the cabinet mints into the session. **Rejected: amending ADR-188 to authorise the A2 merge.** That would still need the nonce to close #1, it keeps an identity-moving path open, and it needs a security argument from lead-architect. **Cost of refusal:** a player logged into a character-less account (with the flag on) who later wants their bot character must log out first. The email they used stays on the orphan account until a future merge ADR. Under the closed beta (flag off) that orphan is essentially unreachable.
- **F2. The bot never attaches a second character to an account (story 11).** Suppose the account holding a Telegram identity already owns a web character. Then `/start` gives the bot character a fresh account with no identity, and the player reaches it with the `/web` code. **Rejected for P0: the bot adopts the web character.** That changes the `/start` flow (Ask 14 risk) and pulls Phase 1 play forward.
- **F3. Password reset answers uniformly (story 10).** The honest mail caveat plus the bot-code path is shown to everyone. The timing gap of a synchronous SMTP send is accepted (see `## Descoped`).

### Recon questions for `drone-scout` (answer before dispatching the named wave)
- **Q1 (W1, story 02).** Confirm the exact paths of every crash site in recon §C (armor/teleport completion handlers, ToolkitHandler, QuestExplore*, QuestFirstAidkit, FoodAndWater, ClosedWarehouse, DeathRoulette, TaxCollection). Story 02 owns `app/TaskHandlers/`. Any file outside it must be added to story 02 `## Files` as a plan delta.
- **Q2 (W1, story 03).** List every public page whose view is **not** rendered through `app/Views/site/layout.php` (for example `/map`, profile, battles). Ask 8 says "на всех страницах", so those views join story 03 `## Files`.
- **Q3 (W1, story 03).** Is CSP enabled for the site (`Config\App::$CSPEnabled` / `Config\ContentSecurityPolicy`)? If yes, story 03 also needs the CSP config file for the Statable host.
- **Q4 (W3, story 06).** Is the settings screen rendered by `app/Controllers/Telegram/Commands/SettingsCommand.php`, and is it reachable from the reply menu under the ADR-150 grid? What naming/dispatch convention do callback actions in `Commands/Actions/` follow? If a different screen or file is right, amend story 06 `## Files`.
- **Q5 (W1, story 02).** What is the prod `action_log` row count? The ALTER to NULL rebuilds the table, and ops may need a quiet window.
- **Q6 (W1).** Is the newest file in `app/Database/Migrations/` earlier than `2026-12-10-100001`? Are there duplicates in `telegram_users.telegram_id` / `characters.telegram_user_id` on **testbot**? Prod was checked 2026-09-23: 0.
- **Q7 (W2, story 05).** What is the current route path of the Telegram Login Widget callback, and what does `header.php:17-21` render today?
- **Q8 (W4, story 09).** Does `TelegramLoginVerifier` leave non-Telegram query params (such as `next`) out of the data-check string? Story 09 relies on this. If the answer is no, `TelegramLoginVerifier.php` joins story 09 `## Files`.

**Recon answers (Queen, 2026-09-23, targeted `git ls-files`/`git grep` + read-only SQL):**
- Q1: every crash site is under `app/TaskHandlers/` — `Objects/ToolkitHandler.php`, `Quests/QuestExplore30CellsHandler.php`, `Quests/QuestExplore300CellsHandler.php`, `Quests/QuestExploreAllBiomesHandler.php`, `Quests/QuestFirstAidkitBasicHandler.php`, `FoodAndWaterConsumptionHandler.php`, `Objects/ClosedWarehouseHandler.php`, `DeathRouletteHandler.php`, `TaxCollectionHandler.php`, `CompleteRobotExplorationHandler.php`, `CompleteRobotGatheringHandler.php`, `MarchingTaskHandler.php`, `Craft/WorkbenchStandard/Armor/CraftCompletion{DrifterClothes,LeatherJacket,RaggedShirt,ReinforcedLeather}Handler.php`, `Craft/WorkbenchStandard/CraftCompletion{TeleportBackpack,TeleportBeaconBasic,PortableTeleport}Handler.php`. Story 02 `## Files` needs no delta.
- Q2: every public page view extends `site/layout.php` except `app/Views/site/bot_stub.php` (the bot-subdomain 301 stub — not a visitor page; stays without the counter). Partials `_layout/*` are included by the layout.
- Q3: CSP is off (`app/Config/App.php:197` `$CSPEnabled = false`) — no CSP change needed.
- Q4: `app/Controllers/Telegram/Commands/SettingsCommand.php` + `Commands/Actions/SettingsAction.php` exist (SettingsAction::buildScreen, recon 1). Story 06 keeps its Files.
- Q5: prod `action_log` 9 407 rows (~2 MB) — ALTER is instant. **Note:** `explored_cells` is the big one: ~703 k rows / 103 MB on prod; the NOT NULL→NULL change rebuilds it (MySQL 8 INPLACE). Story 02 must use a plain `MODIFY` in its own migration and the deploy is expected to take tens of seconds on that table — acceptable, no maintenance window (DAU 15).
- Q6: newest migration `2026-12-08-100000_FixArmorScreenTipNadet.php` < `2026-12-10-100001` ✓. Testbot: 0 duplicate `telegram_id`, 0 duplicate `characters.telegram_user_id`, 0 characters without tg ✓ (prod same).
- Q7: widget callback `GET login/telegram/callback`, logout `POST logout/telegram` (`app/Config/Routes.php:231-232`); header has no auth state (`app/Views/site/_layout/header.php:17-21`, study scout). Local host `http://mmorpg.test/` confirmed (`laragon/etc/apache2/sites-enabled/auto.mmorpg.test.conf`).
- wave-check reports `verify-gap` for all 8 stories: false positive — each story's own new test file is in its `## Files` but does not exist yet, and wave-check only counts existing paths. Story 01 additionally runs the existing `WipeManifestCoverageTest` read-only.

## Stories

**Wave 1**
- `web-accounts-p0-01-accounts-core` (tracer): tables `accounts`, `account_identities`, `account_tokens`, `account_link_codes`; `characters.account_id` plus backfill; UNIQUE on Telegram keys; `Config\Accounts`; `web.open_registration` seed; `AccountService`; WipeManifest.
- `web-accounts-p0-02-worker-null-tg`: nullable Telegram keys (`character_tasks`, `explored_cells`, `action_log`, drift `character_resources.id_telegram_users`); `TelegramChatResolver`; every recon §C crash site grants the reward first and skips the notification.
- `web-accounts-p0-03-site-kit-statable`: auth UI components in `wildworld-ui.css` + `ui-kit.html` + `?v=` bump; Statable partial on every page (env `STATABLE_SITE_HASH`); prototype copied into the repo.

**Wave 2**
- `web-accounts-p0-04-provisioning`: `CharacterProvisioningService` extracted from `StartCommand:87-181`, `?int $chatId` in StarterKit/NewbieGreeter, new bot players get an account.
- `web-accounts-p0-05-auth-core`: `AccountSession` (session keys, remember-me, logout, legacy upgrade), `AccountAuthService` (email+password), `AccountThrottleFilter`, the whole `/account` route group, login page + cabinet stub, Telegram widget as an identity, session readers moved to `character_id`.

**Wave 3**
- `web-accounts-p0-06-bot-link-code`: `/web` command + settings button, `LinkCodeService`, site page `/account/link`, `/guide` section, `SeedWebLinkTip`.
- `web-accounts-p0-07-oauth-cabinet`: Google (`league/oauth2-google`) and in-house Yandex provider (state + PKCE S256), OAuth buttons with a disabled state, cabinet (list/add/unlink identities, add email+password, Telegram widget link).
- `web-accounts-p0-08-registration`: flag-gated email registration + web character creation, password reset with an honest failure, header auth state.

**Wave 4: council round-1 fixes (review BLOCK, `council/round-1/review.md`)**
- `web-accounts-p0-09-identity-integrity` (opus): critical #1 + majors #2, #5. The widget callback is bound to the session through a single-use `tg_link_nonce`. `mergeInto` is removed, so code and widget refuse instead of merging (F1). Telegram login after an unlink creates no shadow account (`accountForTelegramLogin`).
- `web-accounts-p0-10-reset-no-oracle`: major #3. `/account/reset` gives one response for every email, whatever the mail outcome, and always carries the bot-code alternative (F3).
- `web-accounts-p0-11-one-character-per-account`: major #4. The bot never attaches a second character to an account; the new bot character gets its own account instead (F2).

The three wave-4 stories have disjoint `## Files`. Story 11 uses only the `AccountService`
methods that story 09 must leave unchanged.

Ask coverage: 1→01,04 · 2→01,05,07,09 · 3→06,09 · 4→01,08,11 · 5→02,04 · 6→05 · 7→03 · 8→03 · 9→03,05,06,07,08 · 10→05,07 · 11→06 · 12→06 · 13→01 · 14→02,04,11 · 15→05,06,07.

## Contracts

**Migrations (unique prefixes; the worker runs `ls app/Database/Migrations | tail` first and, if any prefix is ≥ these, shifts to the next free `2026-12-10-1000NN` and reports it)**
- 01: `2026-12-10-100001_CreateAccountsTables.php`, `2026-12-10-100002_LinkCharactersToAccounts.php`, `2026-12-10-100003_WebOpenRegistrationSetting.php`
- 02: `2026-12-10-100010_NullableTelegramKeys.php`
- 06: `2026-12-10-100020_SeedWebLinkTip.php`

**Schema (story 01)**
- `accounts`: `id` INT UNSIGNED AI PK, `acquisition_source` VARCHAR(32) NULL (`telegram` backfill/bot, `web` site), `created_at`, `updated_at`, `last_login_at` DATETIME NULL.
- `account_identities`: `id` PK, `account_id` FK→accounts ON DELETE CASCADE, `provider` ENUM('email','google','yandex','telegram'), `subject` VARCHAR(191), `secret_hash` VARCHAR(255) NULL (email only, `password_hash()`), `email` VARCHAR(191) NULL (display), `created_at`, `last_used_at` NULL; **UNIQUE(provider, subject)**, INDEX(account_id).
  Subject: email = `mb_strtolower(trim())`; google = OAuth `sub`; yandex = `id` from `login.yandex.ru/info`; telegram = `telegram_users.telegram_id` as a decimal string.
- `account_tokens`: `id`, `account_id` FK CASCADE, `purpose` ENUM('remember','password_reset'), `selector` CHAR(24) UNIQUE, `validator_hash` CHAR(64) (sha256), `expires_at`, `created_at`, `last_used_at` NULL.
- `account_link_codes`: `id`, `character_id` FK CASCADE, `code_hash` CHAR(64) UNIQUE (sha256 of the normalized code), `expires_at`, `used_at` NULL, `created_at`.
- `characters.account_id` INT UNSIGNED NULL, FK→accounts ON DELETE SET NULL, INDEX. Backfill: one account + one `telegram` identity per character with a `telegram_user_id`.
- UNIQUE `telegram_users.telegram_id`, UNIQUE `characters.telegram_user_id`. The migration aborts with a clear message if duplicates exist.
- Story 02: `character_tasks.telegram_user_id`, `explored_cells.telegram_user_id`, `action_log.chat_id` become NULL. `character_resources.id_telegram_users` becomes NULL DEFAULT NULL **only if the column exists** (prod drift).

**Config and flags**
- `Config\Accounts` (`app/Config/Accounts.php`, story 01): `linkCodeTtlSeconds=600`, `linkCodeLength=8`, `rememberLifetimeSeconds=2592000`, `rememberCookie='ww_remember'`, `passwordMinLength=8`, `passwordResetTtlSeconds=3600`, `throttleIpPerMinute=10`, `throttleIdentifierPerHour=20`.
- GameSettings `web.open_registration` (bool, default 0, rationale/effect per the `S8ReferralGameSettings` pattern). Read via `GameSettingsService` / `gsBool`.
- Env vars: `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, `YANDEX_OAUTH_CLIENT_ID`, `YANDEX_OAUTH_CLIENT_SECRET` (story 07); `STATABLE_SITE_HASH` (story 03). Empty = feature shown as unavailable (OAuth) or hidden (Statable).
- **Statable snippet** (Queen fills this before wave 1; story 03 renders it with the hash from `STATABLE_SITE_HASH`): `<script src="https://statable.com/js/{STATABLE_SITE_HASH}/s.js" defer></script>` — site `wildworld.fun` registered by the Queen 2026-09-23, value **`3353671`** (public id, not a secret; "Standard" script). The Statable "I've installed the tracking code" step is confirmed after the prod deploy.

**Services (namespace `App\Services\Web` unless noted)**
- `AccountService` (01):
  - `ensureForTelegram(int $telegramUserId): int`: find-or-create the account for this `telegram_users.id` through its telegram identity; attaches the character if it is unattached. **Unchanged by wave 4.**
  - `ensureForCharacter(int $characterId): ?int`: null for a character with neither an account nor Telegram.
  - `createAccount(string $acquisitionSource): int`
  - `findByIdentity(string $provider, string $subject): ?int`
  - `addIdentity(int $accountId, string $provider, string $subject, ?string $secretHash = null, ?string $email = null): bool`: false if `(provider, subject)` is taken.
  - `identities(int $accountId): list<array>`
  - `unlinkIdentity(int $accountId, int $identityId): bool`: false if it is the last one.
  - ~~`mergeInto(int $fromAccountId, int $intoAccountId): bool`~~: **removed by story 09 (F1).**
  - `accountForTelegramLogin(int $telegramUserId): ?int` (**new, story 09**): the account that holds the telegram identity. Returns null when there is no identity but this Telegram user's character sits on an account (the identity was unlinked, A3). Otherwise it falls through to `ensureForTelegram`. It is used by the widget login and the legacy session upgrade.
  - `characterForAccount(int $accountId): ?array`
  - `attachCharacter(int $accountId, int $characterId): void`
- `App\Services\Telegram\TelegramChatResolver` (02):
  - `chatIdForCharacter(int $characterId): ?int` (character → `telegram_users.telegram_id`, null if either is missing)
  - `telegramUserIdForCharacter(int $characterId): ?int`
- `App\Services\Player\CharacterProvisioningService` (04): `create(string $name, ?int $telegramUserId, ?int $chatId, ?int $accountId): int`. It returns the character id. When `$accountId === null && $telegramUserId !== null` it calls `ensureForTelegram`, **unless** the account holding that telegram identity already owns a character. In that case it uses `createAccount('telegram')` + `attachCharacter` (story 11, F2). Behaviour for bot players is identical to today.
- `AccountSession` (05):
  - `login(int $accountId, bool $remember = false): void`: regenerates the session id, sets the keys, `last_login_at`, and the remember token if asked.
  - `current(): ?array{account_id:int, character_id:?int, telegram_user_id:?int}`: restores from the remember cookie and upgrades legacy `tg_user_id`-only sessions (through `accountForTelegramLogin` from story 09; null clears the keys).
  - `accountId(): ?int`
  - `characterId(): ?int`
  - `refreshCharacter(): void`
  - `logout(): void`: destroys the session, deletes the token, clears the cookie.
- `AccountAuthService` (05):
  - `verifyPassword(string $email, string $password): ?int` (accountId)
  - `registerWithEmail(string $email, string $password): int|string` (accountId or an error code)
  - `setEmailPassword(int $accountId, string $email, string $password): true|string`
- `LinkCodeService` (06):
  - `issue(int $characterId): array{code:string, expires_at:string, ttl_minutes:int}`: invalidates earlier unused codes of the character.
  - `redeem(string $code): ?array{character_id:int, account_id:int}`: atomic single-use `UPDATE … WHERE used_at IS NULL AND expires_at > NOW()`, affected_rows = 1.
  - `link(string $code, ?int $currentAccountId): array{status, message, account_id}` (plan delta 06). **After story 09:** the statuses are login, no-op, refused and invalid, with no merge. Logged into any account other than the character's, the code is refused before it is spent.
- `OAuthProviderFactory` (07):
  - `isConfigured(string $provider): bool`
  - `make(string $provider): AbstractProvider`
  - `unavailableReason(string $provider): string`
- `YandexOAuthProvider extends League\OAuth2\Client\Provider\AbstractProvider` (07).
- `PasswordResetService` (08):
  - `request(string $email): 'sent'|'mail_failed'`: an unknown email also returns `'sent'`, with no enumeration. **After story 10:** the return value only feeds an operator log line. `AccountPassword` renders the same response for every outcome.
  - `complete(string $selector, string $validator, string $newPassword): bool`

**Session keys:** `account_id`, `character_id`; `tg_user_id` is still written when the character has Telegram (legacy readers). OAuth: `oauth_state`, `oauth_pkce`, `oauth_intent` ('login'|'link'). Widget link (story 09): `tg_link_nonce`. It is single-use, minted by the cabinet when it renders the link widget, carried in the widget auth URL, and consumed by the callback. Without it, a logged-in callback changes nothing. Remember cookie `ww_remember` = `selector:validator`, HttpOnly, Secure, Lax, rotated on use.

**Routes (story 05 declares the WHOLE group in `app/Config/Routes.php`; later stories only implement the controllers)**

| Method + path | Controller::method | Story |
|---|---|---|
| GET `/account` | `AccountCabinet::index` | 05 stub, 07 full |
| GET, POST `/account/login` | `AccountAuth::login` / `::attempt` | 05 |
| POST `/account/logout` | `AccountAuth::logout` | 05 |
| GET, POST `/account/link` | `AccountLink::index` / `::redeem` | 06 |
| POST `/account/identity/email` | `AccountCabinet::addEmail` | 07 |
| POST `/account/identity/(:num)/unlink` | `AccountCabinet::unlink/$1` | 07 |
| GET `/account/oauth/(google\|yandex)` | `AccountOAuth::start/$1` | 07 |
| GET `/account/oauth/(google\|yandex)/callback` | `AccountOAuth::callback/$1` | 07 |
| GET, POST `/account/register` | `AccountRegister::index` / `::store` | 08 |
| GET, POST `/account/character` | `AccountRegister::character` / `::createCharacter` | 08 |
| GET, POST `/account/reset` | `AccountPassword::request` / `::send` | 08 |
| GET, POST `/account/reset/(:segment)` | `AccountPassword::form/$1` / `::complete/$1` | 08 |

Filter alias `accountThrottle` goes on every POST above except logout. On exceed it returns 429 plus the page with a notice. The routes of wave-3 controllers 404 until wave 3 lands, and that is expected.

**Views (flat, under `app/Views/site/`)**
- `account_login` (05; 07 adds the OAuth partial)
- `account_cabinet` (05 stub, 07 full)
- `account_link` (06)
- `account_oauth_buttons` (07)
- `account_register`, `account_character`, `account_reset` (08)

**CSS components (story 03):** a form, a field (label/input/error), a notice (info/error/ok), a provider button with an unavailable state (`aria-disabled` plus a visible note) and an identity row. Story 03 adopts the existing class prefix of `wildworld-ui.css`, reports the final class names on its INTERFACES line, and the Queen pastes them here before wave 2.

## Tradeoffs
- **Chosen: fix only the read side of the Worker and crons, through one `TelegramChatResolver`.** Grant the reward, then look up the chat and skip on null. **Rejected: also rewriting the ~20 task creators** (recon §C, `$user['id']` → `$character['telegram_user_id']`). In P0 those creators are reachable only from Telegram, where the two values are equal, so the rewrite would touch 20 bot files and risk Ask 14 for zero observable gain. Phase 1 (the web bridge) will make them reachable from the web and must revisit them.
- **Chosen: one story (05) declares the whole `/account` route group up front.** **Rejected: each story adds its own routes.** That would put three wave-3 stories on `Routes.php` at once, which is a collision. The cost is 404s on wave-3 routes during wave 2.
- **Chosen (wave 4): the one-character guard lives in `CharacterProvisioningService`, the single place that creates characters.** **Rejected: guarding inside `AccountService::ensureForTelegram`.** That would put stories 09 and 11 on the same file in one wave, and it would change a contract that `/start` depends on. The cost is that `ensureForTelegram` alone can still attach to a character-owning account. The only thing that creates characters is the provisioning service, and it no longer lets that happen.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
plus `bash scripts/wave-check.sh docs/specs/web-accounts-p0` before each wave, and `curl -sS -o /dev/null -w '%{http_code}' <route>` for `/account/login`, `/account/link`, `/account/register`, `/account` after wave 3.
The Queen's manual Tier-2/3 pass follows: `/account/*` at 375/768/1440 with a clean console (Ask 9), and `/web` on preprod-testbot for `telegram_user_id=25` (Ask 11).

## Descoped

- 2026-09-23 · round-1 review #3 residual: `/account/reset` sends mail synchronously, so a known email can take longer to answer than an unknown one. We accept this timing gap in P0 (DAU 15, and `accountThrottle` limits probing). A mail queue is a Phase 1 item (F3).

## Plan deltas

- 2026-09-24 · trigger: story 10 NEEDS_CONTEXT (x2). Decision: `tests/database/AccountRegistrationTest.php` joins story 10 `## Files`; EDIT `testResetPageShowsHonestMailFailureWithBotCodeAlternative` to assert the unified page (one body for every outcome, still naming the bot-code alternative) — do not delete coverage. Worker's mailer seam (`Services::email` shared + `clear(true)`) accepted. Also: the wave-4 stop on story 09 (`full suite red`) was NOT story 09 — a round-1 council seat left `php -S localhost:8080` serving the court copy, which made `BasePickerTest`/`StartRobotGatheringBaseTest` photo URLs reachable; process stopped, suite green 4330/0.
- 2026-09-23 · trigger: story 06 NEEDS_CONTEXT (x2). Queen's Q4 answer was wrong: the settings screen is built in `SettingsAction::buildScreen()` (shared by /settings, the `settings` callback and the «настройки» reply text) and callbacks resolve only through `CallbackRoutes`. Decision: `app/Controllers/Telegram/Commands/Actions/SettingsAction.php` and `app/Config/CallbackRoutes.php` added to story 06 `## Files` (07/08 are closed; no collision). The worker's proposed `LinkCodeService::link(string $code, ?int $currentAccountId)` returning a status+message (refuse-before-spend) is accepted as a contract extension beside `redeem()`.
- 2026-09-23 · trigger: story 04 NEEDS_CONTEXT (x2). Decision: YES to both — `tests/unit/Services/Onboarding/OnboardingNavLabelConsistencyTest.php` (source-scan must now point at `app/Services/Player/CharacterProvisioningService.php`, keeping the `! $singleScreen` assertion) and `phpstan-baseline.neon` (delete the two `StartCommand.php` entries that became unmatched) added to story 04 `## Files`. Story 05 lists neither. Observed hazard: wave-2 workers ran DB tests concurrently against the shared `wildworld_tests` (one flaky run in 04) — close-story runs the suite sequentially, which is the gate.
- 2026-09-23 · trigger: story 02 return report — fixes removed errors that 13 `phpstan-baseline.neon` entries matched (phpstan fails on unmatched entries). Decision: `phpstan-baseline.neon` added to story 02 `## Files` (no other wave-1 story lists it). Tail recorded: StrategicLootHandler:165,303, BaseRelocationCompletionHandler:146, BaseFullRelocationCompletionHandler:200 still call `find(null)` for web-only characters — harmless (no throw, no send), left for Phase 1.
- 2026-09-23 · trigger: driver stop `close-story exit 2 — verification not in ## Commands` on story 01 (per-file phpunit and curl lines are not byte-exact `## Commands` cells). Decision: every story's `## Verification` = full suite + phpstan + migrations lint (exact cells); workers still run their own test files while iterating; curl lines dropped (exit 0 even on HTTP 500 — vacuous; views are checked by the council and the Queen's Tier-2 pass). Rejected: editing the constitution's Commands table for one spec.
- 2026-09-23 · trigger: council round 1, review BLOCK (`council/round-1/review.md`, 1 critical, 4 major, 13 minor). Decision: wave 4 carries 3 fix stories. Story 09 covers #1, #2 and #5, merged because they share `TelegramLogin`/`AccountService` and one rule. Story 10 covers #3 and story 11 covers #4. A2 is superseded by F1, pending owner confirmation. Minor findings are **not cut** as stories. The worker-level ones (#10, #11, #12, #13, #15, #18) and the plan-level ones (#6, #7, #8, #9, #14, #16) wait for a Queen or owner call. #17 (tech-writing notes) is `drone-docs` work before ship, not a code story.

**Approved:** Andrei, 2026-09-23
**Briefed:** <written by scripts/cycle.sh briefed - stage 01+02 on the straight-through path (--go, Tier 1): "via grill, <owner>, <date>" (or "via grill (assumed)" / "via mini-brief"). Alternative to **Approved:** above.>
**Branch:** vulyk/web-accounts-p0
**Checked:** <written by scripts/human-check.sh after the owner has looked - stage 05, and the override for stage 04+05. /vulyk-ship refuses without either this or a GREEN **Council:** line.>
**Council:** RED round 1, 2026-09-23, at 32aaafc7, pack 14c02cf5e190
**Shipped:** <written by scripts/ship-check.sh --record - stage 06: the published version, and where>
