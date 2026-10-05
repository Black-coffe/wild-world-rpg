# Цена партии крафта видна до и после запуска (plan)

**Tier:** 2 · **Spec slug:** `craft-batch-price-confirm` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-158 (строка правды о времени), ADR-190 (ядро `CraftOrderService` + рендереры), ADR-182 (форма `craft_again_callback`), ADR-024 (баланс в GameSettings), ADR-134 (tips), ADR-020 (media-off)
**Depends on:** `craft-quantity-parity-01` (v0.51.663, ряд кнопок количества на T3), W2.N3-01 (ядро старта)

## Goal
Игрок, который жмёт «Крафт 50шт», должен знать цену до списания и видеть, что списано, после. Карточка T3
подписывает золото «за 1 шт.» и показывает время одной штуки; стартовое сообщение получает строку «Списано»;
крупная партия (по штукам или по золоту, пороги в GameSettings) проходит через экран подтверждения с итогом
партии. Проверка встаёт в `GenericCraftActionStart` — общий вход всех кнопок `genericCraft_<Key>_<qty>`,
поэтому защищены все рецепты, а не только T3. Само правило (порог) живёт в ядре `CraftOrderService::start()` —
единственной точке старта для бота и `/play`, — а оба клиента только рендерят ответ `CONFIRM_REQUIRED`.

## Assumptions
- Подтверждённый колбэк — `genericCraft_<Key>_<qty>_ok` (4-й сегмент). Самый длинный ключ даёт 42 байта < 64. Поле `craft_again_callback` рецептов и регулярку `CraftShortageService::shortfallRecipeKey()` не трогаем (ADR-182).
- Итог экрана подтверждения берётся из `CraftOrderService::preview()` — того же ядра, что и старт; отдельной арифметики в рендерере нет.
- «Списано» — из того, что `start()` реально списал (`consumed`, уже пишется в `task_settings`); `start()` начинает его возвращать. Новых колонок и таблиц нет → WipeManifest не меняется (seed в `game_settings`/`game_tips`).
- Постановка в очередь (`QUEUED`) тоже списывает сразу, поэтому подтверждение срабатывает и для неё, а строка «Списано» добавляется и в сообщение «В очередь поставлено».
- Старые отдельные `StartCraft…2Action` вне скоупа: работают по одной штуке. Мост `op=bridge` пускает только `genericCraft_<Key>_1` (экран нехватки) и подтверждения не встретит.
- Флаг подтверждения по умолчанию `false`: любой будущий вызов `start()` без флага получает защиту, а не обходит её.
- Пороги по умолчанию: 25 шт и 50 000 золота; 0 выключает условие.
- `wave-check.sh` даёт `verify-gap` у story 02 — ложное срабатывание: `for tok in $(...)` (`scripts/wave-check.sh:195`) без кавычек раскрывает glob `app/Database/Migrations/*.php` в уже существующие файлы, и новые миграции story с ними не совпадают. Команда — дословная ячейка `## Commands` и новые миграции проверяет. Чинить рамку — вне этого скоупа (кандидат для `/vulyk-evolve`).
- Вердикт guide — нет (правка экрана, не новая механика); tips — да, `крафт`.

## Stories

**Wave 1**
- `craft-batch-price-confirm-01` — карточка T3 «за 1 шт.» + время штуки; строка «Списано» в старте и очереди (asks 1, 2, 6)

**Wave 2**
- `craft-batch-price-confirm-02` — правило в ядре (`CONFIRM_REQUIRED`), экран подтверждения в боте, пороги в GameSettings, совет; Tier-3 смоук (asks 3, 4, 5, 6, 7, 8, 9)

**Wave 3**
- `craft-batch-price-confirm-03` — панель подтверждения в `/play` из ответа ядра; Tier-2 на 3 вьюпортах (asks 10, 11)

## Contracts
- `CraftOrderService::start()` → ключ `consumed`: `array{gold:int, resources:array<string,int>, crafted_items:array<string,int>}` — итог за партию (рюкзак+склад сложены).
- `CraftOrderService::start(int $characterId, string $recipeKey, int $qty, bool $confirmed = false)` → при `needsConfirm && !confirmed` код `CONFIRM_REQUIRED` + итог `preview()`, ничего не списано; `preview()` → `needs_confirm:bool`.
- `CraftBatchConfirmPolicy::needsConfirm(int $qty, int $goldTotal): bool` — читает `craft.confirm.min_qty` / `craft.confirm.min_gold`, 0 = условие выключено.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

- 2026-10-05 — триггер: поправка владельца во время сборки («Your phrase «от 25 штук и дороже определённой суммы» was implemented as «or», so even cheap batches of 25+ will ask for confirmation.»). Решение: `CraftBatchConfirmPolicy::needsConfirm` — И (`qty ≥ min_qty && gold ≥ min_gold`; 0 выключает своё условие, оба 0 — никогда); тексты сида настроек и совета переписаны (миграции ещё нигде не применены); тесты — дешёвая партия 25+ стартует без вопроса. Ask 3 читать с И. Отвергнуто: оставить ИЛИ и поднять `min_qty` — бинты и еда на 100 шт. всё равно спрашивали бы.
- 2026-10-05 — триггер: `close-story` story 02, полный набор упал фатально: шпион `CraftOrderService` в `tests/database/PlayViewControllerTest.php:1573` переопределяет `start()` старой сигнатурой (класс «смена сигнатуры ломает тест-подклассы»). Решение: `PlayViewControllerTest.php` добавлен в `## Files` story 02 — только сигнатура и новые ключи ответа шпиона (`consumed`, `batch`, `needs_confirm`); story 03 продолжает владеть файлом для веб-тестов. Отвергнуто: ждать story 03 — набор красный на каждом коммите между ними.
- 2026-10-05 — триггер: замечание владельца после утверждения («Если на сайте в /play есть выбор количества, там партия может запуститься без подтверждения… чтобы он не ломал и не рассинхронивал логику»). Решение: порог переезжает из рендерера бота в ядро `CraftOrderService::start()` (флаг `confirmed`, код `CONFIRM_REQUIRED`), story 02 расширена на ядро, добавлена story 03 (рендер в `/play`). Отвергнуто: отдельная проверка в `WebNativeScreenService` — вторая копия правила, ровно тот рассинхрон, о котором предупредил владелец. Утверждение снято до повторного «да».

**Approved:** Andrei, 2026-10-05 (повторно, после правки про /play)
**Briefed:**
**Branch:** vulyk/craft-batch-price-confirm
**Checked:**
**Council:** GREEN round 1, 2026-10-05, at da47fc7a, pack 687cc060b781
**Council:** GREEN round 2, 2026-10-05, at eb5f0d33, pack 687cc060b781
**Shipped:**
