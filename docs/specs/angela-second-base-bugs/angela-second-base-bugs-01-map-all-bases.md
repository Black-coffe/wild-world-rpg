---
story: angela-second-base-bugs-01
spec: angela-second-base-bugs
status: done
tier: 3
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Карта помечает все базы игрока, а расстояние считает до ближайшей

## Goal
`TextMapService` перестаёт знать ровно одну базу персонажа: в окне карты эмодзи 🏕 получает каждая
активная база, попавшая в видимый прямоугольник, а строка расстояния считает ходы до ближайшей из
них. У игрока с одной базой ни карта, ни строка не меняются ни на символ.

## Requirements
> На карте не отображается вторая база
> Если те баги остались еще, устрани их, протестируй на тест-проде и потом залей на боевой. И с смок-тестами.

## Files
- app/Services/World/TextMapService.php
- app/Models/ClaimedCellModel.php
- tests/unit/Services/World/TextMapMultiBaseTest.php

## Non-goals
- Не трогать ветку «чужие базы» (`$otherBases`, 🚫) — она уже исключает собственного персонажа и работает верно.
- Не менять легенду карты и не вводить новый эмодзи: 🏕 уже описан как «ваша база» и годится для N баз.
- Не чинить `BaseCampDecorService`, `BaseService::findFirstActiveCell` и прочие «однобазовые» места — они в `## Открытые хвосты` плана, не в этой истории.
- Не переписывать `findFirstActiveCell` / `resolveTargetBaseCell`: только ДОБАВИТЬ `findAllActiveCells`, существующие методы оставить байт-в-байт.
- Не менять формат строки расстояния (слова, эмодзи, порядок) — меняется только то, до какой базы она считает.

## Map slice
`memory/map/world.md` (карта, туман войны), `memory/map/bases.md` (мульти-база, `claimed_cells`).

## Ключевые места (разведка уже сделана, искать не надо)
- `app/Services/World/TextMapService.php:124-137` — `$baseX/$baseY` как одна пара скаляров, источник — `claimedCellModel->where('character_id',…)->where('status','active')->first()` без `orderBy`.
- `app/Services/World/TextMapService.php:234-239` — отрисовка 🏕 сравнением `$worldX === $baseX && $worldY === $baseY`. Ветка стоит ДО ветки тумана войны — своя база видна всегда, это поведение сохранить.
- `app/Services/World/TextMapService.php:372-376` — та же однобазовая выборка в `getDistanceLine()`.
- `app/Models/ClaimedCellModel.php` — уже есть `findActiveCell`, `findFirstActiveCell`, `resolveTargetBaseCell`, `countActiveBases`. Многострочного сиблинга нет — его и добавляем (контракт в plan.md).

## Acceptance criteria
- [ ] У персонажа с двумя активными базами, обе из которых попали в окно карты, 🏕 стоит на обеих клетках.
- [ ] База вне окна карты маркер не добавляет и ничего не ломает.
- [ ] Строка расстояния показывает число ходов до ближайшей базы; для персонажа ровно с одной базой строка совпадает с прежней посимвольно.
- [ ] Персонаж без баз: карта и строка ведут себя как раньше (маркера нет).
- [ ] Собственная база помечается независимо от того, разведана ли клетка (порядок веток сохранён).
- [ ] Текст экрана самодостаточен без картинок: число ходов и факт «это твоя база» читаются из самого сообщения.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Tracer
Тонкий срез через все слои класса ошибки: модель (`findAllActiveCells`) → сервис (набор клеток вместо одной пары) → текст экрана. Если на этом срезе вскроется, что «текущая база» резолвится не так, как предполагает план, — это меняет истории 02 и 03, и узнать об этом надо здесь.

## Замечания по тестам (обязательно прочесть)
- В тестовой БД нет наполненной таблицы `map` (`feedback_verify_render_on_db_with_real_world_data`). Не строй тест на реальном мире: выдели выбор клеток-маркеров так, чтобы его можно было проверить без наполненной карты, либо посей ровно те строки, которые тесту нужны, своей же схемой.
- DB-тест обязан строить свою схему сам — CI гоняет на ПУСТОЙ базе без миграций (`.claude/rules/db-schema.md`).
- `map.id == cell_number` — инвариант; в тестах сей `id` явно.
- НИКОГДА не делай `git stash`, `git checkout` и не трогай общую локальную тест-БД разрушительно (DROP/migrate). Нужен старый вариант файла — `git show HEAD:<path>`.
- Не гоняй весь набор параллельно с другими агентами: DB-тесты дерутся за общие таблицы.

## Implementation notes
- `ClaimedCellModel::findAllActiveCells()` добавлен (orderBy id ASC, детерминированный порядок); `findFirstActiveCell`/`resolveTargetBaseCell` не тронуты.
- `TextMapService::buildMapOnly()` — `$baseX/$baseY` заменены на `$ownBaseCells` (map `"{x}_{y}" => true`), отрисовка 🏕 через `isset()`; порядок веток (своя база до тумана войны) сохранён байт-в-байт.
- `TextMapService::getDistanceLine()` — вместо `first()` перебирает `findAllActiveCells()`, берёт минимум по Чебышёву; формат строки не менялся.
- Тест `TextMapMultiBaseTest` — без БД, все модели подменены reflection'ом на анонимные подклассы (паттерн `TeleportUseValidatorBaseChoiceTest`), проверяет обе базы в окне, базу вне окна, отсутствие баз, и ближайшую базу в строке расстояния (+ byte-identical формат для одной базы).
- phpstan: `find($claimedRow['map_cell_id'])` пришлось нарроуить через `is_numeric()` на переменной (не на offset), не кастуя mixed напрямую; заодно почистил 4 устаревших baseline-записи для TextMapService.php (obsolete: coordinate_x/y на вложенном типе, map_cell_id-non-empty, notIdentical.alwaysTrue) и поднял count 2→4 для двух живых записей coordinate_x/coordinate_y (тот же класс ошибки CI4 `find()`, теперь встречается в цикле по нескольким базам).

## Findings
