---
story: pvp-detection-clarity-01
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Фундамент окна: таблица `pvp_standoffs`, манифест вайпа, шесть ключей баланса

## Goal

После story в БД есть таблица `pvp_standoffs`, в которой физически невозможно открыть два окна на
одного защитника; она классифицирована в `Config\WipeManifest` как `PLAYER_DATA`; в `game_settings`
лежат шесть ключей `pvp.standoff.*` с полной рационализацией и границами; аудит-коды окна принимаются
`action_log`. Кода, который этим пользуется, ещё нет — это фундамент для `-06`.

## Requirements

> База должна дать возможность заморозить моментальную атаку противника и даёт ему пять минут с оповещением на принятие действий: укрыться, убежать, первым атаковать и так далее.

> Но ровно пять минут, потом атакующий может атаковать.

## Files
- app/Database/Migrations/2026-09-11-210000_Adr186CreatePvpStandoffs.php
- app/Database/Migrations/2026-09-11-210100_Adr186SeedStandoffSettings.php
- app/Database/Migrations/2026-09-11-210200_Adr186ExtendActionLogPvpCodes.php
- app/Config/WipeManifest.php
- tests/unit/Config/WipeManifestCoverageTest.php

## Non-goals
- Не создавать модель, сервис и хендлер — они принадлежат `-06` и `-10`.
- Не трогать `GameBalance::$pvpAttackCooldownSec` (30 с): ADR-186 §6 оставляет его в коде как анти-спам.
- Не менять существующие ключи `defense.*` и не трогать чужие миграции.
- Не запускать полный набор тестов и не делать `DROP`/`migrate` на общей локальной тест-БД.
- Не делать `git stash` / `git checkout`; прежнюю версию файла читать `git show HEAD:<path>`.

## Map slice
`memory/map/pve-pvp.md` (если среза нет — `mmorpg-vault/apps/pve/index.md`);
ADR-186 §1, §5, §6, §7; `mmorpg-vault/decisions/ADR-087-Full-server-wipe-mechanic.md`;
`app/Config/WipeManifest.php:37-207` (структура записи).

## Acceptance criteria
- [ ] Миграция создаёт `pvp_standoffs` с колонками и индексами из `## Contracts` плана, генерируемой колонкой `open_defender_id INT AS (IF(status='open', defender_id, NULL)) STORED` и `UNIQUE` по ней; две попытки вставить второе `open` на одного защитника дают отказ на уровне БД, а не на уровне PHP-проверки.
- [ ] `down()` откатывает таблицу.
- [ ] `Config\WipeManifest` классифицирует `pvp_standoffs` как `PLAYER_DATA` с `link => ['attacker_id','defender_id']` и `by => 'character'`; `WipeManifestCoverageTest` зелёный (он и есть гейт деплоя).
- [ ] Шесть ключей `pvp.standoff.*` засеяны идемпотентно (повторный прогон не плодит строк), категория `combat`, у каждого непустые `rationale_text`, `effect_text`, `above_effect_text`, `below_effect_text`, `default_value_text`, soft- и hard-границы — ровно значения из таблицы в `## Contracts` плана. `pvp.standoff.enabled` засеивается **`false`**.
- [ ] Тип колонки `action_log.action_name` проверен SELECT-ом до написания кода. Если ENUM — третья миграция расширяет его восемью кодами из `## Contracts`; если колонка свободного типа, миграция не нужна, и это записано в `## Implementation notes` (файл тогда не создаётся, и воркер сообщает об этом в отчёте).
- [ ] `php -l` по каждой новой миграции чист (phpstan миграции исключает и до них не достаёт — проверка именно линтером).
- [ ] Префикс имени миграции уникален в каталоге; если занят — время сдвинуто, новое имя названо в отчёте.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/WipeManifestCoverageTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Tracer

Первая story эпика: тонкий срез через все слои фундамента — миграция схемы, запись в манифест,
seed баланса, аудит-коды. Если UNIQUE по генерируемой колонке в нашей версии MySQL не встанет или
`WipeManifest` потребует иной формы записи — это выясняется здесь, до того как на контракт лягут
пять story.

## Implementation notes

- `app/Database/Migrations/2026-09-11-210000_Adr186CreatePvpStandoffs.php` — таблица `pvp_standoffs`. Forge не умеет генерируемые колонки, поэтому `open_defender_id` (STORED, `IF(status='open', defender_id, NULL)`) и её `UNIQUE`-индекс добавлены сырым `$this->db->query()` после `createTable()`. Индексы `(attacker_id,status)`, `(defender_id,status)`, `(status,expires_at)` — через `forge->addKey()` до создания таблицы, как у соседних PvP-таблиц (`character_tributes`), без FK-constraint'ов (тот же стиль).
- `app/Database/Migrations/2026-09-11-210100_Adr186SeedStandoffSettings.php` — шесть ключей `pvp.standoff.*`, категория `combat`, идемпотентно по `setting_key` (паттерн `SeedBuyLevelGateGameSettings`). `enabled=false` на seed.
- `app/Database/Migrations/2026-09-11-210200_Adr186ExtendActionLogPvpCodes.php` **не создан**: SELECT `SHOW COLUMNS FROM action_log LIKE 'action_name'` на локальной БД показал `varchar(255)` — свободный тип, не ENUM (подтверждено также по исходнику `2024-03-18-134951_CreateActionLogTable.php`: `action_name` объявлен VARCHAR с самого создания, ни одна миграция его не меняла на ENUM — искал `modifyColumn`/`ENUM` по всем миграциям, тронувшим `action_name`, нашёл только саму создающую). Восемь кодов окна (`pvp_standoff_opened`/`fled`/`countered`/`held`/`expired`/`cancelled`, `tower_alert_sent`, `abandoned_character_relocated`) пишутся в него как обычные строки без миграции — код `-06`/`-08`/`-09`/`-10` может использовать их сразу.
- `app/Config/WipeManifest.php` — `pvp_standoffs` добавлен сразу после `battle_logs` (соседняя PvP-таблица), `PLAYER_DATA`, `link => ['attacker_id','defender_id']`, `by => 'character'`.
- `tests/unit/Config/WipeManifestCoverageTest.php` не менялся — гейт DB-независим (регекс по `createTable()` в миграциях), новая запись в манифесте закрывает его без правки теста.
- Инвариант «UNIQUE по `open_defender_id`» проверен вручную на одноразовой БД `wildworld_story01_check` (создана и удалена этим кругом, не пересекается с общей `wildworld_tests`): точным SQL из миграции воспроизведена таблица, вторая вставка `status='open'` того же `defender_id` дала `ERROR 1062 Duplicate entry` на уровне БД, третья с `status='fled'` того же защитника и четвёртая `open` другого защитника прошли — ровно поведение из AC. Полный `php spark migrate --all` на пустой БД не запускался осознанно (известная проблема — часть старых таблиц типа `battle_logs` не имеет собственной createTable-миграции, см. `reference_local_db_bootstrap_from_testbot` в памяти проекта, не наш дефект).

## Findings
