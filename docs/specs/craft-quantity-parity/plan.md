# Паритет крафта «по несколько штук» для T3-инструментов (plan)

**Tier:** 2 · **Spec slug:** `craft-quantity-parity` · **Brief:** [brief.md](brief.md)
**Governed by:** CLAUDE.md — TIPS-COVERAGE, GUIDE-COVERAGE, UX-DISCOVERABILITY, MEDIA-OFF; правило «ноль одиночек в ряду» (`packButtonRows`)
**Depends on:** ничего не ждёт; бэкенд партийного крафта уже живой

## Goal
Карточка T3-утилит (сапёрная лопата, алмазная кирка, золотая мотыга) даёт игроку тот же ряд
кнопок количества, что и карточки обычных рецептов, вместо единственной зашитой «🛠 Скрафтить 1 шт».
Механику крафта партией менять не нужно: `GenericCraftActionStart` разбирает `genericCraft_<Key>_<qty>`
и честно множит на qty весь путь — проверку сырья, списание, длительность и `task_settings.quantity`;
`GenericCraftCompletionHandler` кладёт партию в стак. Единственная причина отказа — UI-хардкод `_1`.

## Assumptions
- Ступени количества берутся из существующего набора обычных карточек (`[1, 5, 10, 25, 50, 100]`),
  недоступные по сырью ступени не показываются — как в `BasicMedKitCraft1Action`. Отдельной лесенки
  для T3 не заводим (решение владельца, `## Answers` в брифе).
- Общего хелпера рядов количества в репозитории НЕТ: формула максимума продублирована примерно в 27
  карточках. Мы заводим один переиспользуемый метод в уже существующем `app/Services/Craft/CraftCardHelper.php`
  и зовём его из T3-экрана. Остальные 27 карточек на него НЕ мигрируем — это отдельная задача.
- Прочность: при доливе в существующий стак `GenericCraftCompletionHandler` обновляет только `quantity`,
  `durability_count` строки не трогает. То есть партийный крафт не чинит стак и не обнуляет заряды —
  поведение то же, что при N одиночных крафтов подряд. **Мы его не меняем**, но story 04 закрепляет
  его характеризующим тестом, чтобы будущая правка не проехала молча.
- Число баланса здесь не появляется: набор ступеней — не баланс, а форма экрана, и он переиспользуется,
  а не назначается заново. GameSettings-ключ не заводим.

## Stories

**Wave 1**
- `craft-quantity-parity-01` — ряд кнопок количества на карточке T3-утилит + переиспользуемый метод в `CraftCardHelper`.
- `craft-quantity-parity-02` — `craft_again_callback` у T3-инструментов ведёт назад на карточку, а не на зашитый крафт 1 шт.
- `craft-quantity-parity-03` — seed-миграция совета «инструменты крафтятся партией» (категория «крафт»).

**Wave 2**
- `craft-quantity-parity-04` — тесты: ряд кнопок для T3, отсечение ступеней по сырью, характеризация прочности при доливе.

## Contracts
- `App\Services\Craft\CraftCardHelper::quantityRows(string $recipeKey, int $maxAffordable): array` —
  возвращает готовые ряды inline-кнопок `genericCraft_<recipeKey>_<qty>` по ступеням `[1,5,10,25,50,100]`,
  отфильтрованным по `$maxAffordable`, упакованные через `ButtonPacker` (2–3 в ряд, ноль одиночек).
  Ступень `1` присутствует всегда, если `$maxAffordable >= 1`. Story 01 определяет метод; story 04 тестирует
  ровно эту сигнатуру.
- callback-формат `genericCraft_<RecipeKey>_<qty>` не меняется — story 02 опирается на него как есть.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress` и `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`,
плюс `bash scripts/wave-check.sh docs/specs/craft-quantity-parity` перед каждой волной.
Tier-3 живой смоук в Telegram Web (карточка сапёрной лопаты: ряд кнопок, крафт партией, инвентарь) — на стадии 04 цикла,
зелёный Tier 1 здесь ничего про Telegram-рендер не доказывает.

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei, 2026-09-06
**Branch:** vulyk/craft-quantity-parity
**Checked:** <written by scripts/human-check.sh>
**Shipped:** <written by scripts/ship-check.sh --record>
