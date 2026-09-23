---
story: bugs-info-0923-01
spec: bugs-info-0923
status: todo
returned:
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

## Findings
