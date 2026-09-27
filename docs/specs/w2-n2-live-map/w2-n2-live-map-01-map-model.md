---
story: w2-n2-live-map-01
spec: w2-n2-live-map
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Модель карты, «Мир» бота из неё, нативная сетка в /play

## Goal
`App\Services\World\LiveMapService::forCharacter(int $id)` отдаёт модель экрана карты без Markdown:
- окно 12×12 со смещением 6;
- для каждой клетки код, биом, маркер и explored по текущей приоритетной лестнице `TextMapService`;
- расстояние до ближайшей базы, статы (❤️ 💤), центр, доступные действия и легенду.

`TextMapService` рисует строку карты из модели, а не из своего SQL. `MoveSurfaceService::show/renderCompassInPlace`
становятся рендерерами модели (текст + роза, кнопки прежние).

В вебе `view=map`: партиал `site/_play/native_map.php` с HUD и доком. Внутри HTML-сетка клеток:
клетка — `<button>` в форме, роза направлений — тоже формы, без JS всё работает. «🌍 Мир»/«Карта»
в доке открывает эту сетку. В этой story формы клеток и розы ведут на `op`, которых ещё нет: до
story 02 они отвечают «скоро» или идут через мост. Плюс два хвоста W2.N1.

## Requirements
> 1. «Мир» — одна модель экрана карты в сервисе (окно, маркеры, туман, расстояние до базы, действия); карта бота и карта /play рисуются из неё и показывают одно и то же.
> 5. Док /play: «🌍 Мир»/«Карта» открывает нативную карту; кнопки без своего экрана (остров, события, дроны, сбор) — через мост.
> 7. Сетка, роза, превью Похода — сначала в ui-kit.html; без горизонтального скролла на 375/768/1440.
> 8. Хвосты W2.N1: intent_id с суффиксом влезает в VARCHAR(64); снять броню можно без своего Арсенала.

## Files
- app/Services/World/LiveMapService.php
- app/Services/World/TextMapService.php
- app/Services/World/MoveSurfaceService.php
- app/Services/Web/WebNativeScreenService.php
- app/Services/Player/EquipmentLoadoutService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_map.php
- app/Views/site/_play/dock.php
- public/assets/css/wildworld-ui.css
- public/assets/js/wildworld-play.js
- public/ui-kit.html
- tests/database/LiveMapServiceTest.php
- tests/database/EquipmentLoadoutServiceTest.php
- tests/database/PlayViewControllerTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать шаг (`MoveCharacterToDirectionAction`) и Поход — это story 02 и 03.
- Не менять окно, приоритет маркеров и гейты флагов (`settlements.enabled`, `world.nodes.point_mode_enabled`, `navigation.world_hub.enabled`).
- Не трогать фото-карту `MapService::showMapWithPlayer` (ветка world_hub=off) и публичный `/map`.
- Не заводить новую колонку или таблицу под intent — лимит решается в коде.

## Map slice
`memory/map/world.md` (Gotchas: 0..999, `BiomeModel` — Entity, `TextMapService` рисует все базы); `memory/map/website.md` (раздел «Игра на сайте»).

## Acceptance criteria
- [ ] Тест на фикстуре `map`: модель отдаёт окно 12×12, и в каждой клетке тот же маркер, что в строке `TextMapService` до правки. Проверяются игрок, свои базы, туман, чужая база только на открытой клетке и ⬜ за краем 0..999.
- [ ] Бот: «🌍 Мир» и возврат из легенды показывают ту же карту, розу и кнопки, что до правки.
- [ ] Веб: док «🌍 Мир»/«Карта» открывает нативную сетку с HUD; кнопки мира без своего экрана работают через `op=bridge`.
- [ ] `intent_id` длиной 60 плюс самый длинный суффикс экрана не превышает 64 символа (тест на границе).
- [ ] Снять броню без своего Арсенала можно (тест); надеть без Арсенала — по-прежнему замок.
- [ ] Сетка и роза есть в `ui-kit.html`; 0 радиусов и теней, только токены.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
