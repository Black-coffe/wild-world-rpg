---
story: chat-requests-batch-11
spec: chat-requests-batch
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [chat-requests-batch-05]
---

# След смерти называет, сколько потеряно

## Goal
Запись о смерти в `action_log` несёт состав и величину потерь — сколько золота и каких
ресурсов сняла смерть, — а не только факт гибели и номер клетки возрождения. Без этого
экран «Куда ушло» (story 06) ответит игроку «ты умер», когда вопрос был «где мои 50%».

## Requirements
> [09.08.2026] Max Syskov: «У меня исчезло 50% ресурсов, сравнивал "сегодня 15:03" и "сейчас". все время был на базе»

## Files
- app/Services/Player/DeathService.php
- app/Services/Player/Death/PlayerRespawner.php
- tests/database/DeathLossTraceTest.php

## Notes
Находка story 05 (24.08): `PlayerRespawner::respawn()` знает только `$characterId` и клетку
возрождения. Величина и состав потерь считаются раньше — в `DeathService` /
`LootProcessor`, шаги 4–5 `handlePlayerDeathAndReward()`, — и к моменту `respawn()` уже
применены, состояние «до» оттуда невосстановимо. Поэтому запись обязана рождаться там, где
потери считаются, а не там, где происходит возрождение.

Точные пути и номера строк проверить самому: story 05 называла их по чужому отчёту.

Уже существующий формат следа держать единым — story 05 завела `TAX_BUILDINGS`,
`TAX_BEACONS`, `DEATH_RESPAWN` с человекочитаемым `description`, который будет читать
экран story 06. Новая запись либо дополняет `DEATH_RESPAWN` суммой, либо встаёт рядом
своим кодом — но `description` остаётся текстом, который можно показать игроку дословно.

Грабли те же: `action_status` строго из enum (Pending / Completed / Skipped / REJECTED),
`chat_id` — `bigint unsigned NOT NULL` без default. Сбой логирования НЕ имеет права
уронить или откатить смерть и возрождение.

Известный факт канона (для проверки правдоподобия чисел, не для правки):
смерть с базой — −3% , без базы — −50%.

## Non-goals
- **Формулу потерь не менять ни на процент.** Правится только то, что игра о ней говорит.
- Не трогать награду победителю, гейт `winner == player` и логику респавна.
- Не логировать рейд и просрочку расходников — отдельный заход.
- Не заводить новую таблицу: пишем в `action_log`.

## Acceptance criteria
- [x] Создан и зелёный `tests/database/DeathLossTraceTest.php` — падает на доправочном поведении.
- [x] В логе видно золото И ресурсы (состав), а не одна суммарная цифра без смысла.
- [x] Смерть при нулевых потерях не порождает запись-пустышку «потеряно 0».
- [x] Падение записи в лог не откатывает смерть/возрождение — проверено так же жёстко,
      как в story 05 (таблица дропается, состояние БД после вызова утверждается).
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.
- [x] В логе видны крафт-предметы (роботы/дроны/верстаки/транспорт) поимённо (`name_rus`,
      батч-резолвом), не только золото и ресурсы — доп. пункт по запросу team-lead
      (жалоба Max: та же «молчащая пропажа», просто дороже).

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Services/Player/DeathService.php`
(путь из `## Files` — `app/Services/PVE/DeathService.php` — не существует; реальный
файл `App\Services\Player\DeathService` лежит в `app/Services/Player/DeathService.php`,
см. `## Findings`.)

## Findings

**Путь-опечатка в story подтверждена и исправлена по факту.** `app/Services/PVE/DeathService.php`
не существует. Реальный класс — `App\Services\Player\DeathService`, файл
`app/Services/Player/DeathService.php` (namespace, use-секция и вызов из
`PlayerRespawner::respawn()` подтверждают, что это тот же сервис, о котором говорит
story: он же зовёт `$this->respawner->respawn($loserId)` на шаге 7). Работал с этим
файлом; phpstan-гейт прогнан по реальному пути.

