<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-03

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
- **ЗАКРЫТО (2026-09, exploit-fix-08, F7, ADR-181).** `EA-duplication-02` — портативный телепорт
  срабатывал дважды с одного заряда. `TeleportItemConsumer::consumePortable()`/`consumeBackpack()`
  теперь делают CAS по снимку (`WHERE id=? AND quantity=? AND durability_count=?`,
  `bool`/`?int` вместо `void`/`int`); `TeleportUseAction` списывает до перемещения и перемещает
  только на подтверждённый исход. Не через `ConditionalWriteService` — инлайн CAS (у примитива нет
  готового метода под двухколоночный condition с произвольным новым значением).
- **ЗАКРЫТО (2026-09, exploit-fix-07, F5, ADR-181).** `EA-gaps-04` — маяк ставился до списания
  предмета. См. `bases.md` (`BeaconInstaller`).
- Ресурсы персонажа матчатся по (`id_characters`, `id_resources`) — см. Key types выше;
  `decreaseResources()` (F0/ADR-181, 2026-09) удалена, см. `craft.md`.

## Vault
`mmorpg-vault/apps/player/index.md` · `mmorpg-vault/tech-writing/services/`
