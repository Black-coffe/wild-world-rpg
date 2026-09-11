---
story: pvp-detection-clarity-02
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Три гейта PvP переезжают в админку и начинают называть причину машиночитаемо

## Goal

После story `PvPRestrictionService` не содержит ни одного хардкод-числа: порог уровня, граница южной
зоны и возраст аккаунта живут в `GameSettings` категории `combat` с полной рационализацией. Отказ
возвращает не только текст для игрока, но и код причины — на нём story `-07` рисует замок **до**
тапа вместо ошибки после.

## Requirements

> ⚠️ Ошибка: PvP недоступно: Один из игроков имеет уровень ниже 5 — PvP недоступно.

> Кого тыкою- ниже 5

## Files
- app/Services/Player/PvPRestrictionService.php
- app/Database/Migrations/2026-09-11-220000_Adr186SeedPvpRestrictionSettings.php
- tests/database/PvPRestrictionServiceTest.php

## Non-goals
- Не менять сами правила: пороги остаются 5 / 900 / 10 дней, меняется только откуда берётся число.
- Не трогать разрыв уровней (ветеран против новичка) — это отдельный вопрос владельцу, не эта работа.
- Не править `PlayerDetectionService` и `AttackPlayerAction` — замок рисует `-07`, окно ставит `-08`.
- Не ломать существующую сигнатуру `checkPvPAllowed()` для нынешних вызывающих: добавляем поля в возврат, не убираем.
- Не запускать полный набор и не делать `DROP`/`migrate` на общей локальной тест-БД; не делать `git stash`/`git checkout`.

## Map slice
`app/Services/Player/PvPRestrictionService.php:30-80` (три гейта: `:33`, `:41-56`, `:58-73`);
ADR-186 §6 (последний абзац) и `## Contracts` плана (имена ключей, границы, коды причин);
`mmorpg-vault/decisions/ADR-024-Game-Settings-live-tunable-framework.md`.

## Acceptance criteria
- [ ] Все три числа читаются из `GameSettings` (`pvp.restriction.min_level`, `pvp.restriction.safe_zone_min_y`, `pvp.restriction.min_account_age_days`); грep по файлу не находит ни `5`, ни `900`, ни `10` как литерал правила.
- [ ] Миграция сеет три ключа идемпотентно, категория `combat`, каждый с `rationale_text` / `effect_text` / `above_effect_text` / `below_effect_text`, soft- и hard-границами и `default_value_text` — значения из таблицы в `## Contracts` плана.
- [ ] `checkPvPAllowed()` возвращает `reason_code` из набора `level` / `safe_zone` / `account_age` вместе с прежним текстом сообщения; текст сообщения игроку не меняется в этой story.
- [ ] Все нынешние вызывающие продолжают работать: список вызывающих получен `Bash`-грепом (`grep -rn 'checkPvPAllowed' app/`), а не памятью, и приведён в `## Implementation notes`.
- [ ] Тест покрывает три отказа и разрешение, причём пороги в тесте меняются через настройки — то есть тест доказывает, что число действительно приехало из `GameSettings`, а не осталось в коде.
- [ ] Тест строит схему из настоящих классов миграций (`.claude/rules/tests-db.md`): CI гоняет на пустой базе, рукописный `CREATE TABLE` запрещён.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PvPRestrictionServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `PvPRestrictionService::checkPvPAllowed()` читает три порога через `GameSettingsService::get()`.
  **Ремонт после ревью главной сессии (11.09):** первая редакция вызывала `get($key)` вовсе без
  третьего аргумента — при непримененной миграции/недоступной таблице это молча давало
  `(int) null = 0` и тихо снимало все три гейта (`min_level=0` пускает уровень 1, `safe_zone_min_y=0`
  делает `coordinate_y >= 0` истинным всегда, `min_account_age_days=0` снимает возрастной ценз) без
  единой ошибки в логе — находка сформулирована самим владельцем через ревьюера, не мной. Починено
  добавлением safety-net третьим аргументом (`get($key, 5|900|10)`, тот же приём, что `world.move.*`
  в `MoveCharacterToDirectionAction`) с комментарием в файле, отличающим safety net (число-страховка
  на случай отсутствия строки) от «литерала правила» (сравнение `< $minLevel` и т.п. нигде не
  содержит числа напрямую) — критерий приёмки про grep остаётся выполненным по этому различию.
  Добавлен тест `testGatesFallBackToPriorHardcodeWhenSettingsRowsAreMissing`, который физически
  удаляет все три строки `pvp.restriction.*` и доказывает: гейты остаются включёнными по прежним
  хардкод-значениям, а не отключаются.
- Возврат расширен полями `reason_code` (`level`/`safe_zone`/`account_age`/`map_missing`/`''`) и
  `message` (дубль `reason`); ключ `reason` не убран — единственный нынешний вызывающий,
  `AttackPlayerAction.php:156-157`, продолжает читать `$check['allowed']`/`$check['reason']` без правок.
  Список вызывающих получен `Bash`-грепом `grep -rn 'checkPvPAllowed' app/ tests/` — вызывающий
  ровно один. Test-спаев `PvPRestrictionService` в репозитории не найдено.
- Миграция `Adr186SeedPvpRestrictionSettings` сеет три ключа категории `combat`, идемпотентна по
  `setting_key` (паттерн `SeedWorldMoveGameSettings`), дефолты байт-в-байт равны прежнему хардкоду
  (5/900/10), `rationale_text` каждого явно называет заморозку ручки до снятия замера окна (ADR-186 §6).
  Новых таблиц/player-колонок не создаёт — `game_settings` уже `KEEP` в `WipeManifest`, запись не нужна.
- Тест `tests/database/PvPRestrictionServiceTest.php` строит схему (`telegram_users` → `characters` →
  `map` → `game_settings`) исполнением реальных классов миграций, только если таблицы отсутствуют —
  проверено на одноразовых пустых БД (`ww_probe_pvp02`, `ww_probe_pvp02b` — обе созданы и удалены
  этими прогонами) и на персистентной локальной `wildworld_tests`, все прогоны зелёные, 7/7 после
  добавления safety-net-теста. На персистентном стенде тест
  не трогает чужие строки (собственные `characters`/`map`-строки — с cell_number в диапазоне
  900 000 000–999 999 999, удаляются по id в `tearDown`); таблицы, которых не существовало, дропаются
  обратно. Один тест (`testLevelThresholdIsActuallyReadFromGameSettingsNotHardcoded`) временно
  поднимает `pvp.restriction.min_level` до 50 и возвращает обратно в этом же прогоне, доказывая, что
  число реально приезжает из `GameSettings`, а не осталось в коде.
- `RelocateAbandonedCharacters.php`/`RelocateAbandonedCharactersTest.php` в `git status` — чужие
  untracked-файлы story `-03`, эта story их не касалась; phpstan-ошибки в них не относятся к `## Files`
  этой story (файлы этой story проверены отдельно — 0 ошибок).

## Findings
