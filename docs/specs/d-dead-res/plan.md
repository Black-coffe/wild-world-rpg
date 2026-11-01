# D-DEAD-RES — лекарства из мёртвого сырья (plan)

**Tier:** 2 · **Spec slug:** `d-dead-res` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-024 (баланс в GameSettings), ADR-020 (media-off), ADR-022 (арт), ADR-094 (просрочка лекарств), ADR-130 (два верстака), ADR-134 (советы), ADR-190 (ядро крафта для бота и веба); карточка `docs/defects/craft-recipe-three-places.md`
**Depends on:** S19 T3-медицина (`58b55392`) — эталон data-driven лечения; `CraftOrderService` (W2.N3)

## Goal
Семь самых накопленных ресурсов без потребителя становятся сырьём четырёх новых лекарств первого верстака.
Лечение — уже работающий путь Аптечки и Провизии (`type='drug'`, числа в `GameSettings`). Пул добычи, редкие
трофеи, запасы игроков и старые рецепты не трогаем. Предметы — предложение плана, состав утверждает владелец.

| Предмет (`name_eng`) | Сырьё на 1 шт. | Лечит (здоровье / выносливость) | Уровень |
|---|---|---|---|
| 🐍 Сыворотка от яда (`Antivenom`) | Змеиный яд 6 · Снег 10 | 25 / 0 | 5 |
| 🩹 Хитиновый пластырь (`ChitinPatch`) | Тропические насекомые 12 · Капельники 4 | 18 / 5 | 2 |
| 🧴 Серная мазь (`SulfurOintment`) | Сера 5 · Вулканический пепел 8 | 10 / 20 | 5 |
| 💊 Минеральные таблетки (`MineralTablets`) | Редкие руды 3 · Капельники 3 · Снег 5 | 5 / 30 | 7 |

Уровень — максимум `level_required` сырья, чтобы варить могли все держатели. Числа лечения сидятся в `GameSettings`
рядом с действующими (Бинт 18, вершина первого верстака 40) и меняются из админки. Цена предмета — по формуле
«не печатает золото» от сырья по `sell_price` (ask 5): ориентир Сыворотка ≤ 70, Пластырь ≤ 31, Мазь ≤ 53, Таблетки ≤ 63.

## Assumptions
- Количество сырья в рецепте живёт в `Config\CraftRecipes`, как у всех 115 рецептов; перенос количеств в
  `GameSettings` — долг D8, не эта спека. Лечение и время крафта — в `GameSettings`, как у S19.
- Новые лекарства лечат здоровье и выносливость, но **не снимают раны**: вписать их в лечение ран — новая механика вне asks.
- Бот: карточка рецепта переиспользует генерик-превью `MedicalRecipePreviewT3Action` (prefix `craftPreviewT3Medical_`,
  гейт по верстаку читается из рецепта). Если его текст называет профессиональный верстак, builder делает текст
  нейтральным в том же файле, а не заводит четыре legacy-экрана `*Craft1Action`.
- Меню: четыре кнопки добавляются в «⚕️ Медикаменты» первого верстака (`MedicalCraft1Action`), рядами по 2.
- Пять редких и хвост из 14 ресурсов остаются без потребителя; в ROADMAP D-DEAD-RES сужается до «следующая волна —
  хвост 29–15 держателей», с причиной, почему редкие не выводим.
- Арт: 4 картинки через `php spark images:generate` (платная генерация, ключ уже в `.env`), стиль ADR-022.

## Stories

**Wave 1**
- `d-dead-res-01` — данные и рецепты: миграции `crafted_items` + `tasks` + `GameSettings`, 4 рецепта, каталог веба, меню бота, тест «не печатает золото» (red).

**Wave 2**
- `d-dead-res-02` — контент: арт и `ImageRegistry`, совет Роби, канон, tech-writing, ROADMAP.

## Contracts
- Ключи рецептов: `Antivenom`, `ChitinPatch`, `SulfurOintment`, `MineralTablets` — одинаковые в `CraftRecipes`,
  `CraftCatalog`, `crafted_items.name_eng`, `tasks.name = craft<Key>`, `ImageRegistry`.
- GameSettings: `medical.<snake_name_eng>.heal_health` / `.heal_tired` (snake: `antivenom`, `chitin_patch`,
  `sulfur_ointment`, `mineral_tablets`), категория как у S19.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

- **Время крафта — `tasks.min/max_duration` (минуты), а не GameSettings** (d-dead-res-01). GameSettings-override
  `duration_override_setting_key` хранит целые часы (hard_min 1 ч): полевое лекарство варилось бы ≥ 60 мин против
  3–19 мин у соседей. Так время задано у всех рецептов первого верстака. Минуты в админке — отдельная правка
  `CraftDurationService`, кандидат в долг D8.
- **Редких трофеев четыре, не пять** (d-dead-res-02): после семи из 25 остаются 18 — Корона, Книга, Сердце, ТВЭЛы и
  хвост из 14.
- **Полка Аптечки** (repair round 1): новые `drug` вписаны в `Config\Consumables::MEDICINE`, иначе `shelfOf()`
  отправлял их в «🍲 Провизия».
- Цены сырья — прод-снимок 2026-11-01; на preprod «Вулканический пепел» 13.30 против 3.80 (`preprod-settings-drift`).

**Approved:** владелец («утверждаю»), 2026-11-01
**Briefed:**
**Branch:** vulyk/d-dead-res
**Checked:**
**Council:**
**Council:** RED round 1, 2026-11-01, at a0b3c958, pack c838b68ea4c9
**Council:** GREEN round 2, 2026-11-01, at 2dbee966, pack 4cfa3dfc4269
**Shipped:**
**Shipped:** v0.51.709, 2026-11-01, at 14d21c9f - merged to develop, publish pending
