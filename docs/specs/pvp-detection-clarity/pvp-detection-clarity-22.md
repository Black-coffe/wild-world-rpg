---
story: pvp-detection-clarity-22
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-test
tracer: false
wave: 7
blocked_by: []
---

# Инварианты закрыты проверками, а не комментариями

## Goal

После story три инварианта, которые сейчас держатся на честном слове одного вызова, закреплены
тестами на том пути, где их реально можно сломать; уборка тестовой схемы переживает падение
`setUp()`; а метка кнопки остаётся различимой для двух соседей с похожими длинными именами.

## Requirements

> BLOCK-2, minor #G: тесты `-18` проверяют `shown_ids` на уровне `renderDetectionMessage()`, но само место записи истории не покрыто ничем: если кто-то вернёт `insert()` обратно в цикл сбора кандидатов, все тесты останутся зелёными.

> BLOCK-2, minor #H: инвариант «`cancelled` достижим только действием самого нападавшего» держится проверкой владения в `StandoffLeaveAction:42` и ничем не закреплён в тестах; новое место закрытия окна этим статусом тихо лишит защитника кулдауна.

> BLOCK-2, minor #I: если `setUp()` падает посередине, PHPUnit не зовёт `tearDown()` и созданные до падения таблицы остаются в базе — тот самый мусор, от которого лечила story `-15`.

> BLOCK-2, minor #K: имя в метке кнопки режется до 20 символов, поэтому два соседа с длинными именами, совпадающими в первых 19 символах, снова получают неразличимые метки — остаток дыры major #11 в редком случае.

## Files
- app/Services/Player/PlayerDetectionService.php
- tests/database/PlayerDetectionRenderTest.php
- tests/database/PvpStandoffServiceTest.php
- tests/database/RelocateAbandonedCharactersTest.php

## Non-goals
- Не трогать `AttackPlayerAction` и `StandoffNotifier` — их держат параллельные story `-19` и `-20`.
- Не трогать `PvpStandoffService` сам по себе: #H закрывается ТЕСТОМ, а не новой проверкой в сервисе. Если окажется, что тестом честно не закрыть, — скажи в отчёте, не правь сервис.
- Не менять формат метки кнопки целиком: #K чинится различимостью в крайнем случае, а не отказом от имени в метке.
- Не писать тесты, которые сканируют исходник на подстроки вместо проверки поведения: сломанный метод оставит такой тест зелёным.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`PlayerDetectionService.php:196-203` — запись истории по `shown_ids` внутри `detectNearbyPlayers()`;
`:388` — обрезка имени в метке кнопки;
`PvpStandoffService.php:47` `COOLDOWN_ARMING_STATUSES` и `StandoffLeaveAction.php:42` — проверка
владения (читать, не править);
`tests/database/RelocateAbandonedCharactersTest.php:71-92` — `setUp()` с флагами `$created`.

## Acceptance criteria
- [ ] Связь «в `player_detection_history` попадает только показанное» проверяется на пути `detectNearbyPlayers()`, а не только на рендере: тест падает, если запись истории вернуть в цикл сбора кандидатов.
- [ ] Инвариант «`cancelled` может поставить только сам нападавший» закреплён проверкой: тест падает, если окно закрыть этим статусом от имени защитника или постороннего.
- [ ] Уборка тестовой схемы не зависит от того, дошёл ли `setUp()` до конца: воспроизведи падение `setUp()` посередине и докажи, что мусора в базе не остаётся.
- [ ] Два показанных соседа с длинными именами, совпадающими в первых 19 символах, получают различимые метки кнопок. Тест закрывает именно этот случай.
- [ ] Прежние тесты этих четырёх файлов остаются зелёными; ни один не переписан так, чтобы перестать ловить свою находку.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayerDetectionRenderTest.php tests/database/PvpStandoffServiceTest.php tests/database/RelocateAbandonedCharactersTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

