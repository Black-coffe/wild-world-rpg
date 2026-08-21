---
story: craft-shortfall-buy-fix-02
spec: craft-shortfall-buy
status: done
tier: 3
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Экран нехватки: сделать кнопку входа и считать тем же количеством, что и сделка

## Goal
Фича собрана и физически недостижима: `craftBuy` не отправляет ни одна клавиатура в игре. Экран
нехватки получает работающую кнопку входа и перестаёт показывать сумму за количество, которое
сделка провести не может.

## Requirements
> сделать возможность крафтить даже если недостаточно какого-то материала или компонента для крафта вещи
> и процент налога за опт указываем в тексте, игрок должен видеть и понимать чего не хватает и сколько за это придлется заплатить!
> Недостаточно ресурсов, чтобы скрафтить хотя бы 1 шт.

## Files
- app/Services/Craft/CraftShortageService.php
- tests/unit/Services/Craft/CraftShortageServiceTest.php

## Non-goals
- Не трогать `CraftShortfallBuyAction` — он чинится в fix-01.
- Не менять формулу и DTO в `CraftShortfallBuyService` — это fix-03.
- Не менять порядок экрана: блок и кнопка остаются НИЖЕ «⛏ Добыть» и вариантов «собрать самому».
- Не изобретать новый `callback_data`: контракт задан в `## Contracts` плана.

## Map slice
memory/map/craft.md; `.claude/rules/telegram-ux.md`, `.claude/rules/player-facing.md`.

## Acceptance criteria
- [ ] 🔴 На экране нехватки есть работающая кнопка входа в докупку, и её `callback_data` совпадает с контрактом из плана. Тест краснеет, если кнопка исчезнет.
- [ ] 🔴 Когда докупка недоступна, вместо кнопки показано lock-состояние с объяснением причины и путём вперёд — а не молчаливое отсутствие.
- [ ] 🔴 Количество, которым считается блок, совпадает с тем, которое реально проведёт сделка, включая `max_units_per_purchase`. Тест на расхождение: игрок запросил больше лимита.
- [ ] Кнопка не появляется, когда докупать нечего.
- [ ] Ряды кнопок проходят через общий нормализатор — одиночек в строке не остаётся.
- [ ] Порядок вариантов на экране не изменился.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Craft/`

## Notes
Полный разбор находок — `docs/specs/craft-shortfall-buy/review-findings.md`. Читай его, прежде чем править. Критическая 1, крупная 6.

## Implementation notes

- `CraftShortageService::shortfallBuyBlock()` теперь возвращает `{lines, button}`: `button` —
  рабочая `craftBuy_<RecipeKey>_<qty>` (контракт `plan.md`), появляется только когда
  `quote()->available === true` И у рецепта есть `craft_again_callback` (`genericCraft_<Key>_<qty>`,
  единственное поле, где `RecipeKey` уже есть во всех ~105 рецептах `CraftRecipes`).
- Количество зажимается новым `maxUnitsPerPurchase()` (ключ `craft.shortfall_buy.max_units_per_purchase`,
  тот же, что режет сделку в `CraftShortfallBuyAction`) ДО вызова `quote()` — экран и сделка теперь
  считают на одном числе; при клампе добавлена строка-пояснение в текст.
- `keyboard()` собирает хвост (кнопка докупки + «Инвентарь» + «Назад») через `ButtonPacker::pack()` —
  одиночного ряда с новой кнопкой не бывает. Ряд «⛏ Добыть» как был единственной кнопкой в строке,
  так и остался — это пред-существующее поведение, вне объёма правки.
- Легаси-рецепты брони/маяков (`StartCraftArmor*2Action`, вне `Config\CraftRecipes`) не несут
  `craft_again_callback` → кнопки для них по-прежнему нет, только текст. Известный, отдельно
  зафиксированный пробел (находка 7 ревью), не в объёме этой story.
- Тесты: `tests/unit/Services/Craft/CraftShortageServiceTest.php` — 5 новых тестов (рабочая кнопка,
  упаковка ряда, отсутствие кнопки при отказе, кламп количества с проверкой quantity, переданной в
  `quote()`, через капчурящий стаб).

## Findings
