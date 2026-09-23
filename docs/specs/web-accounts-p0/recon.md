# Recon — web-accounts-p0 (condensed scout reports, 2026-09-23)

Sources: 2 scouts for this spec (character creation; telegram-id decoupling) + the study
`../web-first-client/report.md`. All paths repo-relative. Evidence is `file:line`.

## A. Character creation today (only `/start`)

`app/Controllers/Telegram/Commands/StartCommand.php` does the writes inline:
- a. INSERT `telegram_users` (telegram_id, username, names, acquisition_source, language_code) `:61-70`
- b. INSERT `characters` (telegram_user_id, name=username?:'', level 1, experience 0.01, health 100, tired 100, str/agi/int 0.01, gold 1000, cell_number null) `:89-109`
- c. name fallback `Путник-{id}` via static `mintDistinctName` `:111-114,:404-407`
- d. referral `ReferralService::recordReferralOnRegister` `:119-125` (flag `referral.enabled`)
- e. spawn: random `map` cell `coordinate_y>=900`, biome in [1,2,3,5,6,7,8,9] → UPDATE cell_number `:131-151` (inline)
- f. `OnboardingChainService::ensureChainAssigned` `:155-156` (no chat id)
- g. `StarterKitService::grant($charId,$tgUserId,$chatId)` `:166-167` (needs chat id; writes action_log)
- h. `ColdOpenSignalService::placeBaitForNewChar` `:173-174` (no chat id)
- i. `NewbieGreeterService::placeGreeterForNewChar(..., $chatId, ...)` `:179-180` (needs chat id)
- UI-only: reply keyboard, welcome/kit texts `:200-311`.
- Tutorial steps are optional; "skip tutorial" (`WithoutTrainingStartAction.php:36-84`) writes nothing → a character after step i is fully playable.
- Daily tasks / login streak / last_seen / return digest are assigned only from the webhook (`BotController.php:135-169`) — web characters get none until ported (accepted gap for P0; note in plan).
- Name rule: `NameService::applyName` regex `/^[\p{L}\p{N}_]{3,20}$/u` (`app/Services/Player/NameService.php:53`); NO uniqueness on `characters.name`.

**Extract:** `CharacterProvisioningService::create(...)` taking over `StartCommand.php:87-181`; `StartCommand` keeps only Telegram UI. `StarterKitService`/`NewbieGreeterService` accept `?int $chatId`.

## B. Schemas

- `telegram_users.telegram_id` BIGINT NOT NULL, **no UNIQUE** (`2024-03-20-153728_CreateTelegramUsersTable.php:18-23`) → race on double /start.
- `characters.telegram_user_id` INT nullable, not UNIQUE, FK ON DELETE SET NULL (`2024-03-20-154155_CreateCharactersTable.php:18-23,:96`).
- `character_tasks.telegram_user_id` INT NOT NULL FK (`2024-03-22-132411...:24-29,:61`); model `required|integer` (`app/Models/CharacterTaskModel.php:32`); no WHERE by it anywhere; `character_id` always set.
- `explored_cells.telegram_user_id` INT NOT NULL FK (`2024-03-24-212921...:24-29,:68`); ALL reads by `character_id`; writes via `ExploredCellsModel::revealAround(int $characterId, int $telegramUserId, …)` `:25-27,:76-81`; callers MoveCharacterToDirectionAction:275, MoveNorthEastTips:86, RecceDroneAction:127, SettlementTeleportService:221, MarchingTaskHandler:275, CompleteRobotExplorationHandler:247,:407, ExploreAreaTipsAction:61.
- `action_log.chat_id` BIGINT UNSIGNED NOT NULL (`2024-03-18-134951...:24-29`) — ~30 writers.
- `character_resources.id_telegram_users` — not in migrations (DB drift); model strips it (never written).
- `player_action_log.telegram_user_id/chat_id` already nullable.
- Admin `users` (email UNIQUE, password_hash) — untouched; `Signup` is publicly routed and creates dead non-admin rows (`Routes.php:183-185`) — out of scope, note only.

**Decision (from evidence):** web-only character has NO `telegram_users` row and `characters.telegram_user_id = NULL`.

## C. Code that breaks for a character with no Telegram (must fix — Ask 5)

