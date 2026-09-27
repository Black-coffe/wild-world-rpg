---
story: w2-n4-base-03
spec: w2-n4-base
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 3
blocked_by: [w2-n4-base-01, w2-n4-base-02]
---

# Веб: нативная «🏠 База» в /play, стройка в HUD; хвосты N3

## Goal
`/play?view=base` рисует из ядра story 01–02: пикер при 2+ базах, обзор, каталог «что можно
построить» с замками «🔒 Название (нужно: …)» и путём, карточку постройки, старт стройки, апгрейд.
Остальные действия зданий — кнопки `op=bridge` с прежней `callback_data`. «🏠 База» — в доке, идущая
стройка — в строке задач HUD с живым таймером. Компоненты сначала в `public/ui-kit.html`. Хвосты N3:
костёр бота читает рыбный список из `CraftOrderService`; «🔒 Профессиональный крафт» не рвёт слово на 375.

## Requirements
> 2. /play?view=base: выбор базы при 2+, обзор (клетка, биом, постройки со стопками, налог, срок, Вышка), «что можно построить» с замками и путём к требованию, карточка (есть/нужно, время, уровень, эффект), старт стройки и апгрейд; стройка в HUD с таймером; «🏠 База» в доке.
> 3. Действия вне ядра (роботы, теплица, маяки, дрон, снос, ремонт, склад, ангар, декор) — видимые кнопки через мост, ни одна не пропадает.
> 6. Открытие базы в вебе = визит, как в боте; одноразовые подсказки веб-игроку — во входящие.
> 7. Экраны сначала в ui-kit.html; без горизонтального скролла на 375/768/1440; читаются без картинок.
> 9. Хвосты N3: «🔒 Профессиональный крафт» не рвёт слово на 375; FISH_RECIPES — один источник.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Services/Player/CharacterSheetService.php
- app/Views/site/_play/native_base.php
- app/Views/site/_play/native_craft.php
- app/Views/site/_play/dock.php
- app/Views/site/_play/hud.php
- app/Views/site/_layout/meta.php
- app/Services/Craft/CraftOrderService.php
- app/Controllers/Telegram/Commands/Actions/Craft/Cooking/CampfireCookingSelect.php
- public/assets/css/wildworld-ui.css
- public/assets/js/wildworld-play.js
- public/ui-kit.html
- tests/database/PlayViewControllerTest.php
- phpstan-baseline.neon

## Non-goals
- Не делать нативными карточки 14 зданий и их действия, снос, ремонт, склад, ангар, декор — только кнопки моста.
- Не менять правила ядра из story 01–02; найденная дыра — в Findings.
- Только токены `wildworld-ui.css` (ADR-062): 0 радиусов, 0 теней.

## Map slice
`memory/map/website.md` — «Игра на сайте», нативные экраны, дедуп `web_play_intents`, CSRF; `memory/map/bases.md` — Entry points.

## Acceptance criteria
- [ ] `view=base` с 2+ базами → пикер; с одной → обзор; без базы → экран с путём к «разбить лагерь» (через мост); чужой `b` → отказ, не чужая база.
- [ ] Каждое здание обзора имеет кнопку; у каждого действия вне ядра — кнопка моста, ведущая на экран бота.
- [ ] `build_start`/`upgrade` с тем же `intent_id` дважды → одно действие; HUD после старта показывает стройку с таймером `[data-ends-at]`, после завершения — не «идёт».
- [ ] Открытие `view=base` пишет визит; подсказка web-only видна во входящих.
- [ ] Без JS — PRG на `/play?view=base`; `?v=` у `wildworld-play.js`/CSS поднят.
- [ ] `CampfireCookingSelect` не держит свой рыбный список; «Профессиональный крафт» переносится по словам на 375.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
