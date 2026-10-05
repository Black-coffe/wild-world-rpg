# Цена партии крафта видна до и после запуска (plan)

**Tier:** 2 · **Spec slug:** `craft-batch-price-confirm` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-158 (строка правды о времени), ADR-190 (ядро `CraftOrderService` + рендереры), ADR-182 (форма `craft_again_callback`), ADR-024 (баланс в GameSettings), ADR-134 (tips), ADR-020 (media-off)
**Depends on:** `craft-quantity-parity-01` (v0.51.663, ряд кнопок количества на T3), W2.N3-01 (ядро старта)

## Goal
Игрок, который жмёт «Крафт 50шт», должен знать цену до списания и видеть, что списано, после. Карточка T3
подписывает золото «за 1 шт.» и показывает время одной штуки; стартовое сообщение получает строку «Списано»;
крупная партия (по штукам или по золоту, пороги в GameSettings) проходит через экран подтверждения с итогом
партии. Проверка встаёт в `GenericCraftActionStart` — общий вход всех кнопок `genericCraft_<Key>_<qty>`,
поэтому защищены все рецепты, а не только T3.

## Assumptions
- Подтверждённый колбэк — `genericCraft_<Key>_<qty>_ok` (4-й сегмент). Самый длинный ключ даёт 42 байта < 64. Поле `craft_again_callback` рецептов и регулярку `CraftShortageService::shortfallRecipeKey()` не трогаем (ADR-182).
- Итог экрана подтверждения берётся из `CraftOrderService::preview()` — того же ядра, что и старт; отдельной арифметики в рендерере нет.
- «Списано» — из того, что `start()` реально списал (`consumed`, уже пишется в `task_settings`); `start()` начинает его возвращать. Новых колонок и таблиц нет → WipeManifest не меняется (seed в `game_settings`/`game_tips`).
- Постановка в очередь (`QUEUED`) тоже списывает сразу, поэтому подтверждение срабатывает и для неё, а строка «Списано» добавляется и в сообщение «В очередь поставлено».
- Веб `/play` (`native_craft`) и старые отдельные `StartCraft…2Action` вне скоупа: веб уже рисует превью ядра, старые экшены работают по одной штуке.
- Пороги по умолчанию: 25 шт и 50 000 золота; 0 выключает условие.
- `wave-check.sh` даёт `verify-gap` у story 02 — ложное срабатывание: `for tok in $(...)` (`scripts/wave-check.sh:195`) без кавычек раскрывает glob `app/Database/Migrations/*.php` в уже существующие файлы, и новые миграции story с ними не совпадают. Команда — дословная ячейка `## Commands` и новые миграции проверяет. Чинить рамку — вне этого скоупа (кандидат для `/vulyk-evolve`).
- Вердикт guide — нет (правка экрана, не новая механика); tips — да, `крафт`.

## Stories

**Wave 1**
- `craft-batch-price-confirm-01` — карточка T3 «за 1 шт.» + время штуки; строка «Списано» в старте и очереди (asks 1, 2, 6)

**Wave 2**
- `craft-batch-price-confirm-02` — экран подтверждения крупной партии, пороги в GameSettings, совет; Tier-3 смоук (asks 3, 4, 5, 6, 7, 8)

## Contracts
- `CraftOrderService::start()` → ключ `consumed`: `array{gold:int, resources:array<string,int>, crafted_items:array<string,int>}` — итог за партию (рюкзак+склад сложены).
- `CraftBatchConfirmPolicy::needsConfirm(int $qty, int $goldTotal): bool` — читает `craft.confirm.min_qty` / `craft.confirm.min_gold`, 0 = условие выключено.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:**
**Branch:**
**Checked:**
**Council:**
**Shipped:**
