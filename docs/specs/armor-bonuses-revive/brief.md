# Brief — armor-bonuses-revive

**Date:** 2026-09-16
**Deliverable:** изменённый код (боёвка + обнаружение игроков)
**Tier:** 2

## Request (verbatim)

> Что делаем с мёртвыми спец-бонусами брони? → **Оживить механику**

Контекст решения (сообщение владельцу, на которое он ответил): `bonus_type`, `special_bonus`,
`penalty_type` не читаются боёвкой ни разу, а `EffectService` сравнивает JSON со строкой — условие
не срабатывает никогда. При этом игрокам в описаниях предметов обещаны отражение урона, оглушение,
маскировка, прибавка к скорости.

## Recon (2026-09-16, прямой SELECT на проде + разбор боевых путей)

**Что реально работает у брони сегодня:**

| Поле | Статус |
|---|---|
| `armor_value` | ✅ PvE: `armorEffect = armor/(100+armor)`, урон × `(1 − armorEffect)` |
| `physical/fire/poison_resistance` | ✅ Только PvP: `PvpDamageCalculator::computeArmorResistance`, clamp 0.9 |
| `armor_type` | ❌ display-only, в расчётах не участвует |
| `speed_modifier` / `stealth_modifier` | ❌ не читаются нигде |
| `bonus_type` / `special_bonus` / `penalty_type` / `penalty` | ❌ не читаются нигде |
| `required_level` / `required_strength` | ❌ при надевании не проверяются |

**Готовые точки подключения (хуки уже существуют, новых полей не нужно):**

- `EquipmentService::getEquipmentBonuses()` уже перебирает экипированную броню и читает `armor_value`
  — туда же встаёт разбор `bonus_type`.
- `BattleCharacter` уже несёт `incomingDamageMultiplier` / `outgoingDamageMultiplier` (E21, ADR-121,
  default 1.0) и `armorBonus`.
- `PlayerDetectionService` считает дистанцию и радиус обнаружения; скрытность там не учитывается
  (`grep stealth` = 0).

**Классификация 16 значений `bonus_type` на проде:**

| Группа | Значения | Вердикт |
|---|---|---|
| Хук готов | `damage_reduction`, `damage_reflection`, `melee_damage` | 🟢 в скоуп |
| Механика есть, хука нет | `stealth` | 🟢 в скоуп (отдельная story, меняет PvP) |
| Уже работает через `*_resistance` | `physical_resist`, `fire_resist`, `poison_resist` (4 фракционных) | ⚪ не трогаем — `bonus_type` там дублирует описание |
| Механики в игре НЕТ | `carry_capacity` (грузоподъёмности нет), `crit_chance` (крита в PvE нет), `speed`, `acrobatics`, `morale`, `phantom`, `knockback`, `electric_shield` | 🔴 вне скоупа |

⚠️ **Ловушка формата:** у 4 фракционных комплектов `special_bonus` — не JSON, а простая строка
(«Маскировка: +20% к скрытности…»). Парсер обязан это переживать без ошибок.

## Asks

1. `damage_reduction` действительно снижает входящий урон в PvE: бой в Тактическом бронекостюме
   логирует меньший входящий урон, чем тот же бой без него.
2. `damage_reflection` возвращает часть урона атакующему: в логе боя видна строка отражения, HP
   атакующего падает.
3. `melee_damage` увеличивает урон владельца брони наёмника в PvE.
4. `stealth` уменьшает шанс попасть в чужой список обнаружения на границе радиуса; на своей клетке
   игрок виден всегда.
5. Ни один бонус не применяется, пока killswitch выключен: при `outfit.bonuses.enabled=false`
   боевой путь **байт-идентичен** сегодняшнему (доказывается тестом).
6. Битая ветка `EffectService` (сравнение `special_bonus` со строкой `'damage_reduction'`) удалена
   или починена — мёртвого кода не остаётся.
7. Броня с `special_bonus` в виде строки (4 фракционных) не роняет боевой путь и не даёт бонуса.
8. Все числа-ограничители (caps, killswitch) — в `GameSettings` с rationale/effect/above/below;
   per-item значения остаются в таблице `outfits` (это контент, как `armor_value`).
9. Вердикт guide: **да** — `/guide` получает раздел про то, что реально считается у брони.
10. Вердикт tips: **да** — идемпотентная seed-миграция с советом Роби про спец-бонусы.
11. Tier-3 смоук на preprod-testbot: реальный бой в брони со спец-бонусом, caption читается в media-off.
12. Новых таблиц/колонок не заводим → `WipeManifest` не затрагивается (зафиксировать явно).

## Open questions (гриль — ждут ответа владельца)

1. **Скрытность в этой же спеке или отдельной?** Она меняет PvP-видимость, а не только урон.
2. **Что с семью бонусами без механик** (`speed`, `acrobatics`, `morale`, `phantom`, `knockback`,
   `electric_shield`, `crit_chance`, `carry_capacity`)? Чистить описания предметов, чтобы не обещать
   несуществующее, — или оставить как задел и молчать о них?
3. **Отражение урона против боссов-узлов** — клампить, как `combat.nodes.leveldiff_cap`, или нет?
