---
story: bugs-info-0923-05
spec: bugs-info-0923
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: []
---

# «Надеть» вместо «Одеть» на карточках экипировки

## Goal
Кнопка экипировки на карточках брони (`Profile/GearArmorDetailAction.php`, по разведке `:187`) и оружия (`Profile/GearWeaponDetailAction.php`, `:208`) подписана «Надеть». Роутинг идёт по префиксу `callback_data` (`CallbackRoutes.php:236,238`), не по тексту, поэтому он не меняется. После истории `git grep -n "Одеть" -- app` пуст.

## Requirements
> "надеть"
> Якщо ці баги в грі є, береш їх всі, плануєш через вулик-план і запускаєш починку, ремонт цих багів

## Files
- app/Controllers/Telegram/Commands/Profile/GearArmorDetailAction.php
- app/Controllers/Telegram/Commands/Profile/GearWeaponDetailAction.php
- app/Controllers/Telegram/Commands/Profile/ToggleEquipArmorAction.php
- app/Controllers/Telegram/Commands/Profile/ToggleEquipWeaponAction.php
- app/Database/Migrations/2026-12-08-100000_FixArmorScreenTipNadet.php

Queen delta (2026-09-23, после grep): «Одеть» живёт ещё в `ToggleEquipArmorAction.php:174` и `ToggleEquipWeaponAction.php:183` (текст кнопки после переключения) и в уже применённом сиде совета `2026-10-24-100000_SeedArmorScreenTip.php:41` («кнопка *Одеть* или *Снять*»). Сид не править — новая идемпотентная миграция `2026-12-08-100000_FixArmorScreenTipNadet.php`: `UPDATE game_tips SET content = REPLACE(content, '*Одеть*', '*Надеть*') WHERE title_en = 'ArmorScreen'`, `down()` — обратная замена. Комментарии «Одеть» в коде тоже поправить; после story `git grep -n "Одеть" -- app` находит только старый сид.

## Non-goals
- `callback_data` кнопок не менять: старые сообщения должны работать.
- Видимое игроку «Одеть»/«одеть» (действие «надеть на себя») в этих двух файлах → «Надеть»/«надеть». Другие формы («одет», «одета») и остальной текст карточек не переписывать, их список — в Implementation notes.
- `git grep` найдёт «Одеть» вне этих двух файлов (или тест, который проверяет подпись) → стоп и INTERFACES со списком, чужой файл не трогать.

## Map slice
`memory/map/telegram.md` — Key types (action-handler по `callback_data`).

## Acceptance criteria
- [ ] На карточках брони и оружия кнопка экипировки подписана «Надеть», `callback_data` байт в байт прежний.
- [ ] `git grep -n "Одеть" -- app` пуст.
- [ ] Набор и phpstan зелёные: подпись нигде в тестах не закреплена. Если закреплена — INTERFACES (см. Non-goals).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `GearArmorDetailAction.php`, `GearWeaponDetailAction.php`: кнопка `'Одеть'` → `'Надеть'`, комментарии тоже поправлены; `callback_data` не тронут.
- `ToggleEquipArmorAction.php:174`, `ToggleEquipWeaponAction.php:183`: текст кнопки после переключения `'Одеть'` → `'Надеть'`.
- Новая миграция `2026-12-08-100000_FixArmorScreenTipNadet.php`: `UPDATE ... SET content = REPLACE(content, '*Одеть*', '*Надеть*')` по `title_en='ArmorScreen'`, `down()` — обратная замена. Сид `2026-10-24-100000_SeedArmorScreenTip.php` не тронут (по указанию Queen delta).
- `git grep -n "Одеть" -- app` находит только строку внутри старого сида `2026-10-24-100000_SeedArmorScreenTip.php:42` — ожидаемо (Queen delta это явно предвидела, поэтому acceptance-строка «grep пуст» выше по файлу устарела относительно delta).
- Тестов, закрепляющих подпись кнопки или текст тайпа, не найдено (`grep -rln "Одеть\|Надеть" tests/` — только `tests/_support/Community/provenance-corpus.php`, статический корпус, уже ждёт «Надеть», ассертов на код не делает).
- Полный phpunit-прогон: 2 незатронутых падения (`BiomeGatherProfileServiceTest`, `SpecializationServiceTest`) и фон ~427 ошибок — не в файлах story, пре-существующее состояние параллельной волны (другие story правят `CraftedResourcesAction.php`, `QuestsInfo.php`, `ExploredMapService.php`, `StrategicLootHandler.php` одновременно).
- phpstan: 24 pre-existing ошибки вне файлов story (`QuestsInfo.php`, `StrategicLootHandler.php`) — ноль ошибок в тронутых файлах.

## Findings