`find(null)` in CI4 returns ALL rows (`BaseModel.php:596-598`); warnings become exceptions in production.
- CompleteRobotExplorationHandler:88-92 — returns before reward (reward lost) → move chat lookup after reward.
- CompleteRobotGatheringHandler:107-110 — throws before crediting (:282).
- MarchingTaskHandler:116 → :275 (revealAround with 0 → FK violation), re-inserts :608, :664.
- Armor completion handlers: DrifterClothes:76, LeatherJacket:71, RaggedShirt:81, ReinforcedLeather:71 → TypeError in `notifyUser(int …)` after grant.
- TeleportBackpack:105→139-144, TeleportBeaconBasic:103→137-142 — find(null) → throws.
- Crons: ToolkitHandler:96, QuestExplore30Cells:81, QuestExplore300Cells:79, QuestExploreAllBiomes:69, QuestFirstAidkit:80, FoodAndWater:85,:247 (no try/catch → aborts whole run for everyone), ClosedWarehouse:90,:136, DeathRoulette:219, TaxCollection:819,:833.
- Task creators write `$user['id']` — must write `$character['telegram_user_id']` (nullable): PlantCropActionStart:107, StartRobotExplorationAction:215, StartRobotGatheringAction:241, GenericBuildingAction:204, GenericCraftActionStart:230, GatherAction:257, StartrobotexplorerCommand:294, 7 legacy armor/teleport starters (e.g. StartCraftArmorRaggedShirt2Action:215), MarchAction:268, RepairCraftedItemAction:251, DeleteBaseAction:425 (`?? 0`), RelocationTaskCreator:71. NOTE: for Telegram players `$user['id']` == `$character['telegram_user_id']`, so for bot players behavior is unchanged — these creators are reachable only from Telegram in P0, so they can stay as-is; only Worker-side reads must tolerate NULL. Planner decides.
- Safe already (skip on null): Gather, GenericCraft, GenericBuilding, PlantCrop, Repair, PortableTeleport, Relocation, Greenhouse, LowHealth, StrategicLoot, GatherResultPersister, StandoffNotifier, AchievementCheckCron, StreakMilestone, TitleCheck; broadcast/nudge/digest iterate `telegram_users` → skip.
- Proposed helper: one resolver `chatIdForCharacter(int $characterId): ?int`.

## D. Web auth / session / infra

- Web login today: Telegram widget → `TelegramLogin.php:36-83` → session `tg_user_id`,`character_id`; readers of `tg_user_id`: `Map.php:24,:162-168`, `ProfileController.php:193`, `AchievementsController.php:91`, `BattlesController.php:195`.
- Session: FileHandler, 7200 s (`app/Config/Session.php:24-92`); cookie Secure/HttpOnly/Lax.
- CSRF global for POST except webhook (`app/Config/Filters.php:85`); no rate limit on web forms (only `TelegramRateLimitFilter` on webhook, `Filters.php:124`).
- Email: `app/Config/Email.php` smtp, SMTPCrypto 'ssl' with port 587 (likely mismatch); prod sending never verified → password reset by email must degrade gracefully (flag/honest message).
- GameSettings: seed migration pattern `app/Database/Migrations/2026-09-22-100000_S8ReferralGameSettings.php:29-94`; read `GameSettingsService::get` / `gsBool` (`GameSettingsReaderTrait.php:24-51`); admin `/admin/game-settings`.
- New slash command: `app/Controllers/Telegram/Commands/XxxCommand.php` (auto-discovered, `BotController.php:23`); menu list `BotMenuService::commandList()` (`app/Services/Telegram/BotMenuService.php:289`) + `php spark bot:setcommands` post-deploy (ADR-103). No existing one-time-code/login-code mechanism.
- WipeManifest: `app/Config/WipeManifest.php` (categories :37-42; `telegram_users` IDENTITY_RESET :134-141; `characters` CHARACTER_RESET :147; `users` KEEP :110); test `tests/unit/Config/WipeManifestCoverageTest.php`.
- DB tests: `tests/database/`, preferred pattern = run real migration classes (`tests/database/RelocateAbandonedCharactersTest.php:22-27,:92,:391-403`).
- Site design system: `public/assets/css/wildworld-ui.css` + `public/ui-kit.html` (ADR-062); header `app/Views/site/_layout/header.php:17-21`; meta `app/Views/site/_layout/meta.php`; layout `app/Views/site/layout.php`.
- Prototype: `public/webgame-preview.html` gitignored (`.gitignore:246-253`).

## E. Library facts (fact-checked 2026-09-23)

| Claim | Status | Sources |
|---|---|---|
| `league/oauth2-client` latest 2.9.1 (2026-09-16), php `^7.1 \|\| >=8.0 <8.6`, guzzle `^7.8.2` ok (lock: guzzle 7.10.0, psr7 2.9.0) | ✅ | Packagist p2 JSON (T1) · composer.lock (local) |
| `league/oauth2-google` 5.0.0 (2026-03-23), php ^8.0, class `League\OAuth2\Client\Provider\Google`, README lists PHP 8.3 | ✅ | Packagist (T1) · github.com/thephpleague/oauth2-google README (T1) |
| `aego/oauth2-yandex` last release 0.2.2 (2018-01-31) → do NOT use | ✅ | Packagist (T1) |
| Yandex ID: authorize `https://oauth.yandex.ru/authorize?response_type=code&client_id…[&redirect_uri][&scope][&state][&code_challenge&code_challenge_method=S256]`; token `POST https://oauth.yandex.ru/token` grant_type=authorization_code; userinfo `GET https://login.yandex.ru/info?format=json` with header `Authorization: OAuth <token>`, returns `id`, `login` | ❓ one T1 source only | yandex.ru/dev/id/doc/ru/codes/code-url, …/user-information (T1, fetched 2026-09-23) — worker re-verifies at build |
| Google/Yandex OAuth reachable from Russia without VPN | ❓ unverified | — (risk; email+password is the always-available path) |

**Implication:** Yandex = small in-house provider on `league/oauth2-client` `AbstractProvider` (or GenericProvider) with the endpoints above. OAuth client ids/secrets come from env vars `GOOGLE_OAUTH_CLIENT_ID/SECRET`, `YANDEX_OAUTH_CLIENT_ID/SECRET` — registering the apps is an owner/manual step; with empty env the button is hidden (lock-state).
