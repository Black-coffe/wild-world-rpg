---
story: w2-n4-base-03
spec: w2-n4-base
status: done
returned: DONE
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
- `view=base` (`WebNativeScreenService::VIEW_BASE`, вьюха `site/_play/native_base.php`) рисует из ядра story 01–02: `BaseScreenService::resolve()` (пикер / обзор / нет базы / далеко / недоступна), `overview()`, `BuildOrderService::catalog()/preview()`, `BuildingUpgradeService::preview()`. Отклонение от контракта плана: навигация — поля формы `b`/`section` (`overview|catalog|building|upgrade`)/`key`/`id`, как у крафта (`bench`/`cat`/`recipe`), а не `op=pick|catalog|building|upgrade_preview`; мутации — `op=build_start` (`key`) и `op=upgrade` (`id` — тип `buildings.id`, как у бота), обе с `intent_id` (`:build_start`, `:upgrade`). PRG: `/play?view=base&b=…&section=…&key=…`. После мутации — обзор этой базы.
- Открытие обзора — `BaseScreenService::open()` без `chat_id`: визит (если игрок на базе) и онбординг-подсказки в чат персонажа — у веб-игрока виртуальный, `WebDelivery` кладёт во входящие (тест: `last_visited_at` записан, «Построй первую постройку» во `web_inbox`).
- Мост: кнопки экрана базы бота без нативного экрана (маяки, ангар, декор, развитие, склад, телепорт, снос/переезд, снос постройки, «Разбить лагерь», карточки зданий `building_<id>_<Key>_b<id>` — там роботы/теплица/маяки/дрон/ремонт, экран нехватки `genericBuildInfo_<Key>_b<id>`) — `op=bridge` с той же `callback_data`. Путь бота: «🏠 База» текстом нижнего меню → (если нужно) `Base_b<id>` на пикере → `construction_b<id>` / `Build_b<id>` → кнопка; ступени необязательные, кнопка жмётся, только если стоит на сообщении бота. Раскладка кнопок — как в `BaseServiceMessageFormatter::baseBuildings()` (обе ветки `craftBaseHubEnabled`).
- Док: «🏠 База»/«База» → нативный экран. Тест `testDockMeButtonOpensNativeViewOthersStayOnTheBridge` проверял мост на «🏠 База» — переведён на «📋 Дела» (база теперь нативная).
- HUD: стройка — обычная задача `in_work`, строка задач показывает её с таймером `data-ends-at` без правок `hud.php`/`CharacterSheetService` (тест на ответе `build_start`); после завершения completion-handler снимает `in_work` — строка уходит. `CharacterSheetService`, `hud.php`, `dock.php`, `native_craft.php`, `wildworld-play.js` не менялись: док уже рисует нативные кнопки по `viewForDockLabel()`, таймер тикает по общему `data-ends-at`. Поэтому `?v=` у `wildworld-play.js` не поднят (файл не менялся); у `wildworld-ui.css` — `v=14`.
- UI-kit: блок «База — пикер, обзор со стопками, …» + зеркало CSS в inline-стилях `ui-kit.html`. Новые классы `play-base-*` — только токены (0 радиусов, 0 теней).
- Хвосты N3: `CraftOrderService::FISH_RECIPES` — публичная, единственный список; `CampfireCookingSelect::FISH_RECIPES` ссылается на неё (имя сохранено — его читают тесты костра). Замок раздела в сетке (`.play-kb-grid .play-kb-btn.is-locked`) — во всю строку и `overflow-wrap: break-word; hyphens: auto` вместо `anywhere` — «Профессиональный» не рвётся посреди слова.
- Вне списка файлов story (вынужденно): `tests/unit/Views/PlayViewsTest.php` — фикстура дока несла «🏠 База» и ждала для неё мост; после ask 2 кнопка нативная, фикстура переведена на «📋 Дела» (остаётся мостом). Смысл тестов не менялся.
- Не сделано кодом (закрывает Queen в конце сборки): Tier-2 визуальный проход 375/768/1440 и Tier-3 живой проход на preprod (asks 7, 10) — браузер не запускался.

## Findings
- Превью апгрейда не показывает эффект уровня «сейчас / после» (контракт плана `effect_now/effect_next`): у валидатора и `Config\BuildingUpgrades` этих данных нет, эффект уровней живёт в витрине «🏗 Развитие базы» бота — на неё ведёт кнопка моста обзора.
