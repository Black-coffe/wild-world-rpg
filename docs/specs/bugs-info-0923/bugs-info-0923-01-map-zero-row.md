---
story: bugs-info-0923-01
spec: bugs-info-0923
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Ряд и столбец 0 на карте

## Goal
Картинка изученной карты (`ExploredMapService`) считает мир как 0..999 по обеим осям. Изученная клетка с `x=0` или `y=0` попадает в кадр и закрашивается, окно не выходит за 999. Сейчас (`ExploredMapService.php:149-152`) окно зажато в `max(1, …)` / `min(WORLD_SIDE=1000, …)`, поэтому нулевые ряд и столбец не рисуются никогда. Текстовая сетка (`TextMapService`, по разведке `:223` уже держит 0 внутри мира) закрепляется тестом: игрок на `Y=1` видит ряд `Y=0` клетками мира, а не «⬜ за краем».

## Requirements
> Ладно, игру я прошла, последний баг-репорт:
> Ордината 0 не отображается
> Якщо ці баги в грі є, береш їх всі, плануєш через вулик-план і запускаєш починку, ремонт цих багів

## Files
- app/Services/World/ExploredMapService.php
- app/Services/World/TextMapService.php
- tests/unit/Services/World/MapZeroEdgeTest.php

## Non-goals
- `TextMapService.php` трогать, только если тест ряда `Y=0` покраснеет на нетронутом коде. Если зелёный, файл остаётся без правок, а в Implementation notes пишется «закреплено тестом».
- Не менять движение (`MoveCharacterToDirectionAction`, `MAP_MIN=0`), компас, `MapZoomService` и формат/размер картинки.
- Не делать размер мира настройкой `GameSettings`: это геометрия мира, не баланс. Можно заменить магическое `1000` на именованную границу внутри файла, но без новой конфигурации.
- Тест не копирует формулу окна: он зовёт код сервиса.

## Map slice
`memory/map/world.md` — Entry points, Gotchas: рендер мира PHPUnit не видит, в `wildworld_tests` нет таблицы `map`. Тест строит свою схему сам (по миграции) или проверяет вынесенный чистый метод окна/пикселя.

## Acceptance criteria
- [ ] По изученным клеткам {(0,0), (999,999)} окно картинки ровно 0..999 по обеим осям: не `1..1000`, не больше 999. Клетка (0,0) попадает в кадр и закрашена цветом изученной клетки. Проверяет `MapZeroEdgeTest` через код `ExploredMapService`. Допустимо вынести расчёт окна/пикселя в метод того же класса.
- [ ] Окно вокруг изученной клетки у края (например, (0, 500)) не уходит в отрицательные координаты и не теряет столбец `x=0`.
- [ ] Текстовая сетка у игрока с `Y=1`: ряд `Y=0` отрисован клетками мира, а не символом «⬜ за краем». Проверяет `MapZeroEdgeTest`.
- [ ] Без нового числа баланса.
- [ ] Одиночный прогон зелёный: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/World/MapZeroEdgeTest.php`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `ExploredMapService.php`: окно вынесено в `public window(minX,maxX,minY,maxY)`, зажим `WORLD_MIN=0..WORLD_MAX=WORLD_SIDE-1` (было `1..1000`); отрисовка вынесена в `public drawImage(window, cells, ?px, ?py): ?\GdImage` без БД, `renderPng` = summary → window → SELECT → drawImage → PNG. Поле `PAD=2` стало константой. Формат/размер картинки не менялся.
- `TextMapService.php`: не тронут — закреплено тестом (ряд `Y=0` у игрока на `Y=1` рисуется клетками мира на нетронутом коде).
- `MapZeroEdgeTest`: 4 теста; при возврате старого зажима `max(1)/min(WORLD_SIDE)` краснеют все 3 теста картинки (проверено откатом), текстовый — зелёный на обеих версиях, как и ожидалось.
- Полный `phpunit`: 618 errors / 11 failures, все — гонка схемы в общей `wildworld_tests` (`Table 'characters' already exists`, `Cannot drop table 'characters' referenced by FK`), вероятно параллельные прогоны других story; ни одного падения в World/Map. `tests/unit/Services/World` (64) и `tests/unit/World` (7) зелёные. phpstan: 24 ошибки только в `QuestsInfo.php` и `StrategicLootHandler.php` (чужие незакоммиченные правки), по двум файлам story — 0.

## Findings
