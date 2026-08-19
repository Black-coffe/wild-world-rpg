<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Мир и карта (Services/World)

## Purpose
Клеточный мир: биомы, перемещение, туман войны, объекты, узлы, компас, текстовая карта.

## Entry points
- `MapService.php`, `MapZoomService.php`, `TextMapService.php`, `ExploredMapService.php`.
- `BiomeCompassService.php`, `BiomePalette.php` — компас биомов и палитра (ADR-152).
- `ObjectDiscoveryService.php`, `ObjectSignalService.php`, `StrategicObjectService.php`.
- `MoveSurfaceService.php`, `MarchMiniEventService.php` — перемещение и мини-события Похода.
- `NpcLocatorService.php`, `IslandPulseService.php`, `NodeLevelCurve.php`, `SeasonalCraftService.php`.
- Модели: `MapModel`, `BiomeModel`, `ExploredCellsModel`.

## Key types / contracts
`BiomeModel` отдаёт **Entity**, а не массив — проверки вида `is_array()` на биоме дают ложное «нет».
Карта мира — единственный экран, который остаётся текстовым всегда (исключение из media-правил).

## Dependencies
inbound: `MapCommand`, action-handler'ы перемещения и разведки, TaskHandlers добычи/разведки.
outbound: модели мира, `Services/Player` (позиция, вес), `Services/Coverage`.

## Gotchas
- Рендер мира **не проверяется PHPUnit**: в тестовой базе `wildworld_tests` нет таблицы `map`.
  Проверять на реальных данных — `php spark`-командой или HTTP-маршрутом.
- Баланс Похода целиком вынесен в `GameSettings` под ключи `world.march.*` — магических чисел быть
  не должно.
- После мини-события Похода игрок возвращается кнопкой на карту, а не в меню.

## Vault
`mmorpg-vault/apps/world/index.md` · канон — `mmorpg-vault/lore/`
