---
story: craft-quantity-parity-01
spec: craft-quantity-parity
status: done
tier: 2
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Ряд кнопок количества на карточке T3-утилит

## Goal
Карточка рецепта T3-утилит показывает ряд кнопок количества (`genericCraft_<Key>_<qty>` по ступеням
`[1, 5, 10, 25, 50, 100]`, отфильтрованным по тому, сколько игрок реально может себе позволить), вместо
единственной зашитой кнопки «🛠 Скрафтить 1 шт». Ряды строит новый переиспользуемый метод
`CraftCardHelper::quantityRows()`, а не третья копия формулы внутри экрана.

## Requirements
> [06.09.2026 12:40] Arseny: Не баг конечно, но не удобно: почему саперные лопаты, алмазные кирки и тд нельзя крафтить по несколько штук, как обычные инструменты?

> Набор кнопок количества у T3-инструментов — как у обычных рецептов, тот же генератор, а не своя лесенка.

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/WorkbenchProfessional/UtilityRecipePreviewT3Action.php
- app/Services/Craft/CraftCardHelper.php

## Non-goals
- НЕ мигрировать остальные ~27 карточек крафта на новый метод — формула максимума там продублирована,
  это отдельная задача со своим ревью. Трогаем только T3-экран.
- НЕ менять `GenericCraftActionStart`, `GenericCraftCompletionHandler` и формат callback'а — партийный
  крафт уже работает, правка чисто в отображении карточки.
- НЕ заводить GameSettings-ключ под ступени: это форма экрана, а не число баланса, и она переиспользуется.
- НЕ придумывать свою лесенку ступеней для T3 (владелец выбрал паритет с обычными рецептами).
- НЕ выносить одинокую кнопку в ряд: 2–3 в ряд через `ButtonPacker`, как требует правило «ноль одиночек».

## Map slice
`memory/map/craft.md` — Entry points и Gotchas; `memory/map/telegram.md` — action-handler'ы и callback-роутинг.

## Acceptance criteria
- [ ] `CraftCardHelper::quantityRows(string $recipeKey, int $maxAffordable): array` существует и возвращает
      ряды кнопок с `callback_data` вида `genericCraft_<recipeKey>_<qty>`.
- [ ] Ступени выше доступного количества не показываются; при `$maxAffordable >= 1` ступень `1` есть всегда.
- [ ] 🔴 В доступное количество входит ЗОЛОТО: `gold_required` умножается на qty в `GenericCraftActionStart`
      (строки 162 и 440), а у T3-инструментов это 5000–6000 за штуку — в отличие от обычных карточек, где
      ценника нет. Ступень, которую игрок не потянет по золоту, показывать нельзя: это мёртвая кнопка,
      отказ прилетит уже после нажатия.
- [ ] `required_crafted_items` (Профессиональный верстак) в максимум НЕ входит: это гейт владения без
      списания (`GenericCraftActionStart:375-378`), он не масштабируется с количеством.
- [ ] Ряды упакованы по 2–3 кнопки, одиночной кнопки в ряду не остаётся.
- [ ] Карточка T3-утилит строит кнопки крафта этим методом; строки с зашитым суффиксом `_1` в ней не осталось.
- [ ] Подпись экрана остаётся самодостаточной в тексте (media-off): что за предмет, сколько стоит, сколько зарядов.
- [ ] Когда сырья не хватает даже на одну штуку, экран ведёт себя как раньше — кнопки крафта нет, причина названа текстом.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Tracer
Тонкий срез через все слои этой спеки: конфиг рецепта → расчёт доступного количества → построение
ряда кнопок → существующий callback `genericCraft_<Key>_<qty>`. Если на этом срезе вскроется, что
доступное количество для T3 считается иначе (золото, требования построек), остальные story перекраиваются.

## Implementation notes
- Раунд 1 (воркер): метод `CraftCardHelper::quantityRows()` + `STEPS = [1,5,10,25,50,100]`, карточка
  переведена на него, phpstan чист. Максимум считался по `resources` + `crafted_items`; золото не учтено —
  дефект формулировки story, исправлено в раунде 2.
- `CraftCardHelper::quantityRows(string $recipeKey, int $maxAffordable): array` добавлен (`STEPS = [1,5,10,25,50,100]`, паритет с `CampfireCookingSelect::QUANTITY_STEPS`). Фильтрует ступени по `$maxAffordable`, пакует через `ButtonPacker::pack()`. Возвращает `[]` при `$maxAffordable < 1` (вызывающий код уже не показывает кнопки крафта на этом пути).
- `UtilityRecipePreviewT3Action` теперь считает `$maxAffordable` = `min(floor(have/need))` по всем `resources` + `crafted_items` (компоненты), тем же способом, что `calculateMaxCraftableItems()`/`maxCraftableItems()` в сиблинг-карточках; `required_crafted_items` (одноразовый gate) в максимум не входит — он не расходуется на единицу. При `canCraft` строки берутся из `quantityRows()`; хардкод `_1` убран целиком.
- Tips/guide-вердикт: **нет** — это паритет-фикс существующей механики (кнопки количества), не новая player-facing поверхность; discoverability/онбординг не меняются.
- Tier-3 живой смоук не запускал (не было доступа/разрешения в рамках этой сессии worker'а) — рекомендую перед ship.
- Раунд 2 (воркер): предпосылка подтверждена по коду — `GenericCraftActionStart` умножает `gold_required`
  на `$this->quantity`/`$quantity` (строки 161-162 при списании, 439-440 при валидации) и отклоняет
  крафт с `insufficient_gold`, если `goldHave < goldRequired`. В `UtilityRecipePreviewT3Action` золото
  добавлено в расчёт `$maxAffordable` ДО секций resources/components: `$maxAffordable = (int) floor($goldHave / $goldNeed)`
  при `$goldNeed > 0`, иначе `PHP_INT_MAX`; дальше как раньше `min()` с ресурсами и компонентами.
  `required_crafted_items` не тронут (это gate, не расходуется на единицу — подтверждено, не менял).

## Findings
