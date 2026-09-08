---
story: beacon-install-discoverability-01
spec: beacon-install-discoverability
status: todo
tier: 2
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Кнопка «📡 Маяки» на экране «ты не на базе»

## Goal

Персонаж, находящийся вне базы и вне покрытия вышки связи, нажимает «🏠 База» и получает
заглушку `notOnBasePhysically()`. После этой story в её клавиатуре появляется кнопка
`📡 Маяки` → `teleportBeacon`, и путь, который обещают все обучающие тексты игры
(«дойди до места → «🏠 База» → «📡 Маяки» → «Установить маяк здесь»»), впервые с 09.07.2026
становится проходимым. Экран маяков уже умеет всё остальное — чинится только вход.

## Requirements

> А как маяки устанавливать? Работает сейчас это? Сообщение пролетело с Wild World Info ветки чат.

> «1️⃣ Дойди до места, куда захочешь возвращаться. 2️⃣ Открой *«🏠 База»* → *«📡 Маяки»*

## Files
- app/Services/Bases/BaseServiceMessageFormatter.php
- tests/unit/TeleportBeacon/NotOnBaseBeaconDoorTest.php

## Non-goals
- Не трогать `baseBuildings()` — там кнопка «📡 Маяки» уже есть и стоит правильно.
- Не гейтить новую кнопку по наличию «Центра телепортации», уровню или маякам в сумке:
  lock-state рендерит сам экран маяков, а гейт вернул бы ровно ту невидимость, которую чиним.
- Не менять текст заглушки по существу (координаты базы, биом, «1️⃣ дойти пешком / 2️⃣ телепорт»
  остаются) — исключение только если новая кнопка требует одной поясняющей строки.
- Не трогать `CharacterService`, ADR-150-сетку и флаг `navigation.final_grid.enabled`.
- Не заводить новый `callback_data`: вход — существующая строка `teleportBeacon`.

## Map slice
`memory/map/bases.md` (экран базы, BaseService), `memory/map/telegram.md` (callback-маршруты).

## Acceptance criteria
- [ ] `notOnBasePhysically()` возвращает клавиатуру, в которой есть кнопка с
      `callback_data === 'teleportBeacon'` и подписью `📡 Маяки`.
- [ ] Прежние кнопки «📡 Телепорт» (`TeleportToCamp`) и «🧭 Двигаться» (`move`) на месте.
- [ ] Ни один ряд клавиатуры не состоит из одной кнопки (правило «ноль одиночек в ряду»).
- [ ] Возвращаемая структура прежней формы: ключи `caption` / `parse_mode` / `reply_markup`,
      `reply_markup` — валидный JSON.
- [ ] Тест падает, если кнопку убрать.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TeleportBeacon/`

## Tracer
Тонкий срез через все слои двери: формат клавиатуры (formatter) → существующий маршрут
`teleportBeacon` в `CallbackRoutes` → уже живой экран `TeleportBeacon`. Если срез покажет, что
заглушка рендерится не через этот метод или клавиатура где-то переписывается ниже по стеку —
сообщить в `## Findings` до того, как story 02 и 03 будут считаться нужными в текущем виде.

## Implementation notes

## Findings
