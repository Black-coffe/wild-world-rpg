# Sudden death without warning (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/sudden-death-1125` · **Approved:** «Одобряю (Рекомендую)», 2026-11-25

## Goal
A world event drains a gathering (or idle-in-biome) character to 0.01 HP with no messages, and the per-minute
death roulette kills at 0.01 with 99 % in the same minute the first warning arrives; hunger floors at 0.01 too.
Per the owner's choice A, passive sources (world events, hunger) stop at a safe floor above the roulette
threshold, so they wound but never kill; chosen risk (march, steps into danger) stays lethal. The floor has a
price: gathering in the event's biome is interrupted and cannot restart while HP is low. The player is told:
the event notice says it hurts and that the base is safe, one warning on crossing a threshold, the march preview
shows the real HP cost, and the death message names the cause and the damage. Decision record: ADR-200
(`mmorpg-vault/decisions/ADR-200-Passive-damage-does-not-kill.md`).

## Assumptions
- The player's case was a damage world event (most likely «Подземные толчки») — inferred from code; not
  confirmed by a SELECT on prod (`event_effects_log` for that character). Unverified; does not change the fix.
- "Passive" = world events (every `damage_health` kind incl. Snowfall, Fever, MirageOases side effects — all
  pass through `IntentApplier`) and the daily hunger. Moves, marches, PvE/PvP stay as today (ask 2).
- If HP is already below the floor (e.g. after a march), passive damage leaves it unchanged — the floor never
  heals. Characters already below 1.0 at release are not lifted.
- Poison keeps its literal 1.0 floor (ask 1 says "keeps its own floor"); not moved to GameSettings here.
- The low-health cron (`<= 5.00`, ADR-184 bands) and the roulette (`<= 0.99`) stay as they are; the new warning
  is sent from the damage path, so it no longer coincides with the roulette minute. Their hardcoded literals are
  not part of this work.
- Hunger for web-only characters sends no message today (`telegramId === null` return) — fixed only if the
  builder touches that line anyway; otherwise noted as a follow-up.
- The death-cause lookup window (minutes back in `event_effects_log`) is a display window, not balance — a
  constant.
- Goes live: merging into `develop` locally runs nothing. Push to `develop` deploys preprod (migrations with
  three new GameSettings keys and one tip seed run there); prod only by tag. Each step on the owner's word.

