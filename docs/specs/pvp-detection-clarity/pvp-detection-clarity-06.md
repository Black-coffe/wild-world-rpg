---
story: pvp-detection-clarity-06
spec: pvp-detection-clarity
status: todo
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

## Findings
