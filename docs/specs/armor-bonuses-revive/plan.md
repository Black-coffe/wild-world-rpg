# Оживление спец-бонусов брони (plan)

**Tier:** 2 · **Spec slug:** `armor-bonuses-revive` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-024 (admin-tunable balance), ADR-121 (множители входящего/исходящего урона),
ADR-074/075 (per-instance модификаторы — образец killswitch + dormant-поведения), ADR-046/V14
(фракционная броня), ADR-134 (tips), ADR-127 (guide)
**Depends on:** канон и tech-writing уже приведены в соответствие коду (коммиты 9ac42910, 8d973d3)

## Goal

Сделать так, чтобы обещанное в карточке брони действительно происходило в бою — в том объёме, в
котором для этого уже есть механики. Сегодня из 16 значений `bonus_type` не работает ни одно;
после спеки работают четыре, а остальные честно вынесены за скоуп с объяснением почему.

## Assumptions

- **Новых полей и таблиц не заводим.** Всё нужное уже лежит в `outfits` и читается одним запросом,
  который `EquipmentService` и так делает. → `WipeManifest` не затрагивается.
- **Killswitch по умолчанию OFF.** Образец — ADR-074/075: при dormant боевой путь обязан быть
  байт-идентичным текущему, что доказывается тестом, а не обещанием.
- **Per-item числа остаются контентом.** `damage_reduction_percent: 10` живёт в `outfits`, как и
  `armor_value`. В `GameSettings` уходят killswitch и **потолки** (чтобы правка контента не могла
  сделать игрока неуязвимым). Это осознанное чтение ADR-024: тюнится то, что задаёт рамку, а не
  каждая карточка предмета.
- **`special_bonus` — недоверенный вход.** В БД лежит и JSON, и простая строка, и `{}`. Парсер
  возвращает `null` на всё, что не разобралось, и никогда не бросает исключение в боевом пути.
- **Отражение не может убить атакующего в ноль.** Иначе низкоуровневый игрок в «Титане» становится
  оружием против боссов. Нужен потолок.

## Stories

**Волна 1 — фундамент (последовательно, это общий вход для всех остальных)**
- `01-bonus-parser` — чистый хелпер `ArmorBonusResolver`: `outfit[] → ?array{type,value}`. Терпит
  строку, `{}`, битый JSON, `NULL`. Killswitch + потолки из `GameSettings`. Только unit-тесты.
  Файлы: `app/Services/PVE/ArmorBonusResolver.php`, `tests/unit/...`.
- `02-equipment-wire` — `EquipmentService` собирает бонусы через резолвер и кладёт в
  `BattleCharacter`; при dormant — байт-идентичность (тест). Файлы: `EquipmentService.php`,
  `BattleCharacter.php`, тесты.

**Волна 2 — боевые эффекты (параллельно, каждый в своём файле)**
- `03-damage-reduction` — `incomingDamageMultiplier` (хук готов, правка минимальна).
- `04-damage-reflection` — возврат части урона в `BattleService` + строка в боевой лог; потолок и
  поведение против боссов — по ответу на вопрос 3 гриля.
- `05-melee-damage` — прибавка к `damageValue`.
- `06-effectservice-cleanup` — удалить битую ветку сравнения со строкой (Ask 6).

**Волна 3 — скрытность (только если владелец подтвердил вопрос 1 гриля)**
- `07-stealth-detection` — `PlayerDetectionService` учитывает `stealth_modifier` на границе радиуса;
  на своей клетке игрок виден всегда. Отдельный killswitch.

**Волна 4 — player-facing и бумага**
- `08-guide-tips` — раздел `/guide` + seed-миграция совета Роби (Asks 9–10).
- `09-paperwork` — ADR в `mmorpg-vault/decisions/`, tech-writing ноты (`OutfitModel` уже выверен —
  дописать поведение), `hot.md`, daily.

## Contracts

- **Резолвер — единственная точка чтения** `bonus_type`/`special_bonus`. Ни один боевой файл не
  парсит JSON сам.
- **Dormant = байт-идентичность.** Тест сравнивает результат боя с killswitch OFF до и после спеки.
- **Потолки:** суммарное снижение входящего урона и доля отражения зажаты сверху значением из
  `GameSettings` независимо от того, что стоит в карточке предмета.
- **Фракционные не меняются:** их `bonus_type` дублирует уже работающие `*_resistance`; резолвер
  возвращает для них `null`, двойного эффекта не возникает.

## Integration gate

- `vendor/bin/phpunit --no-coverage --no-progress` — зелёный.
- `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` — level 9 чист.
- Tier-3 смоук на preprod-testbot: бой в Тактическом бронекостюме и в «Титане», лог показывает
  снижение и отражение; caption самодостаточен в media-off.

## Descoped (и почему — это часть результата)

`carry_capacity` и `crit_chance` — механик в игре нет (грузоподъёмность не считается нигде, крита в
PvE нет). `speed`, `acrobatics`, `morale`, `phantom`, `knockback`, `electric_shield` — требуют
подсистем, которых не существует. Эти семь значений остаются мёртвыми **осознанно**; что делать с их
описаниями — вопрос 2 гриля.

## Plan deltas

*(пусто)*

**Approved:**
**Briefed:**
**Branch:**
**Checked:**
**Council:**
**Shipped:**
