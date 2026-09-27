---
story: w2-n2-live-map-02
spec: w2-n2-live-map
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 2
blocked_by: [w2-n2-live-map-01]
---

# Сервис шага с событиями, шаг кликом в вебе

## Goal
`App\Services\World\MoveService::step(int $characterId, string $dir)` делает всё, что сегодня
делает `MoveCharacterToDirectionAction::handle()` до отправки:
- блокировки: переезд, эксклюзивная задача;
- границы 0..999 и доступные направления;
- цену с учётом транспорта и раннего множителя;
- записи: статы, клетка и биом, дебафф, открытие 3×3.

Сервис возвращает исход с кодом отказа и `events[]` — нейтральный текст и callback-кнопки хуков
после шага. Это обнаружение, вышки, онбординг-подсказки, находка, cold-open, атмосфера, дебафф,
а также караван, поселение, узел, объект, дроны, груз и склад. Хуки, которым сейчас нужен `$chatId`,
отдают событие, а не шлют сами.

Handler бота становится рендерером: карта из модели story 01, события отдельными сообщениями, как
сейчас. Прирост стата и опыта за шаг переезжает в GameSettings (`world.move.stat_per_step` = 0.02,
`world.move.xp_per_step` = 0.03, с rationale и границами).

Веб: `op=step` с `dir` (клик по соседней клетке и роза), `intent_id` с суффиксом. События
показываются флешами под картой, их кнопки идут через мост.

Хвосты story 01 (Ask 7, Ask 8): поднять `?v=` у `wildworld-ui.css` (и у `wildworld-play.js`, если он меняется) в `meta.php`; в `native_gear.php` без Арсенала надетая броня показывается с формой «Снять» (`op=unequip`), замок остаётся только на «Надеть».

## Requirements
> 2. В /play — серверная сетка с кликом: соседняя клетка = шаг, клетка на одном из 8 лучей = превью Похода (направление, число клеток, ETA, цена), прочие — подсказка; роза направлений работает без JS.
> 3. Шаг — один сервис для бота и веба; возвращает события шага (обнаружение, вышки, находка, дебаф, онбординг, атмосфера): бот шлёт их как сейчас, веб показывает под картой. Механика шага не меняется; прирост стата и опыта за шаг — в GameSettings.

## Files
- app/Services/World/MoveService.php
- app/Controllers/Telegram/Commands/Actions/MoveCharacterToDirectionAction.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_map.php
- public/assets/js/wildworld-play.js
- app/Database/Migrations/2026-12-13-100000_SeedMoveStepGainSettings.php
- tests/database/MoveServiceTest.php
- tests/database/PlayViewControllerTest.php
- phpstan-baseline.neon
- app/Views/site/_layout/meta.php
- app/Views/site/_play/native_gear.php

## Non-goals
- Не добавлять шагу проверку воды и чужих лагерей — механика не меняется (plan `## Assumptions`).
- Сервисы хуков (детекция, вышки, LuckyFind, ColdOpen) переписывать ровно настолько, чтобы они возвращали событие вместо отправки.
- Не трогать `MoveCharacterAction` (старый путь) и Поход.
- Не менять значения цены шага `world.move.*`.

## Map slice
`memory/map/world.md`; `memory/map/player.md` (статы, дебаффы); `memory/map/onboarding.md` (подсказки).

## Acceptance criteria
- [ ] Тест: шаг за край мира, шаг при эксклюзивной задаче и шаг при нехватке здоровья или сил отказывают с тем же смыслом, что прежний handler. Успешный шаг пишет те же дельты.
- [ ] Прирост за шаг читается из GameSettings, и при дефолтах дельты совпадают с прежними. Seed-миграция идемпотентна и проходит `php -l`.
- [ ] Бот: шаг показывает ту же карту и те же сообщения-подсказки, что до правки.
- [ ] Веб: клик по соседней клетке и роза двигают персонажа; события шага видны под картой; повтор `intent_id` не делает второй шаг.
- [ ] Без JS роза и клетки работают через PRG на `/play?view=map`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress && git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
