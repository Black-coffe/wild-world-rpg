---
story: pve-reward-pool-whitelist-01
spec: pve-reward-pool-whitelist
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Seed-миграция ключей `pve.reward.*` в GameSettings

## Goal
В `game_settings` появляются четыре admin-tunable ключа категории `combat`, которыми
курируется пул крафт-предметов, выдаваемых за победу в PvE: белый список типов, потолок
цены, порог «дорогого» NPC (взамен магического литерала) и килсвитч фильтрации. После
story ключи видны на `/admin/game-settings/combat` с полным rationale и Reset-to-default.

## Requirements
> белый список типов наград в GameSettings — pve.reward.craft_types (например drug,food,component,tool,weapon,clothing), плюс потолок цены отдельным ключом
> правится из админки без релиза и с rationale, гасится килсвитчем
> порог «дорогое ≥ 5000» давно перестал что-либо значить

## Files
- app/Database/Migrations/2026-08-19-210000_Adr173SeedPveRewardPoolGameSettings.php

## Контракт ключей (из plan.md — соблюсти буква в букву)

| `setting_key` | `value_type` | значение и `default_value_text` | границы |
|---|---|---|---|
| `pve.reward.craft_types` | `string` | `drug,food,component,tool,weapon,clothing` | все `null` |
| `pve.reward.price_cap` | `int` | `10000` (`0` = без потолка) | recommended `2000`/`20000`, hard `0`/`200000` |
| `pve.reward.expensive_threshold` | `int` | `5000` | recommended `2000`/`20000`, hard `100`/`200000` |
| `pve.reward.type_filter_enabled` | `bool` | `true` | все `null` |

`category` у всех четырёх — `combat` (уже существует, новую не изобретать).

Все четыре текстовых поля (`rationale_text`, `effect_text`, `above_effect_text`,
`below_effect_text`) заполнить содержательно и конкретно: DDL держит их `NOT NULL`, но
пустая отписка проходит SQL и проваливает конституционный инвариант ADR-024.
`above/below` — конкретный сценарий, а не «станет больше/меньше»: например для
`price_cap` ниже — «при 2000 из наград исчезает почти всё оружие, награда сводится к еде
и компонентам», выше — «в пул возвращаются позиции стоимостью в несколько боёв, faucet
снова обгоняет крафт».

Для `craft_types` в `rationale_text` назвать причину прямо: пул выбирался только по цене,
и в розыгрыш попадал весь каталог, включая постройки, роботов, дронов и верстаки —
те самые типы, что застрахованы `craft_insurance.eligible_types`.

## Non-goals
- Не редактировать существующую миграцию `2026-05-27-200100_V24SeedCraftInsuranceGameSettings.php`
  и вообще ни одну прежнюю seed-миграцию: новые значения заводятся новой миграцией.
- Не трогать `app/Services/PVE/RewardService.php` — чтение ключей это story `-02`,
  она идёт в этой же волне и правит тот файл.
- Не трогать `app/Config/WipeManifest.php`: новых таблиц и player-колонок нет,
  `game_settings` уже классифицирована.
- Не заводить новую категорию GameSettings и не менять doc-comment списка категорий
  в `CreateGameSettingsTable.php`.
- Не добавлять bounds к `string`/`bool` ключам — они игнорируются
  `GameSettingsService::isWithinHardBounds()`, `null` это канон.

## Map slice
`memory/map/admin.md` (GameSettings, live-tunable баланс, кэш 60 с).
Образец для копирования структуры — `app/Database/Migrations/2026-05-27-200100_V24SeedCraftInsuranceGameSettings.php`:
идемпотентность через SELECT-by-`setting_key` перед INSERT в цикле, `down()` — `whereIn(setting_key,[...])->delete()`.

## Acceptance criteria
- [ ] `php spark migrate` проходит на чистой и на уже мигрированной базе (идемпотентность: повторный прогон не дублирует строки и не падает)
- [ ] Все четыре ключа заведены с `category = 'combat'` и точными `setting_key` из таблицы выше
- [ ] У каждого ключа непустые и содержательные `rationale_text`, `effect_text`, `above_effect_text`, `below_effect_text`
- [ ] `default_value_text` совпадает со значением, положенным в `value_*` (иначе Reset-to-default соврёт)
- [ ] `down()` удаляет ровно эти четыре ключа и ничего больше

## Verification
`php -l app/Database/Migrations/*Adr173SeedPveRewardPoolGameSettings.php`

## Implementation notes

Создана `app/Database/Migrations/2026-08-19-210000_Adr173SeedPveRewardPoolGameSettings.php` по
образцу `V24SeedCraftInsuranceGameSettings`: цикл SELECT-by-`setting_key` перед INSERT, `down()`
удаляет ровно четыре ключа через `whereIn`. Значения и границы — буква в букву по контракту из
`plan.md`: `craft_types`/`price_cap`/`expensive_threshold`/`type_filter_enabled`, категория
`combat`, bounds у `string`/`bool` — `null`. `php -l` прошёл чисто.

`php spark migrate` локально не выполним: локальная БД собрана дампом с testbot (см. memory
`reference_local_db_bootstrap_from_testbot.md`) и падает на несвязанной ранней миграции
`2026-05-07-100000_AddBattleLogsCreatedAtIndex` («Table 'mmorpg.battle_logs' doesn't exist») —
до новой миграции очередь даже не доходит. `migrate:status` подтверждает: множество миграций
после batch 41 (май) показывают `---`, локальная БД не мигрирована с нуля. Это состояние машины,
не регресс от этой story — идемпотентность подтверждена только чтением кода (SELECT-guard,
идентичный уже живому и проверенному паттерну `V24`), не прогоном.

## Findings
