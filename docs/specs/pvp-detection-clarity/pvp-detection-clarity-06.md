---
story: pvp-detection-clarity-06
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [pvp-detection-clarity-01]
---

# Сервис окна противостояния, его сообщения и честный признак «я на своей базе»

## Goal

После story есть `PvpStandoffService`, умеющий открыть окно без гонки, прочитать активное с ленивым
истечением, закрыть его ровно один раз и удержать кулдаун защитника; `StandoffNotifier`, собирающий
три текстовых сообщения окна; и публичный признак «на этой клетке у персонажа есть живая оборонительная
постройка». Ни один вызывающий этого ещё не использует — их подключают `-08`, `-09`, `-10`.

## Requirements

> А атакующий в этот момент ничего не может предпринять в течение пяти минут либо раньше, пока игрок как-то не среагирует.

> Но ровно пять минут, потом атакующий может атаковать.

> То есть в этом и будет суть защиты базы.

## Files
- app/Services/PVE/PvpStandoffService.php
- app/Services/PVE/StandoffNotifier.php
- app/Models/PvpStandoffModel.php
- app/Services/PVE/DefenseStructureService.php
- tests/database/PvpStandoffServiceTest.php

## Non-goals
- Не врезаться в `AttackPlayerAction`, `RunAwayAction` и `Config\Tasks` — это `-08`, `-09`, `-10`.
- Не трогать `PvpRoundOrchestrator` и `PvpDamageCalculator`: RNG-fence, формулу боя эта работа не касается.
- Не менять существующие методы `DefenseStructureService` — только добавить публичный признак, переиспользующий тот же SQL, что `activeStructures()`.
- Не изобретать своё определение «дома»: второго определения базы в проекте не заводим.
- Не писать собственные `SELECT ... затем UPDATE`: условная запись только через `ConditionalWriteService`.
- Не запускать полный набор и не делать `DROP`/`migrate` на общей локальной тест-БД; не делать `git stash`/`git checkout`.

## Map slice
ADR-186 §1 (ленивое истечение), §2 (признак базы), §5 (гонки), §6 (ключи), «Инварианты» 1–4 и 9;
`## Contracts` плана — сигнатуры, форма строки, имена ключей и callback_data;
`app/Services/Db/ConditionalWriteService.php:176-205` (`transitionIfCurrent`) и `:319-358` (`insertUnique`);
`app/Services/PVE/DefenseStructureService.php:57-128` и `:190-212`.

