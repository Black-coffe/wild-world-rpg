---
story: s2-conditional-writes-02
spec: s2-conditional-writes
status: done
returned: DONE
tier: 3
worker: worker-code
wave: 1
blocked_by: []
red: required
red_reason: reward granted while spawn lock was busy or after the spawn was consumed
red_verified_at: 2026-10-28T20:54:18Z 9d927e500b72c4d0e904644d08e7ef8222c09d62f8c4e7cd362aafbab3a18766
---

# F8: «Драться» и «Ограбить» под `NamedLock` на спавн, `PvEService::attack` нетронут

## Goal

`NpcInteractionService::fight()` (`:348-383`: `attack` `:369` → `consumeSpawn` `:376`) и `rob()` (`:312-339`:
`increaseGold` `:329` → `consumeSpawn` `:330`) переводят спавн в «мёртв» после наград и без лока: два одновременных
вызова на один `spawnId` оба проходят `find()` `:355-357` и оба получают награду. После story тело `fight()`/`rob()` —
от повторной проверки спавна до `consumeSpawn` — идёт внутри `NamedLock::withLock('npc_spawn_<spawnId>', fn)`;
`null` (лок занят) и спавн, уже мёртвый при проверке под локом, дают существующий ответ «NPC уже исчез.» без наград.
`consumeSpawn` `:386-391` становится условным (`transitionIfCurrent`, если у спавна есть колонка статуса, иначе инлайн
`UPDATE/DELETE … WHERE` с `affectedRows`), чтобы повторный вызов после освобождения лока тоже отказывал. Решение
владельца (`brief.md` → Answers 1): правка только в этом сервисе.

## Requirements

> 2. F8 — «Драться» и «Ограбить» (`NpcInteractionService::fight/rob`) выполняют бой/награду/перевод спавна в «мёртв» под
> F8 спавн «мёртв» до `grantRewards()`

## Files

- app/Services/NPC/NpcInteractionService.php
- tests/database/NpcSpawnNamedLockTest.php

## Non-goals

- **`app/Services/Player/PvEService.php` не трогать** — общий с кроном `AutoPveHandler`; если `attack()` внутри
  лока сам берёт `GET_LOCK` или открывает транзакцию с явным `transCommit()`, остановиться и вернуть `NEEDS_CONTEXT`
  (вопрос 1 плана), а не оборачивать иначе.
- Не менять формулу боя, награды, отношения с NPC (`NpcRelationService`) и тексты, кроме повторного использования
  «NPC уже исчез.».
- Не трогать `tests/exploit-poc/DuplicationTest.php::testOneRewardPerSpawn`.
- Не заводить второй лок (на персонажа) — один лок на запрос, ADR-181 п. 4.

## Map slice

- Recon до волны 1 (Q1, 28.10): `PvEService::attack()` (`:66`) не берёт `GET_LOCK`/`NamedLock` и не открывает транзакцию; `AutoPveHandler` (`app/TaskHandlers/NPC/AutoPveHandler.php`) `NpcInteractionService` не зовёт. Правило «один лок на запрос» держится — форма плана в силе.

`memory/map/pve-pvp.md` → Gotchas (последний пункт: `fight()` double call и `npc_spawns` без блокировки; «Награда
только победившему»); `memory/map/quests-events-npc.md` → Entry points (NPC). Контракт `NamedLock`, порядок
лок → транзакция → работа → освобождение и форма теста с вторым соединением — `plan.md` → `## Contracts`.

## Acceptance criteria

- [ ] `fight()` и `rob()` выполняют проверку спавна, бой/награду и `consumeSpawn` внутри одного
      `NamedLock::withLock('npc_spawn_<spawnId>', …)`; имя строится так, как `NamedLock` строит имена сегодня.
- [ ] Лок занят (`withLock` вернул `null`) → ответ «NPC уже исчез.», ни золота, ни опыта, ни лута, ни записи боя.
- [ ] Спавн под локом уже мёртв/отсутствует → тот же ответ без наград; `consumeSpawn` — условная запись с
      проверкой затронутых строк, её `Refused` = «уже исчез».
- [ ] `PvEService::attack()` не изменён (diff файла пуст).
- [ ] Тест `NpcSpawnNamedLockTest`: (а) второе соединение `Config\Database::connect('tests', false)` держит
      `GET_LOCK` с тем же именем → `fight()`/`rob()` отказывают, золото/опыт персонажа и строка спавна не изменились;
      (б) после первого успешного `rob()` второй на тот же спавн не выдаёт золота. Сообщение ассерта при провале:
      `reward granted while spawn lock was busy or after the spawn was consumed`. Лок второго соединения
      освобождается в `tearDown` (`RELEASE_LOCK`), соединение закрывается.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/NpcSpawnNamedLockTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `app/Services/NPC/NpcInteractionService.php`: `fight()`/`rob()` — обёртки `NamedLock::withLock('npc_spawn_<id>')`, тела в `fightUnlocked()`/`robUnlocked()`; `null` → «NPC уже исчез.».
- Провал грабежа зовёт `fightUnlocked()` под тем же локом: вложенного `GET_LOCK` нет (ADR-181 п. 4).
- `aliveSpawn()` перечитывает спавн под локом, `status='dead'` = исчез; `consumeSpawn()` — `transitionIfCurrent` из прочитанного статуса в `dead` (статусы бывают `alive`/`active`), затем DELETE; NULL-статус — DELETE с `affectedRows`.
- `rob()`: условный перевод спавна ДО `increaseGold`; `fight()`: перевод после победы (бой может быть проигран, решение владельца).
- `npcFlees()` перешёл на новую сигнатуру `consumeSpawn($id, $spawn)`, поведение прежнее; `PvEService` не тронут.
- `fight()` при занятом локе отдаёт `ok=false, summary='NPC уже исчез.'`; вызывающие экшены `summary` не показывают — текст игроку там прежний (файлы вне story).
- `tests/database/NpcSpawnNamedLockTest.php`: шанс грабежа 1.0 через кэш `GameSettingsService`; red_reason снял кавычки — stamp-red искал их буквально.

## Findings

## Red receipt
- command: vendor/bin/phpunit --no-coverage --no-progress tests/database/NpcSpawnNamedLockTest.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress
- exit: 1
- tests: 9d927e500b72c4d0e904644d08e7ef8222c09d62f8c4e7cd362aafbab3a18766 (tests/database/NpcSpawnNamedLockTest.php)
- impl: 0cd4c02e10f8631b102aa2e3542c98775d504863dcb9cbb62942cb83affbdde5
- stamped: 2026-10-28T20:54:18Z

```
     'experience' => '0.00',
     'npc_kills' => '0',
-    'spawn_status' => 'alive',
+    'spawn_status' => 'absent',
 ]

C:\laragon\www\mmorpg\tests\database\NpcSpawnNamedLockTest.php:145

[37;41mFAILURES![0m
[37;41mTests: 2[0m[37;41m, Assertions: 6[0m[37;41m, Failures: 1[0m[37;41m, PHPUnit Deprecations: 1[0m[37;41m.[0m
```