## Stories
- [ ] S1 Passive floor for events and hunger - asks: 1, 2, 8, 9
  - files: `app/Services/Events/IntentApplier.php`, `app/Services/Events/EventDispatcher.php` (log the applied
    delta), `app/TaskHandlers/FoodAndWaterConsumptionHandler.php`, new seed migration
    `*SeedPassiveFloorSettings.php` (`health.passive_floor` float 1.0, hard_min 1.0, hard_max 50, plus the
    keys S2/S3 read: `health.event_gather_min`, `health.passive_warn_threshold`, all with rationale / effect /
    above / below, soft and hard bounds); tests; tech-writing `services/IntentApplier.md`,
    `tasks/FoodAndWaterConsumptionHandler.md`, new `services/EventDispatcher.md`; ADR-200 → accepted on merge.
  - test first: IntentApplier with HP 5 and a −9 hit ends at 1.0 (today 0.01); HP 0.5 and a −3 hit stays 0.5
    (never heals); hunger on 1.5 HP ends at 1.0 (today 0.75); the applied delta returned/logged is the real one.
    A move/march step below 1.0 still happens (unchanged handlers — a regression test on `MarchingTaskHandler`
    or `MoveService` cost path). Seed test: every key has rationale/above/below and hard bounds, hard_min of the
    floor > 0.99.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Events/IntentApplierTest.php tests/database/IntentApplierRelativeDeltasTest.php` + the new test files; `php -l` on the migration.

- [ ] S2 Gather interrupted at the floor; gather gate in an event biome - asks: 3, 8
  - files: `app/Services/Events/EventDispatcher.php` (after applying damage: character `is_gathering`, HP at
    the floor → move the in_work Gather row to `interrupted` via `ConditionalWriteService::transitionIfCurrent`,
    `CraftQueueActivationNotice::promote/send` as `CancelGatherAction` does, send a «go to base» message,
    VirtualChat-aware), new `app/Services/Events/ActiveDamageEventLookup.php` (active damage events covering a
    biome: `active_events` × `events.biome_ids` × `WorldEvents` effect kind), `app/Controllers/Telegram/Commands/Actions/GatherAction.php`
    (refusal after the biome is known, before `transBegin`; menu hint like `staminaHintForMenu`); tech-writing
    `handlers/GatherAction.md`, new `services/ActiveDamageEventLookup.md`.
  - test first: a gathering character hit to the floor ends with its gather `interrupted` and one message sent;
    a non-gathering one or one above the floor keeps its task; a second interrupt attempt is a no-op. Gate: HP
    below `health.event_gather_min` in a biome with an active damage event → refusal text naming the event and
    «на базу / подлечись»; same HP in a biome without such event → gather starts. Lookup: global event (NULL
    biome_ids) covers every biome; tired-only events (Sandstorm) do not count.
  - check: the new tests + `tests/database/GatherActionStaminaCostTest.php`, `GatherActionFastStartTest`;
    `/play` path goes through the same `GatherAction` (ADR-189 bridge) — verified in Tier-3 on both clients.

- [ ] S3 The player is warned: event notice and threshold warning - asks: 4, 5, 9
  - files: `app/Services/Events/EventMessageFormatter.php` (damage events add a line: hurts health, roughly
    the per-hit range from config, safe on own base; caption ≤ 1024), the damage path in `EventDispatcher`
    (when HP crosses `health.passive_warn_threshold` downward in this tick — one message, even if one hit jumps
    several thresholds; reuses a text builder extracted from `LowHealthWarningHandler`; respects the player's
    `health_warnings_enabled` opt-out), `app/Services/Onboarding/GuideCatalog.php` section `worldevents`
    (events wound, don't kill; gathering stops at low HP — no balance numbers), tip seed
    `*SeedEventsWoundNotKillTip.php`; tech-writing `services/EventMessageFormatter.md`,
    `tasks/LowHealthWarningHandler.md`, `services/GuideCatalog.md`.
  - test first: start notice of a damage event contains the damage line and «база», a loot event does not; the
    longest event name+description stays ≤ 1024. A hit from 40 → 25 with threshold 30 sends one warning; 40 → 1
    sends one; 25 → 20 (already below) sends none; opted-out player gets none.
  - check: `tests/unit/Services/Events/EventMessageFormatterTest.php`, `GuideCatalogTest`, the new tests.

- [ ] S4 Honest march preview and named cause of death - asks: 6, 7
  - files: `app/Services/World/MarchService.php` (`preview` counts danger cells from `routeBreakdown` with
    `b.danger_level`, HP = cells × cost + danger cells × surcharge, shows HP left; if it ends ≤ 0.99 a «можешь
    погибнуть» line before confirm), the march preview renderers (bot and `/play`), `app/Services/Player/Death/DeathMessageBuilder.php`
    (cause: sum of applied `health_delta` per event from `event_effects_log` in the window, else an active/just
    finished march with its `acc.hp`, else hunger; text: which, how many HP, over what time); tech-writing
    `services/MarchService.md`, `services/DeathMessageBuilder.md`.
  - test first: preview of a route with 3 danger cells shows 3 × surcharge more HP than today and the
    remaining HP; a route ending below 0.99 carries the warning line. Death message with event rows −40 and −30
    of «Подземные толчки» over 25 min names the event, 70 HP, 25 min; with none — march / hunger wording.
  - check: `tests/database/MarchServiceTest.php`, `tests/unit/Services/Player/Death/DeathMessageBuilderTest.php`, the new tests.

## Verdicts
- `/guide`: yes — section `worldevents` (S3): harmful events wound but don't kill; gathering stops at low HP,
  go to base.
- Tips: yes — category of world events (S3): «События ранят, но не добивают — добыча прервётся, иди на базу».
- Onboarding / discoverability: the gather refusal and the menu hint explain the lock and the way out (S2).
- Wipe manifest: no new table or column (GameSettings rows are seeds).

## Test command
`vendor/bin/phpunit --no-coverage --no-progress <files>` per story; before merge — the ten suite parts from
`CLAUDE.md` `## Команды`, phpstan, `php -l` of migrations. Tier-3 on preprod-testbot (bot + `/play`) after deploy.

## Descoped
(none)
