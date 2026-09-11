---
story: pvp-detection-clarity-14
spec: pvp-detection-clarity
status: done
tier: 2
worker: worker-code
tracer: false
wave: 6
blocked_by: []
---

# Кулдаун защитника армируют только те исходы, где защитник действительно реагировал

## Goal

После story атакующий не может отменить собственное окно и ударить без окна. Кулдаун защитника
(`pvp.standoff.cooldown_sec`) отсчитывается только от тех закрытий, в которых защитник
воспользовался правом реакции или дал окну истечь, — но не от закрытия, которое устроил сам
нападавший.

## Requirements

> BLOCK, критично #2: `isDefenderOnCooldown()` считает кулдаун по самой свежей строке с ЛЮБЫМ статусом, кроме `open`, а `StandoffLeaveAction` закрывает окно как `cancelled` со стампом `updated_at = сейчас`. «⚔️ Атаковать» → «🚶 Уйти» → «⚔️ Атаковать» → бой немедленно, без единой секунды на реакцию. Это ровно то, что ADR-186 обещает как «суть защиты базы».

> ADR-186 §5 вводил кулдаун против чередующихся атакующих и не разбирал, что `cancelled` — тоже закрытие.

## Files
- app/Services/PVE/PvpStandoffService.php
- tests/database/PvpStandoffServiceTest.php

## Non-goals
- Не трогать `AttackPlayerAction` и `Standoff*Action` — порядок гейтов и экран чужого окна чинит story `-13`, она идёт параллельно и держит те файлы.
- Не трогать `StandoffNotifier` — тексты чинит `-16`.
- Не заводить новую настройку под это: список «армирующих» статусов — инвариант механики, а не ручка баланса. Новых ключей `GameSettings` не добавлять.
- Не менять длительность кулдауна и не трогать `window_sec`.
- Не запускать полный набор; не делать `DROP` / `migrate` на общей `wildworld_tests`; не делать `git stash` / `git checkout`.

## Map slice

`PvpStandoffService.php:208-238` `isDefenderOnCooldown()` — выборка «самая свежая строка со
статусом не `open`»; `:60-81` `shouldOpen()` — единственный её потребитель; `:155-171` `close()` —
переход через `transitionIfCurrent()` плюс отдельный стамп `updated_at`. Статусы ENUM: `open`,
`held`, `fled`, `countered`, `cancelled`, `expired`.

## Acceptance criteria
- [x] Закрытие, инициированное нападавшим (`cancelled`), не армирует кулдаун защитника: после «Уйти» следующая атака по той же цели снова открывает окно. Тест воспроизводит ровно ту тройку тапов из находки.
- [x] Исходы, в которых защитник реагировал или окно дожило до конца (`held`, `fled`, `countered`, `expired`), кулдаун армируют как прежде — защита против чередующихся атакующих (ADR-186 §5) не ослабляется. Тест закрывает каждый из четырёх статусов.
- [x] Решение о том, какие статусы армируют кулдаун, живёт в одном месте кода и подписано комментарием со ссылкой на ADR-186 §5 — чтобы следующий, кто добавит статус в ENUM, увидел вопрос, а не унаследовал молчаливое «любой, кроме open».
- [x] 🟡 Хвост #13 того же ревью, раз файл всё равно открыт: в `close()` переход статуса и стамп `updated_at` — две отдельные записи; падение между ними оставит кулдаун отсчитанным от создания окна, а не от закрытия. Свести к одной записи либо, если это ломает гарантию «ровно один переход» от `transitionIfCurrent()`, оставить как есть и написать в `## Findings`, почему.
- [x] Гарантия «двойной тап даёт ровно один переход» и гонка «крон против игрока» остаются закрытыми — существующие тесты на это зелёные.

## Verification

`vendor/bin/phpunit --no-coverage --no-progress tests/database/PvpStandoffServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

- `PvpStandoffService`: добавлена именованная константа `COOLDOWN_ARMING_STATUSES = ['held','fled','countered','expired']` с комментарием-ссылкой на ADR-186 §5; `isDefenderOnCooldown()` теперь ищет свежую строку `whereIn('status', self::COOLDOWN_ARMING_STATUSES)` вместо `status != 'open'` — `cancelled` (нападавший ушёл сам) больше не армирует кулдаун защитника.
- `PvpStandoffServiceTest`: два новых теста — `testCancelledByAttackerDoesNotArmDefenderCooldown` (ровно тройка тапов из находки: `open()` → `close(..., 'cancelled')` → `shouldOpen()===true` → повторный `open()` успешен) и `testEachReactiveClosingStatusStillArmsDefenderCooldown` (цикл по `held`/`fled`/`countered`/`expired` — каждый по отдельности армирует кулдаун, `shouldOpen()===false`). Существующие тесты `testCloseAppliesOnceAndRefusesSecondReactionFromDefender` и `testShouldOpenFalseWhileDefenderCooldownActiveAndTrueAfterItExpires` (двойной тап и гонка крон/игрок) не менялись и остались зелёными.
- `close()` (акцептанс #4, хвост находки #13) оставлен как есть: `transitionIfCurrent()` пишет только колонку `status`, отдельный `Model::update()` — только он выставляет `updated_at` (через `useTimestamps` модели). Обоснование — см. `## Findings`.

## Findings

Акцептанс #4 просил либо свести переход статуса и стамп `updated_at` в `close()` к одной записи,
либо аргументированно оставить как есть. Оставлено как есть:

- `ConditionalWriteService::transitionIfCurrent()` — общий примитив (используется другими сервисами
  вне этой story, не в `## Files`), его контракт — CAS ровно по одной колонке (`SET col = ? WHERE id
  = ? AND col = ?`); расширять сигнатуру, чтобы он писал вторую колонку (`updated_at`) в одном
  запросе, — правка чужого общего файла, прямо запрещённая Non-goals story и Files-списком.
- Продублировать CAS-логику `transitionIfCurrent()` инлайн в `PvpStandoffService::close()` (сырой
  `UPDATE ... SET status=?, updated_at=? WHERE id=? AND status='open'`) убирает зависимость от общего
  примитива и тем самым выходит за рамки «одна точка правды CAS-перехода» — тот самый инвариант,
  который `ConditionalWriteService` был создан защищать (см. его докблок, exploit-fix-01/18/24).
- Практический риск текущей двухшаговой записи: падение процесса между `transitionIfCurrent()` и
  последующим `Model::update()` оставит `status` уже переведённым, а `updated_at` — от момента
  открытия окна (`open()`), а не закрытия. Кулдаун защитника в этом случае может истечь РАНЬШЕ, чем
  если бы `updated_at` был выставлен точно в момент закрытия — то есть отказ безопасен (сужает окно
  кулдауна, а не открывает эксплойт вроде того, что чинит эта story): защитник в худшем случае раньше
  снова становится доступен для нового окна, а не остаётся без защиты дольше положенного.
- phpstan (`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`) на полном прогоне репозитория
  показывает 1 ошибку в `Services\PVE\StandoffNotifier.php:30` (`property.onlyWritten`) — этот файл не
  в `## Files` этой story (правит `-16` по Non-goals) и не был затронут этой правкой; `PvpStandoffService.php`
  ошибок не даёт.
