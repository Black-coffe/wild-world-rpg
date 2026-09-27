<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-27

# Scout report: Мир и карта (Services/World)

## Purpose
Клеточный мир: биомы, перемещение, туман войны, объекты, узлы, компас, текстовая карта.

## Entry points
- **`LiveMapService.php`** — единая модель экрана «Мир» (окно 12×12, коды клеток, ближайшая база,
  действия, легенда) для бота И `/play` (ADR-190). `TextMapService` — только текстовый рендер модели.
- **`MoveService.php`** — шаг на соседнюю клетку: `step()` → исход `{ok, code, message, events[]…}`,
  `afterStep()` — хуки с чатом. Зовут `MoveCharacterToDirectionAction` и `WebNativeScreenService::step`.
- **`MarchService.php`** — Поход: `preview/start/afterStart/extend/resume/stop/status`. Зовут
  `MarchAction`, `CancelMarchAction`, `/play` (`op=march_*`), HUD (`CharacterSheetService::hud`).
- `MapService.php`, `MapZoomService.php`, `TextMapService.php`, `ExploredMapService.php`.
- `BiomeCompassService.php`, `BiomePalette.php` — компас биомов и палитра (ADR-152; палитра и у `/map`).
- `ObjectDiscoveryService.php`, `ObjectSignalService.php`, `StrategicObjectService.php`.
- `MoveSurfaceService.php` (экран «Мир» бота из модели `LiveMapService`), `MarchMiniEventService.php`.
- `NpcLocatorService.php`, `IslandPulseService.php`, `NodeLevelCurve.php`, `SeasonalCraftService.php`.
- Модели: `MapModel`, `BiomeModel`, `ExploredCellsModel`.

## Key types / contracts
`BiomeModel` отдаёт **Entity**, а не массив — проверки вида `is_array()` на биоме дают ложное «нет».
Карта мира — единственный экран, который остаётся текстовым всегда (исключение из media-правил).

## Dependencies
inbound: `MapCommand`, action-handler'ы перемещения и разведки, TaskHandlers добычи/разведки,
`Services/Web/WebNativeScreenService` (`/play`, вид `map`).
outbound: модели мира, `Services/Player` (позиция, вес), `Services/Coverage`.

## Gotchas
- **Координаты мира — 0..999 по обеим осям**, не 1..1000. `ExploredMapService::WORLD_MIN/WORLD_MAX`
  (bugs-info-0923-01); до фикса картинка «Что я открыл» теряла ряд и столбец 0.
- `ResourceModel` отдаёт `ResourceEntity`: имя ресурса — `instanceof ResourceEntity` + `->name`,
  не `is_array()` (`StrategicLootHandler`, bugs-info-0923-04).
- Слои карты, лестница кодов и ближайшая база считаются только в `LiveMapService::grid()` /
  `nearestBase()` (все активные базы, Чебышёв) — новый слой добавлять туда, не в `TextMapService`.
- `MoveService` и `MarchService` сами в Telegram не пишут; рана и «хвост» клетки — `events[]`
  (бот: кнопки/сообщение, веб: под картой). Коды отказа `relocation`/`busy` — Markdown.
- `MarchService::start()` пишет `msg_chat_id`/`msg_id` в саму вставку `character_tasks` — только если их
  передал бот. Поход из веба без них → `MarchingTaskHandler` на каждом тике шлёт НОВОЕ сообщение в TG.
- Прирост за шаг — GameSettings `world.move.stat_per_step` (0.02) / `world.move.xp_per_step` (0.03),
  миграция `2026-12-13-100000_SeedMoveStepGainSettings`; цена шага — `world.move.*_cost_base`.
- Статики `MarchAction::clampOrderToCap/vehicleHookBlock/routeEtaMinutes` и
  `MoveCharacterToDirectionAction::computeStepCost/availableDirections` — делегаты к сервисам.
- Рендер мира **не проверяется PHPUnit**: в тестовой базе `wildworld_tests` нет таблицы `map`.
  Проверять на реальных данных — `php spark`-командой или HTTP-маршрутом.
- Баланс Похода целиком вынесен в `GameSettings` под ключи `world.march.*` — магических чисел быть
  не должно.
- После мини-события Похода игрок возвращается кнопкой на карту, а не в меню.

## Vault
`mmorpg-vault/apps/world/index.md` · `tech-writing/services/{LiveMapService,MoveService,MarchService}.md` ·
канон — `mmorpg-vault/lore/`