**Что пишется.** Новый `action_name = 'DEATH_LOSS'` (отдельно от `DEATH_RESPAWN` story
05 — рождается в другом месте кода и в другой момент, см. Notes). Приватный
`DeathService::logDeathLoss(int $loserId, array $loserArr, array $lostResources, int
$lostGold)` вызывается в `handlePlayerDeathAndReward()` сразу после шага 5
(`applyLosses()`/`applyCraftLosses()`, ~строка 172) — золото и ресурсы к этому моменту
уже реально списаны, значит числа в `description` совпадают с тем, что случилось.
`describeResourceLoss()` резолвит `resources.name` батчем (`whereIn`) для состава.
Формат:

```
Смерть персонажа: -500 золота; ресурсы: Дерево ×5, Вода ×3
```

Часть с золотом опущена, если `$lostGold <= 0`; часть с ресурсами опущена, если
`$lostResources === []`; если ОБЕ пусты — запись не пишется вовсе (акс. критерий
«без пустышки»). `chat_id` — тот же `chatIdFor()`, что уже был в файле (используется
для `notifyVehicleBroken`), коалесцированный к `0` (не `null`) под конвенцию story 05.
`action_status='Completed'`. try/catch вокруг `save()` — сбой лога не может откатить
уже проведённое `applyLosses()`/`applyCraftLosses()` (они выполняются ДО записи лога,
их эффект в БД необратим независимо от исхода insert'а).

**`PlayerRespawner.php` — только текстовая правка, без новой логики** (файл был в
`## Files`, но данных для суммы там как не было, так и нет — story 05 `## Findings`
объяснила почему). `DEATH_RESPAWN.description` больше не говорит расплывчатое «часть
ресурсов и золота потеряна» (эту фразу теперь заменяет точный `DEATH_LOSS`), а называет
только факт смерти и клетку: `"Смерть персонажа — возрождение на клетке #{$respawnCell}"`.
Оставлять две записи с одинаково смутной формулировкой было бы избыточно и потенциально
путало бы читателя экрана story 06 (какая из двух — источник правды?); теперь роли чёткие:
`DEATH_LOSS` = что потеряно, `DEATH_RESPAWN` = куда переместили. Перепрогнал
`tests/database/TaxAndDeathTraceTest.php` (story 05, не входит в `## Files` этой story,
но текст в нём меняется) после правки — все 5 тестов зелёные (его ассерты проверяют
`assertStringContainsString('Смерть', ...)` и номер клетки, не старую точную фразу).

**Тест `tests/database/DeathLossTraceTest.php` (3 теста).** Зовёт
`DeathService::handlePlayerDeathAndReward()` напрямую (публичный метод, реальный),
инжектируя только `PlayerRespawner` (полностью DI-friendly — модели `claimed_cells`/
`map`/`events` подменены изолированными `dlt_*`-таблицами, реальные не задеты).
`InsuranceCalculator`/`DeathPenaltyCalculator`/`LootProcessor`/`GameSettingsService` —
дефолтные (не переопределены): `LootProcessor` по умолчанию смотрит на ТЕ ЖЕ реальные
`character_resources`/`crafted_items_log`, что и собственные, неинжектируемые модели
`DeathService` на шаге 3 — переопределять только одну сторону значило бы развести шаг
3 и шаг 5 по разным таблицам. `characters`/`telegram_users`/`character_resources`/
`crafted_items_log`/`resources`/`action_log` — реальные имена, `tableExists()`-гард +
`resetDataCache()` в `setUp()` (тот же паттерн и та же причина, что в story 05).

1. Без базы (изолированная `dlt_claimed_cells` пуста) → канон `-50%`: 1000 золота →
   -500, 10 Дерева → -5, 6 Воды → -3. Проверяет `result['penalty']===0.50` (правдоподобие
   по канону из Notes) И содержимое `DEATH_LOSS`-строки (золото И оба ресурса по имени).
2. Ноль золота + ноль ресурсов → `actionLogRows(...) === []` (акс. критерий «без
   пустышки»).
3. `DROP TABLE IF EXISTS action_log` ПЕРЕД вызовом → `save()` реально падает → try/catch
   глотает → `result['success']===true` (никакого исключения наружу) И золото/ресурсы в
   БД проверены напрямую (400 из 800, 2 из 4 камня) — не «нет исключения», а «операция
   реально состоялась».

Краснота подтверждена `git stash` на `DeathService.php`+`PlayerRespawner.php`: тест 1
падает (`assertCount(1, $rows)` находит 0 — `DEATH_LOSS` не пишется вообще), тесты 2 и 3
остаются зелёными (не проверяют факт записи потерь) — ожидаемо. `git stash pop` вернул
диф, тест снова зелёный.

**Побочная находка при отладке (не баг production-кода, баг МОЕГО теста, уже
исправлен).** `character_resources` уже существовал локально (другой воркер/более
полный дамп, с колонкой `id_telegram_users`, которой нет в моей ad-hoc схеме) —
`tableExists()`-гард корректно НЕ создавал и НЕ дропал его, но мои вставленные строки
(id_characters 301-303) переживали каждый прогон и копились (10+ строк на третьем
прогоне), потому что `applyLosses()` МЕНЯЕТ `quantity` после вставки — `hasInDatabase()`-
трекинг по исходным данным не нашёл бы изменившуюся строку для удаления. Фикс —
`tearDown()` явно удаляет строки по `character_id`/`resource_id`/`telegram_user_id` из
известного пула (301-303, 701, 1-3), а не полагается на трекинг по значению или на
`DROP TABLE`, когда таблица не своя. Стабильно зелёный на 4+ последовательных прогонах
после фикса — не путать со story 05 находкой (там была гонка КЭША `tableExists()`
между воркерами, здесь — накопление строк в шаренной таблице между МОИМИ собственными
прогонами).

**Хватит ли экрану story 06.** Да для заявленной цели (Max спрашивал «где мои
ресурсы» — теперь золото и состав по каждому ресурсу видны текстом, готовым к показу
дословно). Не хватает состава крафт-предметов (роботы/дроны/верстаки/транспорт) — acceptance
criteria явно требовали только «золото И ресурсы», crafted-items не просил ни один
критерий, и добавление увеличило бы диф без запроса — если понадобится, это отдельный
маленький доп (тот же `logDeathLoss()`, тот же `$lostCraftedItems`, уже вычислен в
`handlePlayerDeathAndReward()` на шаге 4, ничего искать не придётся).

### Доп. заход (та же story, по прямому запросу team-lead) — крафт-предметы в след

`logDeathLoss()` получил 5-й параметр `$lostCraftedItems` (тот самый, уже вычисленный
на шаге 4 — переиспользован, не пересчитан заново) и новую секцию `description`:
`'предметы: ' . describeCraftedItemLoss($lostCraftedItems)`. `describeCraftedItemLoss()` —
батч-резолв `crafted_items.name_rus` через один `whereIn('id', $craftedItemIds)` (не
запрос в цикле, как и просили), формат `«Промышленник ×1, Дрон-разведчик ×1»`, не
нашлась строка — нейтральное «Предмет#{id}» (та же конвенция, что «Ресурс#{id}»).

**Ограничение длины (по прямому запросу).** Новый общий приватный `joinWithLimit(array
$parts, int $limit)` — используется ОБОИМИ описателями (ресурсы И крафт-предметы, не
только крафт: тот же класс проблемы — длинный инвентарь мог разрастить строку и раньше,
просто до этого захода никто не проверял). `DESCRIPTION_ITEM_LIMIT = 6` (инфра-константа,
не баланс — регулирует читаемость строки лога, не игровую механику, поэтому НЕ в
`GameSettings`, см. `.claude/rules/balance.md` §«не баланс»). Свыше лимита — первые 6
позиций поимённо + `" и ещё N"`, число сказано, не молчание.

**Условие «пустышки» расширено на все три категории**: запись не пишется, только если
`$lostGold<=0 && $lostResources===[] && $lostCraftedItems===[]` одновременно (раньше —
только золото/ресурсы; теперь смерть персонажа БЕЗ ресурсов/золота, но С потерянным
крафт-предметом всё равно порождает запись — и наоборот).

**Тест — новый `testDeathLossNamesCraftedItemInDescription()`** (4-й в файле): персонаж
без золота/ресурсов, с 1 крафт-предметом qty=2 → при -50% (нет базы) `exact=1.0`,
`floor=1`, `fraction=0.0` — детерминированно, БЕЗ броска монетки ADR-172 (`fraction>0.0`
гейтит `rollUnit()`; `game_settings` в тесте нет, `fractionalCraftLossEnabled()` тихо
деградирует в default `true`, но раз дробной части нет — бросок физически не вызывается,
тест не зависит от RNG). Assert: `description` содержит `'предметы:'` и `'Промышленник
×1'` (имя из `name_rus`, не id, не заглушка). Добавлены таблицы `crafted_items`
(`tableExists()`-гард, как остальные) и точечная очистка `crafted_items_log` по
`character_id` + `crafted_items` по id-пулу (901-902) в `tearDown()` — тот же паттерн
«удалять по id, не полагаться на трекинг по значению», что уже объяснён выше для
`character_resources`.

Краснота ПОДТВЕРЖДЕНА повторно `git stash` на `DeathService.php` (после доп. захода):
падают ОБА теста, что проверяют содержимое `DEATH_LOSS`-записи (composition-тест — 0
строк вместо 1 — `logDeathLoss()` целиком не вызывается со старой сигнатурой; новый
crafted-item тест — та же причина), 2 из 4 (zero-loss skip, survives-log-failure) —
зелёные, не проверяют факт записи. `git stash pop` вернул диф.

Полный файл (4 теста, все категории потерь) стабильно зелёный на 3+ повторных
прогонах после фикса (`Tests: 4, Assertions: 19`), и в паре со `story 05`
(`tests/database/TaxAndDeathTraceTest.php`) — `Tests: 9, Assertions: 38`, без
дополнительных ошибок сверх уже известной гонки на `TaxCollectionPerBaseTest.php`
(чужой файл, не в `## Files` ни одной из двух story). `vendor/bin/phpstan
--memory-limit=512M --no-progress app/Services/Player/DeathService.php` — чисто.

**Хватит ли экрану story 06 (обновлено).** Да, полностью для заявленной цели: золото,
ресурсы И крафт-предметы (включая самые дорогие — роботов/дронов/верстаки/транспорт)
теперь названы поимённо в одной строке `description`, готовой к показу игроку дословно,
с защитой от нечитаемо длинной строки на богатом инвентаре.

### Ревью денежного пути — вердикт PASS + находки, закрытые здесь (§1 и §5-1)

**§1 (главное) — сумма стала ПОДТВЕРЖДЁННОЙ, не заказанной.** `LootProcessor` вне
`## Files` этой story (Non-goals: экономику не трогаем), поэтому подтверждение
дельты сделано ЦЕЛИКОМ на стороне `DeathService` — БЕЗ изменения `LootProcessor`.
Источник дельты: `logDeathLoss()` теперь принимает снимок «до» (`$loserGold`,
`$loserResources`, `$loserCraftedItems` — шаг 3, уже читались) ОТДЕЛЬНО от списка
id-кандидатов (`$lostResources`, `$lostCraftedItems` — шаг 4, что МОГЛО измениться),
и вызывается ПОСЛЕ `applyLosses()`/`applyCraftLosses()` (шаг 5). Три новых приватных
метода:

- `confirmedGoldLoss(int $loserId, int $goldBefore): int` — перечитывает
  `characters.gold` СВЕЖИМ `find()`, `max(0, before-after)`. Персонаж исчез между
  шагом 3 и логом (крайний случай) → `0`, не выдумываем.
- `confirmedResourceLoss(array $loserResources, array $lostResources): array` —
  batch-перечитывает `character_resources` (`whereIn('id', ...)`, НЕ в цикле) только
  для id-кандидатов, `before-after` по каждому (строка исчезла → `after=0`, забрали
  всё, что было).
- `confirmedCraftedItemLoss(array $loserCraftedItems, array $lostCraftedItems): array`
  — тот же приём для `crafted_items_log`.

Все три вызываются напрямую рефлексией (`handlePlayerDeathAndReward()` сам читает
состояние на шаге 3 — внешне впрыснуть гонку МЕЖДУ шагом 3 и списанием нельзя без
реальной многопоточности, поэтому механизм проверяется изолированно, тот же приём,
что `collectBuildingTaxPerBase()` в story 05):

- `testConfirmedGoldLossReflectsFreshStateNotStaleBefore` — «до»=1000 передаётся
  вручную, реальное золото в БД подменено на 200 ДО вызова → подтверждено 800, не
  какое-то другое число.
- `testConfirmedResourceLossReflectsFreshStateNotStaleBefore` — «до»=10,
  `lossAmount`(заказано)=5, реальный остаток в БД подменён на 3 → подтверждено 7
  (10−3), НЕ заказанные 5.

**§5 (первый пункт) — гонка на `crafted_items_log` больше не выдаёт воображаемую
величину.** `LootProcessor::applyCraftLosses()::186` молча `continue`, если строка
исчезла между расчётом и списанием — раньше `description` всё равно называл бы
`lossAmount` из шага 4, даже если предмет физически не тронут (или тронут иначе).
`confirmedCraftedItemLoss()` закрывает это без правки `LootProcessor`: перечитывает
факт ПОСЛЕ шага 5 и логирует его. `testConfirmedCraftedItemLossHandlesRacedAwayRow` —
строка `logId=999` физически НЕ существует ни на шаге 3 (передана только в снимке
«до», как будто там БЫЛА), ни после — подтверждённая потеря = весь снимок «до» (2),
не заказанный `lossAmount` (1); демонстрирует, что метод не может соврать про
физически отсутствующую строку.

**§2/§3 (общие с story 05, тот же файл-паттерн).** `ensureTable()` (таблица+колонки
независимо от порядка тестов) и «дропаем `action_log` только если создали сами» —
идентичный код, продублированный в `DeathLossTraceTest.php` (два теста-файла, не
общий хелпер — команда явно попросила не выносить это в общее место).

**Верификация.** `vendor/bin/phpstan --memory-limit=512M --no-progress
app/Services/Player/DeathService.php` — чисто. Краснота (общий `git stash` на все 3
production-файла story 05+11 разом) — 3 `ReflectionException` (`confirmedGoldLoss`/
`confirmedResourceLoss`/`confirmedCraftedItemLoss` не существуют на пре-фикс коде) +
2 failures (`DEATH_LOSS`-composition и crafted-item тесты находят 0 строк вместо 1 —
`logDeathLoss()` зовётся со старой сигнатурой, новый вызов не совпадает); `git stash
pop` вернул диф, все тесты снова зелёные. Полный прогон
`tests/database/DeathLossTraceTest.php` (7 тестов) стабильно зелёный изолированно
(`Tests: 7, Assertions: 22`, повторено трижды) и в паре со `story 05`
(`tests/database/TaxAndDeathTraceTest.php`) — `15 tests, 53 assertions` — при
отсутствии активной параллельной нагрузки других воркеров; при активной нагрузке
(наблюдалась во время этого захода) флапает на табличных гонках
`characters`/`crafted_items`/`action_log` — внешняя гонка окружения (см. §2/§3 выше
и story 05 `## Findings`), не баг кода. Рекомендую верифицировать чистым
последовательным прогоном.

### §3, доследование — RENAME TABLE отклонён, известный пробел покрытия

Тот же вопрос, тот же ответ, что в story 05 (полная аргументация — там): team-lead
попросил заменить `markTestSkipped()` на `RENAME TABLE`, проверка ПЕРЕД реализацией
нашла факт-блокер — `action_log` уже безусловно захватывается (drop+create в каждом
`setUp()`/`tearDown()`) двумя чужими тестами, `tests/database/AchievementServiceTest.php`
и `tests/database/DailyTaskServiceTest.php`, ровно повторяя конфигурацию, которая уже
сломала RENAME-подход для `GreenhouseProductionWaterTest.php` (коммит `1e05e24b`).
Team-lead отклонил RENAME и оба DI-альтернативных варианта (второй заход в
`DeathService`/`LootProcessor` ради тестового удобства — отдельное решение, не хвост
этой story), оставил `markTestSkipped()` с сообщением, называющим причину и
следствие (`ACTION_LOG_SKIP_REASON` в `DeathLossTraceTest.php`).

**Известный пробел покрытия.** На окружении, где `action_log` существует не по
созданию `DeathLossTraceTest` (CI/testbot/прод-дамп),
`testDeathLossSurvivesActionLogFailure` скипается — try/catch-контракт
`logDeathLoss()` («сбой лога не роняет и не откатывает уже проведённое списание
смерти») в этом прогоне не проверяется. Тот же прецедент и те же два файла-захватчика
имени, что в story 05 — подробности и путь закрытия (DI-шов на `ActionLogModel`,
отдельным заходом с отдельным ревью) там же.
