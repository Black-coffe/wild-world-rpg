---
story: w2-n3-craft-queue-03
spec: w2-n3-craft-queue
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 3
blocked_by: [w2-n3-craft-queue-02]
---

# Веб «🔨 Крафт»: верстаки, карточка рецепта, очередь; хвосты W2.N2

## Goal
`Config\CraftCatalog` — индекс «верстак → категория → ключи `CraftRecipes`», наполненный по спискам кнопок нынешних экранов бота (Верстак 1, Стандартный, Проф.), чтобы деревья совпадали.

`/play?view=craft` (партиал `site/_play/native_craft.php`, HUD и док):
- верстаки с замками «🔒 Название (нужно: …)» и путём к требованию (те же правила, что у бота);
- категории → карточки: общая карточка рецепта из `CraftOrderService::preview()` — сырьё (есть/нужно, пул), время, цена, условия;
- количество: кнопки шагов `CraftCardHelper` + поле «своё число» (1..`max_qty`), `op=craft_start`;
- очередь рядом (на мобильном — под каталогом): активные с живым таймером, ожидающие с позицией и «≈», «Отменить» (`op=craft_cancel`);
- нехватка — экран через мост (`op=bridge`) на существующий экран бота.

Док «🔨 Крафт» открывает этот экран вместо моста. Всё работает без JS (формы + PRG); компоненты сначала в `ui-kit.html`.

Хвосты W2.N2: `CharacterSheetService::hud()` у строки `Marching` не отдаёт `ends_at = start_time` (берёт ETA из статуса Похода или `null`), поэтому строка задач не пишет «готово»; клетка карты на 375 — не меньше удобной тап-зоны без горизонтального скролла.

## Requirements
> 2. Индекс «верстак → категория → рецепты» из CraftRecipes; в /play — нативные верстаки с замками и путём к требованию, категории и общая карточка рецепта (сырьё из рюкзака и склада, время, цена, условия); каталог бота не меняется.
> 3. Количество в веб-карточке — кнопки шагов бота плюс поле «своё число» с потолком по сырью.
> 4. Очередь в /play рядом с крафтом: активные с живым таймером, ожидающие с позицией и оценкой времени, отмена ожидающих; завершение крафта видно в вебе, в том числе у игрока без Telegram.
> 6. Верстаки, карточка и очередь — сначала в ui-kit.html; без горизонтального скролла на 375/768/1440.
> 7. Хвосты W2.N2: строка задач не пишет «готово» у идущего Похода; клетка карты на 375 — удобная для пальца.

## Files
- app/Config/CraftCatalog.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Services/Player/CharacterSheetService.php
- app/Views/site/_play/native_craft.php
- app/Views/site/_play/dock.php
- app/Views/site/_play/hud.php
- app/Views/site/_play/native_map.php
- app/Views/site/_layout/meta.php
- public/assets/css/wildworld-ui.css
- public/assets/js/wildworld-play.js
- public/ui-kit.html
- tests/unit/CraftCatalogTest.php
- tests/database/PlayViewControllerTest.php
- phpstan-baseline.neon

## Non-goals
- Не менять экраны крафта бота и ядро (story 01–02), кроме вызовов его API.
- Не делать поиск/фильтры по рецептам (не просили в Asks).
- Не рисовать числа баланса в тексте экрана мимо сервиса: всё из `preview()`.

## Map slice
`memory/map/website.md` («Игра на сайте», W2.N2); `memory/map/craft.md` (Entry points).

## Acceptance criteria
- [ ] Тест: каждый ключ `CraftCatalog` есть в `CraftRecipes`; деревья верстаков совпадают с кнопками экранов бота.
- [ ] Веб: док «🔨 Крафт» → верстак → категория → карточка → старт (кнопкой и «своим числом») → очередь с таймером → отмена ожидающего; повтор `intent_id` не стартует второй раз; без JS — PRG.
- [ ] Замок верстака показывает требование и путь; поле «своё число» не пропускает больше `max_qty` и меньше 1.
- [ ] HUD: у идущего Похода строка задач не пишет «готово»; клетка карты на 375 не меньше тап-зоны, без горизонтального скролла.
- [ ] Компоненты в `ui-kit.html`; 0 радиусов и теней, только токены; `?v=` в `meta.php` поднят.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
