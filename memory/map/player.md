<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Игрок (Services/Player)

## Purpose
Персонаж и всё, что с ним происходит вне боя и вне мира: статы, инвентарь, вес, смерть,
дебаффы, специализации, титулы, стрики, торговля, телепорты, дроны, рефералы.

## Entry points
- `CharacterService.php` — центральный сервис персонажа; `CharacterStatsService.php` — статы.
- `PlayerStateService.php`, `ProfileHubService.php` — состояние и экран Персонажа.
- `DeathService.php` + `Death/`, `DebuffService.php`, `DebuffSourceService.php`.
- `WeightCapacityService.php`, `InventorySortService.php`, `ResourceOverviewService.php`.
- `Trade/`, `TeleportBeacon/`, `TeleportUse/`, `Gather/`, `Craft/`, `Progression/`, `Relocation/`.
  `TeleportUse/`: destination = player-chosen active base (story backpack-teleport-base-choice),
  not just the first claimed cell.
- `DroneService.php`, `RobotService.php`, `RobotRepairService.php`, `CaravanService.php`.
- `TipService.php` (советы), `TitleService.php`, `LoginStreakService.php`, `ReferralService.php`.
- `app/Entities/CharacterEntity.php`, `app/Repositories/CI4CharacterRepository.php`.

## Key types / contracts
`CharacterEntity` — CI4 Entity, **не массив**: `array $x` typehint + `strict_types` даёт TypeError,
а `toRawArray()` обходит касты. Ресурсы персонажа матчатся по паре (`id_characters`, `id_resources`);
колонка `id_telegram_users` в `character_resources` — рудимент.

## Dependencies
inbound: Telegram-actions, TaskHandlers, admin, web-контроллеры.
outbound: модели `app/Models/*`, `Services/GameSettings`, `Services/Notifications`.

## Gotchas
- Запись статов идёт через `CharacterStatsService` с `FOR UPDATE` — это лечение lost-update;
  писать статы в обход сервиса нельзя.
- `characters.level` затирается пересчётом опыта — выставлять уровень на testbot напрямую бесполезно.
- Списание крафт-предметов — только `deductCraftedItem`; raw-set `update()` молча не срабатывает.
- Заряды при слиянии стаков зажимаются `min(dur, base)` на каждом чтении.
- `Death/PlayerRespawner.php:82` всё ещё берёт `claimed_cells` bare `first()` (не тронут story
  backpack-teleport-base-choice — non-goal).
- Ловушки (exploit-audit, `docs/specs/exploit-audit/REPORT.md`): 🔴 `EA-duplication-02` —
  портативный телепорт срабатывает дважды с одного заряда (снимок `validatePortable()`,
  `TeleportItemConsumer` пишет абсолют и ничего не возвращает); 🔴 `EA-gaps-04` — маяк ставится
  до списания предмета, `false` от `subtractItem()` игнорируется.

## Vault
`mmorpg-vault/apps/player/index.md` · `mmorpg-vault/tech-writing/services/`
