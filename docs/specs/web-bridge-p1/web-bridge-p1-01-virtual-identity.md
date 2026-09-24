---
story: web-bridge-p1-01
spec: web-bridge-p1
status: todo
returned:
tier: 3
worker: worker-code
model: opus
tracer: true
wave: 1
blocked_by: []
---

# Virtual identity, web-play schema, flag and firehose channel

## Goal
Every character can be addressed through the existing `from.id → telegram_users` chain. A web-only
character gets a `telegram_users` row with `telegram_id = VirtualChat::idForAccount(account_id)`,
which is in the reserved virtual range (ADR-189 §3). New web characters get the row from
`CharacterProvisioningService`. P0 characters with `telegram_user_id IS NULL` get it from a
backfill migration, which also fills their NULL `character_tasks`/`explored_cells` keys. The
bridge schema exists: `web_play_state`, `web_inbox`, `web_play_intents`, the GameSettings flag
`web.play_enabled` (default off) and firehose source `web`. The new tables are in WipeManifest.
See plan `## Contracts` (Schema, VirtualChat, VirtualIdentityService, DeliveryContext,
PlayerActionLogger, Config\WebPlay, provisioning).

## Requirements
> Персонаж без Telegram получает виртуальный id и играет наравне со всеми.
> Игра на сайте спрятана за флагом `web.play_enabled` в админке (по умолчанию выключен).
> В журнале действий есть канал `web`.
> Новые таблицы (входящие, виртуальные id) прописаны в WipeManifest.

## Files
- app/Database/Migrations/2026-12-11-100001_CreateWebPlayTables.php
- app/Database/Migrations/2026-12-11-100002_WebPlayEnabledSetting.php
- app/Database/Migrations/2026-12-11-100003_PlayerActionLogWebSource.php
- app/Database/Migrations/2026-12-11-100004_BackfillVirtualTelegramUsers.php
- app/Database/Migrations/2026-12-11-100005_SignedTelegramIdColumns.php
- app/Config/WipeManifest.php
- app/Config/WebPlay.php
- app/Services/Web/VirtualChat.php
- app/Services/Web/VirtualIdentityService.php
- app/Services/Web/DeliveryContext.php
- app/Services/Logging/PlayerActionLogger.php
- app/Services/Player/CharacterProvisioningService.php
- tests/unit/Services/Web/VirtualChatTest.php
- tests/database/VirtualIdentityServiceTest.php
- app/Services/Web/AccountSession.php
- app/Services/Web/AccountService.php
- tests/database/WebPlaySchemaTest.php
- tests/unit/Services/Logging/PlayerActionLoggerTest.php
- tests/database/AccountRegistrationTest.php
- tests/database/*Provisioning*Test.php
- phpstan-baseline.neon

## Non-goals
- No transport, seam, inbox writing or screen logic. Those belong to stories 04/05.
- No `account_identities` row for a virtual id, and no change to `ensureForTelegram` or to the
  P0 F2 bot branch of provisioning.
- Do not rewrite task creators (`$user['id']`). The backfill is what makes them consistent.
- Create `100005` **only** if the column check below finds an UNSIGNED or too-narrow column. If
  it finds none, do not create the file, and say so in the notes.
- Do not filter virtual rows out of broadcasts or admin stats (plan A14).

## Map slice
- `memory/map/tasks-worker.md`: the ADR-188 web-only gotcha and the `find(null)` trap.
- `memory/map/admin.md`: WipeManifest, GameSettings.
- `memory/map/website.md`: accounts, one character per account.
- ADR-189 §3, §7, §8 and its wipe table.
- `mmorpg-vault/tech-writing/services/PlayerActionLogger.md`.

## Acceptance criteria
- [ ] (plan delta, recon Q9) `AccountSession` (:182-192, :93-96) and `AccountService::ensureForCharacter` (:90-92) treat a `telegram_user_id` whose `telegram_users.telegram_id` is in the virtual range (`VirtualChat::is()`) as "no Telegram": no session `tg_user_id` from it, no `ensureForTelegram()` for it — a virtual row never becomes a Telegram identity or a second account attachment.
- [ ] The worker runs its own new test files singly while iterating. The close-story gate is the
      full suite, phpstan and the migrations lint.
- [ ] **Verified before coding and written in Implementation notes with evidence:**
      - the type and signedness of `telegram_users.telegram_id` and of every column a virtual id
        or a synthetic `update_id` reaches (`action_log.chat_id`, the `player_action_log` id
        columns, `character_tasks.telegram_user_id`, `explored_cells.telegram_user_id`);
      - the `CharacterTaskModel` validation for `telegram_user_id`;
      - the Bot API statement on id bit width, with its URL.

      Fix `VIRTUAL_BASE` only after that. If the bit width cannot be confirmed, keep 2^52 and
      mark it unverified.
- [ ] Ask 2: `VirtualChat::is()` is true for `idForAccount(n)` and false for 0, for positive ids,
      for `-1001234567890` (supergroup shape) and for `-123456789` (group shape).
      `|idForAccount(n)| < 2^53`.
- [ ] Ask 2: `CharacterProvisioningService::create(name, null, null, accountId)` gives the new
      character a virtual `telegram_users` row and a non-NULL `characters.telegram_user_id`.
      A second call for the same account reuses the row. Bot-path creation is unchanged, and
      the existing tests stay green.
- [ ] The backfill migration:
      - creates exactly one virtual row per character with a NULL `telegram_user_id`;
      - sets the NULL `character_tasks`/`explored_cells` keys of those characters;
      - is idempotent, and its `down()` removes only its own rows.

      A test covers `ExploredCellsModel::revealAround()` for such a character and gets no FK
      error.
- [ ] Ask 7: `web.play_enabled` is seeded as a bool, default 0, with rationale/effect/above/below,
      following `2026-12-10-100003_WebOpenRegistrationSetting.php`. The seed is idempotent.
- [ ] Ask 6: the `player_action_log.source` ENUM accepts `web`, and `begin($update, 'web')` writes
      source `web`. `begin($update)` with no override behaves exactly as before
      (`PlayerActionLoggerTest` green).
- [ ] Ask 11: `web_inbox` and `web_play_state` are PLAYER_DATA by `character_id`, and
      `web_play_intents` is TRANSIENT. `WipeManifestCoverageTest` is green.
- [ ] `DeliveryContext` round-trips `setActor`/`actor`/`reset`. `Config\WebPlay` carries the plan
      values.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Tracer
Thinnest slice:
1. The backfill migration creates a virtual `telegram_users` row.
2. `identityForCharacter()` returns that row with `virtual=true`.
3. A `player_action_log` row with source `web` is inserted under STRICT.

If the schema check breaks any link in this chain, stop and report before building the rest.

## Implementation notes

## Findings