## Acceptance criteria
- [ ] `PvpStandoffService` реализует ровно сигнатуры из `## Contracts` плана; окно считается открытым по `status='open' AND expires_at > NOW()` в момент чтения, а не по факту прохода крона.
- [ ] `open()` использует `insertUnique()`: второй атакующий по тому же защитнику получает отказ **без исключения** и **без порчи `transStatus`** чужой транзакции, а читатель видит уже открытое окно с прежним `expires_at` — остаток наследуется, свежие пять минут не выдаются.
- [ ] `close()` использует `transitionIfCurrent()` со статуса `open`: два хода защитника подряд дают ровно один `true`, второй — `false` («ты уже отреагировал»).
- [ ] `shouldOpen()` возвращает `false`, если окно выключено килсвитчем, если у защитника на этой клетке нет ни одной активной постройки, если не соблюдён `require_tower`, или если кулдаун защитника после прошлого окна ещё идёт.
- [ ] Признак базы — непустой набор активных построек, а **не** `getDefenseProfile() !== null`: персонаж с одним боевым дроном в чистом поле окна не получает. Тест доказывает именно этот случай.
- [ ] `StandoffNotifier` собирает три сообщения (тревога защитнику, экран ожидания атакующего, пинг об истечении): `parse_mode = HTML`, имена экранированы, фото нет, весь смысл в тексте — кто, откуда, сколько осталось, что делает каждая кнопка. Кнопки — по 2–3 в ряд, ни одной одиночной. callback_data ровно из `## Contracts`.
- [ ] 🔴 Тревога защитнику несёт **ровно три** хода, и все три названы явно: «🛡 Укрыться» (`standoffHold_<id>`), «🏃 Убежать» (`runAway`) и **«⚔️ Ударить первым»** (`attackPlayer_<attacker_id>` — существующий callback, цель = напавший). Третья кнопка — не производная от значения `countered` в ENUM, а требование владельца дословно: «укрыться, убежать, первым атаковать». Тест проверяет состав клавиатуры тревоги по callback_data, а не по тексту подписи.
- [ ] Каждое закрытие окна пишет строку аудита соответствующим кодом из `## Contracts`.
- [ ] Тест строит схему из настоящих классов миграций, сеет время часами БД и покрывает: открытие, дубль-открытие вторым атакующим, ленивое истечение без крона, двойной ход защитника, кулдаун защитника, дрон-без-построек.
- [ ] В `## Implementation notes` сказано, что отправку сообщений PHPUnit не исполняет: Telegram-путь доказывается только Tier-3.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PvpStandoffServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `PvpStandoffModel` — таблица `pvp_standoffs`, `$allowedFields` без `open_defender_id` (STORED-генерируемая).
- `DefenseStructureService::hasActiveStructuresOnCell()` — новый публичный метод, переиспользует SQL `activeStructures()`; существующие методы не тронуты.
- `PvpStandoffService` — `open()`/`activeAgainst()`/`activeFor()`/`close()`/`shouldOpen()`/`secondsLeft()`/`markExpiryNotified()` ровно по `## Contracts`. `open()`/`close()` идут через `ConditionalWriteService` (`insertUnique`/`transitionIfCurrent`); `close()` дополнительно стампит `updated_at` обычным `Model::update()` (тот примитив пишет только колонку статуса) — на нём держится отсчёт `pvp.standoff.cooldown_sec`. `require_tower` резолвится приватным join'ом внутри сервиса (не второй метод в `DefenseStructureService` — сужение до конкретной постройки, а не второе определение «дома»).
- `StandoffNotifier` — `alertDefender()`/`waitScreen()`/`notifyAttackerExpired()`; отправка вынесена в `protected sendDefenderAlert()`/`sendExpiredPing()` (приём как у `TowerAlertService`) — тесты подменяют доставку и проверяют состав клавиатуры по `callback_data`. Все три сообщения — HTML, имена через `esc(...,'html')`, фото нет.
- 🔴 Отправку сообщений PHPUnit не исполняет: `sendDefenderAlert()`/`sendExpiredPing()` в тестах подменены, реальный Telegram-путь (HTML-рендер, длина текста, доставка) доказывается только Tier-3 на testbot.
- Тест `tests/database/PvpStandoffServiceTest.php` строит схему прогоном настоящих классов миграций (`telegram_users → characters → map → game_settings → factions → buildings → character_buildings → action_log → pvp_standoffs` + `W5AddCharacterCombatDroneActiveUntil`), ENUM `building_type` расширяется до `defensive` вручную (то же, что `S26AddDefensiveStructures`/`S26bAddWatchTower`, без их побочной зависимости на `events`/`world_objects`/`tasks.handler_key`, не относящейся к этой story) — WoodenWall/WatchTower строки засеяны напрямую. `character_buildings.map_cell_id` — FK на `map.id` (не `map.cell_number`), поэтому «клетка» в тесте — реальный `map.id` новой строки, что совпадает с прод-инвариантом `id == cell_number` (`PlayerStateService::isCharacterOnBase()`).
- Покрыто: открытие, дубль-открытие (null + `activeAgainst()` с прежним `expires_at`), ленивое истечение без крона (`activeAgainst()` → null при `expires_at` в прошлом и статусе всё ещё `open`), двойной ход защитника (`close()` → true затем false), кулдаун защитника (false пока не истёк, true после), `require_tower` (false без вышки, true с ней), дрон-без-построек (профиль обороны существует по ADR-064, но `hasActiveStructuresOnCell()`/`shouldOpen()` — false), клавиатуры `alertDefender()`/`waitScreen()` по составу `callback_data`.

## Findings
