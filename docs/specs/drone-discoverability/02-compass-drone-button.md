---
story: drone-discoverability-02
spec: drone-discoverability
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Компас-экран карты получает «🚁 Дрон»

## Goal
Первый рендер карты (`MoveCharacterAction` → `MoveSurfaceService`) показывает кнопку
«🚁 Дрон» (`callback_data: droneScoutList`) на тех же условиях, что и экран после шага:
killswitch `drone.scout.enabled` ON и у чара есть `DroneScout` с `quantity > 0`.
Близнец перестаёт расходиться: обе поверхности ходьбы ведут к дрону.

## Requirements
> **Компас-экран карты кнопки дрона не имеет.** `MoveSurfaceService`
> (первый рендер карты, `MoveCharacterAction`) не рендерит `droneScoutList`;
> она есть только на экране *после* шага. Классический расходящийся близнец.

## Files
- app/Services/World/MoveSurfaceService.php

## Non-goals
- НЕ трогать `MoveCharacterToDirectionAction` (его кнопка уже есть; это чужая story-граница).
- НЕ переносить весь контекстный хвост экрана шага (Караван / Узел / Незнакомец /
  Обыскать / Склад / Карго) в сервис — переносим ТОЛЬКО scout-дрона.
- НЕ менять компас-розетку `compassRows()` — она общий источник для обеих поверхностей,
  правка там ударит по экрану шага.
- НЕ вводить новых GameSettings-ключей.

## Map slice
memory/map/ — секция про карту/движение.

## Acceptance criteria
- [ ] Кнопка встаёт в существующий `$worldRow` (рядом с «🌍 Остров живёт» / «🎉 События»),
      а не отдельной одиночной строкой; при переполнении ряд пакуется по 2–3 кнопки.
- [ ] Кнопка отсутствует, если `DroneService::isEnabled()` вернул false.
- [ ] Кнопка отсутствует, если у чара нет строки `crafted_items_log` с `DroneScout` и `quantity > 0`.
- [ ] Лишние запросы не уходят при выключенном killswitch'е: проверка владения выполняется
      ТОЛЬКО после `isEnabled()`.
- [ ] `buildDirectionsKeyboard()` вызывается из обоих мест сервиса (строки ~66 и ~290)
      с одинаковым результатом — расхождения поверхностей не появилось.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Services/World/MoveSurfaceService.php`

## Implementation notes
`buildDirectionsKeyboard()` теперь принимает `$character` и повторяет один-в-один условие
close-close с MoveCharacterToDirectionAction:366-383 (isEnabled → CraftedItems `DroneScout` →
CraftedItemsLog qty>0), кнопка встаёт в `$worldRow`; ряд пакуется `array_chunk($worldRow, 3)` —
при ≤3 кнопках рендер идентичен старому одному ряду. Оба вызова (`show()`, `renderCompassInPlace()`)
передают `$character`. phpstan L9 чист.
Ревью: комментарий у `array_chunk` переписан — он не гарантирует «никогда не одна кнопка»;
при обоих killswitch'ах мира (island, final_grid) выключенных и дроне у чара ряд выродится
в одну кнопку (недостижимо на проде сегодня, т.к. оба ON, но код это допускает).
