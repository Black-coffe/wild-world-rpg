---
story: craft-quantity-parity-04
spec: craft-quantity-parity
status: done
tier: 2
worker: worker-test
tracer: false
wave: 2
blocked_by: [craft-quantity-parity-01, craft-quantity-parity-02]
---

# Тесты: ряд количества у T3 и прочность при доливе в стак

## Goal
Тест закрепляет две вещи, которых сегодня не держит ни один гейт: (1) карточка T3-утилит отдаёт ряд
кнопок количества, отсечённый по доступному сырью, и (2) долив партии в существующий стак инструмента
не переписывает `durability_count` — то есть крафтом стак не «чинится» и заряды не теряются.

## Requirements
> [06.09.2026 12:40] Arseny: Не баг конечно, но не удобно: почему саперные лопаты, алмазные кирки и тд нельзя крафтить по несколько штук, как обычные инструменты?

## Files
- tests/unit/Craft/CraftQuantityParityTest.php

## Non-goals
- НЕ писать тест, который сканирует исходник на наличие строки/паттерна: сломанный метод оставит такой
  тест зелёным. Звать реальный метод и проверять его возврат.
- НЕ менять поведение прочности — это характеризующий тест существующего поведения, а не правка.
- НЕ трогать production-файлы: если для теста нужен шов, о котором story 01 не договорилась, — это
  находка в `## Findings`, а не самовольная правка чужой story.
- НЕ гонять полный набор параллельно с чем-либо: DB-тесты дерутся за общие таблицы.

## Map slice
`memory/map/craft.md` — `GenericCraftCompletionHandler`, `crafted_items_log`; `memory/map/data-layer.md`.

## Acceptance criteria
- [ ] Тест зовёт `CraftCardHelper::quantityRows()` напрямую и проверяет: ступени выше доступного количества
      отсутствуют, `callback_data` имеет вид `genericCraft_<Key>_<qty>`, одиночной кнопки в ряду нет.
- [ ] Граничные случаи названы явно: `maxAffordable = 0` (кнопок крафта нет), `= 1` (ровно одна ступень),
      значение выше самой большой ступени (лесенка не растёт бесконечно).
- [ ] Характеризующий тест долива: строка `crafted_items_log` с частично израсходованным `durability_count`
      после долива партии сохраняет прежний `durability_count`, а `quantity` вырастает на размер партии.
- [ ] Тест падает, если поведение долива изменится в любую сторону — и на «починку» до каталожного значения,
      и на обнуление.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Craft/CraftQuantityParityTest.php`

## Implementation notes

## Findings