**#G (история пишется только за показанных).** Добавлен `PlayerDetectionRenderTest::testDetectNearbyPlayersWritesHistoryOnlyForShownNeighbors()` — гоняет настоящий `detectNearbyPlayers()` (реальный SQL-join + запись `player_detection_history`), а не `renderDetectionMessage()` напрямую. 15 реальных персонажей на одной клетке (`distance=0`, все одного уровня → детерминированная сортировка по id), `max_listed`=12 по умолчанию → тест требует ровно 12 строк истории и именно 12 меньших id; для оставшихся 3 явно `assertNotContains`. Чтобы этот путь вообще заработал, пришлось поправить саму `PlayerDetectionService::detectNearbyPlayers()`: `CharacterModel::find()` отдаёт `CharacterEntity`, а `renderDetectionMessage()` типизирует `$attacker` как `array` — без явного `toArray()` вызов падал `TypeError` на каждом реальном детекте (баг существовал ДО этой story, просто ни один тест раньше не гонял `detectNearbyPlayers()` с настоящей строкой `characters`). Правка — один `if (!is_array($character)) { $character = $character->toArray(); }` сразу после `find()`.

**#H (`cancelled` — только от нападавшего).** `PvpStandoffService` НЕ тронут (Non-goal соблюдён буквально). Добавлен `PvpStandoffServiceTest::testStandoffLeaveActionRefusesCancelForDefenderAndStrangerAndCooldownStaysUnarmed()` — гоняет настоящий `StandoffLeaveAction::handle()` с реальным `CallbackQuery` от защитника и от постороннего (тот же приём, что `StandoffAttackGateTest::callbackQuery()`), проверяет ответ «не твоё» и что строка `pvp_standoffs.status` осталась `'open'`. На уровне самого сервиса это НЕ закрыть тестом честно: `close(int $standoffId, string $status)` не принимает «кто звонит» — вызов идентичен что от атакующего, что от защитника, разница целиком в вызывающем коде (`StandoffLeaveAction:42`). Поэтому тест бьёт по границе, где владение реально проверяется.

**#I (уборка при падении `setUp()`).** `RelocateAbandonedCharactersTest`: тело создания схемы вынесено в `buildSchema()`, `setUp()` оборачивает `buildSchema()+ensureOriginCells()` в `try/catch(Throwable)` → на исключении зовёт новый `dropTrackedTables()` (тот же порядок, что раньше был только в `tearDown()`) и перебрасывает исключение. `dropTrackedTables()` дополнительно проверяет `tableExists()` перед каждым `down()` — на пути отказа флаг `$created[...]` может быть `true`, а таблицы физически нет (атомарный `CREATE TABLE` с FK упал целиком). Новый тест `testSetUpCleansUpPartiallyCreatedTablesWhenItFailsMidway()` воспроизводит НАХОДКУ ревьюера буквально: сносит уже созданную штатным `setUp()` пятёрку, подкладывает несовместимую `map` (`id VARCHAR(10)` вместо `INT UNSIGNED`), вызывает `buildSchema()+ensureOriginCells()` напрямую — `CreateClaimedCellsTable::up()` падает на FK `map_cell_id → map.id` (конфликт типов), `telegram_users`/`characters` к этому моменту уже созданы. После падения — четыре `assertFalse(tableExists(...))`. В конце тест сам чинит схему (сносит корявую `map`, вызывает `buildSchema()` ещё раз), чтобы настоящий `tearDown()` этого теста отработал штатно.

**#K (различимость меток при коллизии первых 19 символов).** `PlayerDetectionService::buttonName()` правлен: при обрезке длинного имени хвост теперь не просто `…`, а `…№<id>` (id уникален по конструкции — PK), сдвигая точку обрезки так, чтобы итоговая длина не превышала прежние 20 символов. Короткие имена (≤20) формат не меняют — Non-goal «не менять формат метки целиком» соблюдён: различимость чинится ровно в крайнем случае длинных коллизий. Новый тест `PlayerDetectionRenderTest::testTwoNeighborsWithNamesMatchingFirstNineteenCharsGetDistinctButtonLabels()` — два соседа с ИДЕНТИЧНЫМИ первыми 19 символами (разные только хвосты после 19-го), проверяет `assertNotSame` меток и что каждая метка несёт свой id.

Прогон дважды на двух независимых свежих БД (`wildworld_tests_s22`, `wildworld_tests_s22b`) — оба раза `Tests: 29, Assertions: 251`, 0 ошибок; после каждого прогона `SHOW TABLES` на throwaway-БД пуст (никакого мусора), обе БД дропнуты. Ни один из 25 прежних тестов этих трёх файлов не переписан — только дополнен новыми методами/приватными хелперами.

## Findings
