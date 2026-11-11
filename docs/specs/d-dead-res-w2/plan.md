# D-DEAD-RES волна 2 — пять лекарств из хвоста (plan)

**Tier:** 2 · **Spec slug:** `d-dead-res-w2` · **Brief:** [brief.md](brief.md)
**Model profile:** opus-builds
**Governed by:** ADR-024 (баланс в GameSettings), ADR-020 (media-off), ADR-022 (арт), ADR-130 (два верстака), ADR-134 (советы), ADR-190 (ядро крафта бот+веб); карточки `docs/defects/craft-recipe-three-places.md`, `sibling-buttons-solo-rows`, `magic-terms-in-player-text`
**Depends on:** `d-dead-res` (v0.51.709, `7afe9ecf`) — эталон пути и `CraftRecipesDeadResourcesTest`; `d-dead-res-heal-line`

## Goal
Последние 14 ресурсов без потребителя становятся сырьём пяти полевых лекарств первого верстака, сгруппированных
по биомам добычи. Путь — ровно волны 1: `drug` + `tasks` в минутах + `medical.<snake>.heal_*` в `GameSettings` +
рецепт/каталог/меню/Worker/`Consumables::MEDICINE` + арт + совет. После волны D-DEAD-RES закрыт: без потребителя
остаются только четыре редких трофея ветеранов, сознательно.

| Предмет (`name_eng`) | Сырьё на 1 шт. | Лечит ❤️ / ⚡ | Ур. | Сырьё по sell | Цена |
|---|---|---|---|---|---|
| 🦭 Тюлений бальзам (`SealBalm`) | Масло тюленя 6 · Арктические цветы 3 · Лед 10 | 12 / 25 | 6 | 126.35 | 110 |
| 🧫 Термальная закваска (`ThermalCulture`) | Термофильные микроорганизмы 6 · Обсидиан 5 | 8 / 28 | 5 | 84.55 | 75 |
| 🫙 Пещерный жир (`CaveTallow`) | Подземные животные 6 · Сталактиты и сталагмиты 5 | 22 / 8 | 4 | 74.10 | 65 |
| 🏜 Пустынная соль (`DesertSalt`) | Миражные кристаллы 4 · Пустынный агат 3 · Фоссилии 1 · Драгоценные камни 1 | 10 / 35 | 15 | 128.25 | 115 |
| 💉 Обезболивающий укол (`PainkillerShot`) | Медицинское сырьё 1 · Яд животных 4 · Природный газ 2 | 40 / 15 | 18 | 275.50 | 245 |

Уровень — максимум `level_required` сырья. Цена × 1.10 ≤ сырьё по `sell_price` (прод 2026-11-11), `gold_required` нет.

## Assumptions
- Количества сырья — в `Config\CraftRecipes`, как у всех рецептов (перенос в GameSettings — долг D8); время —
  `tasks.min/max_duration` в минутах, как в волне 1 (plan delta волны 1: часовой override сделал бы лекарство в разы медленнее).
- Лекарства не снимают раны — как в волне 1; совет говорит об этом прямо.
- `UsePharmacyAction` и `MedicalRecipePreviewT3Action` не трогаем: волна 1 сделала их data-driven и нейтральными к
  верстаку. Если итог крафта (`GenericCraftCompletionHandler`) берёт строку лечения не из ключа — это находка, story расширяется plan delta.
- Меню: 12 + 5 = 17 кнопок → ряды по 2 и последний ряд из 3.
- Тест волны 2 — отдельный файл `CraftRecipesDeadResourcesWave2Test.php`, читает `ITEMS` новой миграции; тест волны 1 не трогаем.
- Арт: 5 картинок через `php spark images:generate` (платная генерация, ключ в `.env`), стиль ADR-022 — шаг story 02.
- Цены сырья — прод; на preprod часть `sell_price` расходится (`preprod-settings-drift`) — тест держит прод-снимок.

## Audience surfaces

| Что | Где бывает аудитория (доказательство) | Где стоит вход |
|---|---|---|
| пять новых рецептов | bot, play (крафты волны 1 — 6 записей `crafted_items_log` id 128–131 с 02.11 через «⚕️ Медикаменты» и каталог `/play`) | bot, play |
| совет Роби | bot, play (совет дня уходит в оба клиента) | bot, play |

## Stories

**Wave 1**
- `d-dead-res-w2-01` — данные и рецепты: две миграции (`crafted_items`+`tasks`, GameSettings), рецепты, каталог, меню, Worker, полка, тест «не печатает золото» (red).

**Wave 2**
- `d-dead-res-w2-02` — контент: арт и `ImageRegistry`, совет Роби, канон, tech-writing, ROADMAP закрыт.

## Contracts
- Ключи: `SealBalm`, `ThermalCulture`, `CaveTallow`, `DesertSalt`, `PainkillerShot` — одинаковые в `CraftRecipes`,
  `CraftCatalog`, `crafted_items.name_eng`, `tasks.name = craft<Key>`, `ImageRegistry` (`craft/<snake>`).
- GameSettings: `medical.<snake>.heal_health` / `.heal_tired` (`seal_balm`, `thermal_culture`, `cave_tallow`,
  `desert_salt`, `painkiller_shot`), категория и границы — как у `…100010` волны 1.

## Integration gate
Десять строк `Full suite` из `## Commands` (unit1, unit2, db1–db8), по одной на шаг.

## Descoped

*(empty)*

## Plan deltas

**Approved:** владелец («Утверждаю»), 2026-11-11
**Briefed:**
**Branch:** vulyk/d-dead-res-w2
**Checked:**
**Council:**
**Shipped:**
