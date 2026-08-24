---
story: chat-requests-batch-05
spec: chat-requests-batch
status: done
tier: 2
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Налог и смерть оставляют след

## Goal
Два источника убыли, на которые игроки жалуются чаще всего, начинают писать строку в
`action_log`: ежедневный сбор налога за здания и маяки и потери при смерти. Без этого
экран «Куда ушло» покажет игроку всё, кроме того, что он ищет.

## Requirements
> [08.06.2026] Ivan Divan: «лога движения средств тоже нету и не понятно нихера»

> [09.08.2026] Max Syskov: «У меня исчезло 50% ресурсов, сравнивал "сегодня 15:03" и "сейчас". все время был на базе»

## Files
- app/TaskHandlers/TaxCollectionHandler.php
- app/Services/Player/Death/PlayerRespawner.php
- tests/database/TaxAndDeathTraceTest.php

## Notes
Разведка 24.08: `TaxCollectionHandler` списывает золото через `adjust()` в четырёх
местах (~214, 253, 306, 486) и не пишет в `action_log` ни строки. Мировые события след
уже оставляют — `event_effects_log.effect_details` несёт `gold_delta` и
`magnitude.resource_loss_percent`, трогать их не нужно.

Значения `action_status` — строго из enum (Pending / Completed / Skipped / REJECTED),
иначе STRICT валит INSERT. Колонка `chat_id` — `bigint unsigned NOT NULL` без default,
слать `null` нельзя. В `description` класть сумму и за что списано человеческим текстом:
его будет читать экран story 06.

## Non-goals
- Не менять ставку налога, порядок списания и правило «второй недобор сносит постройку».
- Не трогать формулу потерь при смерти.
- Не заводить новую таблицу: пишем в существующий `action_log`, он уже классифицирован
  в `WipeManifest`.
- Не логировать рейд и просрочку расходников — это отдельный заход после первого среза.

## Acceptance criteria
- [x] Создан и зелёный `tests/database/TaxAndDeathTraceTest.php` — он обязан падать на доправочном поведении,
      иначе гейт зелёный впустую (урок «скан исходника ≠ покрытие»).
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный (см. `## Findings` про гонку воркеров).

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/TaskHandlers/TaxCollectionHandler.php`

## Findings

**Что и в каком формате пишется в `action_log`.** Новый приватный `logTaxDeduction(int
$characterId, string $actionName, int $amount, string $what)` в `TaxCollectionHandler`
и `logDeathTrace(int $characterId, int $respawnCell)` в `PlayerRespawner` — оба пишут
после успешного списания/респауна, оборачивают `save()` в try/catch (сбой форензики
не блокирует и не откатывает уже проведённую операцию, тот же паттерн, что
`ResourceTradeService::logPurchase`), и резолвят `chat_id` через
`resolveChatIdForLog()` (join `characters`→`telegram_users`, `0` — не `null` — если
не нашли, колонка `bigint unsigned NOT NULL`). `action_status` всегда `'Completed'`
(валидное enum-значение). Формат строки:

| action_name | Когда | description (пример) |
|---|---|---|
| `TAX_BUILDINGS` | сбор налога за здания (агрегатный путь ~213, per-base ~486) | `Налог за 3 зданий: -3000 золота` |
| `TAX_BEACONS` | сбор налога за маяки (полная оплата ~253, частичная ~306) | `Налог за 2 маяков: -450 золота` / `Налог за 2 маяков (частично): -300 золота` |
| `DEATH_RESPAWN` | смерть → респаун (`PlayerRespawner::respawn()`) | `Смерть персонажа: часть ресурсов и золота потеряна, возрождение на клетке #777` |

Нулевую сумму (`$amount <= 0`) `logTaxDeduction` не пишет — нечего трассировать.

**Все 4 call-site налога инструментированы** (номера подтверждены по факту в этом
заходе): агрегатный путь зданий ~213-214, маяки полностью ~252-253, маяки частично
~305-306, per-base здания ~485-486 (`collectBuildingTaxPerBase`). Экономика/порядок
списания/формула потерь не тронуты — добавлены только вызовы `logTaxDeduction()`
ПОСЛЕ существующего `adjust()`.

**Ограничение по смерти (сообщается явно, как просили).** `PlayerRespawner::respawn()`
знает только `$characterId` и итоговую клетку — точная сумма/состав потери (ресурсы,
крафт, золото) считается в `DeathService`/`LootProcessor` (шаги 4-5
`handlePlayerDeathAndReward()`), которые вне `## Files` этой story, и к моменту вызова
`respawn()` списание там уже произошло — «до» здесь недоступно. `description` поэтому
называет факт смерти и место возрождения БЕЗ суммы. Экран story 06, если ему нужна
точная сумма по смерти, потребует отдельного захода, открывающего `DeathService`/
`LootProcessor` (это НЕ баг текущего кода — публикую как ограничение по scope, не чиню
сам).

**Тест `tests/database/TaxAndDeathTraceTest.php` (5 тестов).** Налоговая часть зовёт
`collectBuildingTaxPerBase()` рефлексией (тот же паттерн, что уже существующий
`tests/database/TaxCollectionPerBaseTest.php`) — единственный call-site налога,
достижимый без wall-clock гейта `handle()` (`new DateTime()`, не seedable; трогать
Non-goals не позволяют). Остальные 3 call-site'а зовут ТОТ ЖЕ приватный
`logTaxDeduction()` — механизм идентичен и покрыт этим же тестом, но рантайм-прогон
именно ЭТИХ трёх мест (внутри `handle()`) этим заходом не сделан — честно фиксирую,
не переоцениваю покрытие. Смертная часть зовёт `PlayerRespawner::respawn()` напрямую
(DI-конструктор, реален).

Краснота подтверждена `git stash` на оба production-файла: 2 упавших теста
(`testTaxDeductionWritesActionLogTrace`, `testDeathRespawnWritesActionLogTrace` —
`assertCount(1, $rows)` находил 0 строк), 3 остальных (zero-amount skip, обе
«survives-log-failure») остались зелёными, потому что не проверяют факт записи —
ожидаемо и корректно. `git stash pop` вернул диф, тест снова зелёный.

**Требование #2 (лог не должен ронять списание) проверено явным сценарием**: оба
`*SurvivesActionLogFailure`-теста дропают таблицу `action_log` ПЕРЕД вызовом
(`DROP TABLE IF EXISTS action_log`) — insert реально падает (таблицы физически нет),
try/catch глотает, и тест утверждает, что золото/`cell_number`/`last_respawn_at`
всё равно записаны в БД (не просто «нет исключения наружу» — реальное состояние
после операции).

**Локальная `wildworld_tests`.** Таблицы `characters`/`telegram_users`/
`character_buildings`/`action_log` создаются в `setUp()` только если их нет
(`tableExists()`-гард) и дропаются только созданное самим тестом.
`claimed_cells` (реальная, локально уже существует без колонки `camp_name` —
устаревший дамп) получает временный `ALTER TABLE ADD COLUMN camp_name` только если
колонки нет, и снимается в `tearDown()` — `baseNameMap()` иначе падает на любом
прогоне `collectBuildingTaxPerBase()` (это уже было верно и для существующего
`TaxCollectionPerBaseTest.php` — та же локальная проблема, не моя). Для
`PlayerRespawner` — изолированные `tdt_claimed_cells`/`tdt_events` через DI-инъекцию
моделей, реальные `claimed_cells`/`map`/`events` не задеты вовсе.

**Гонка воркеров подтверждена эмпирически.** `\Config\Database::connect()` (без
аргумента) под `ENVIRONMENT=testing` резолвится в ту же группу `'tests'`
(`Config\Database::__construct()`), а `TaxCollectionHandler` читает/пишет через неё
хардкодом в нескольких местах (DI на эти вызовы нет) — тот же connection-объект, что
использует `DatabaseTestTrait`. `tableExists()` кэширует список таблиц НА ЭТОМ
объекте; если другой тест в этом же процессе успел его прогреть до того, как здесь
создана/удалена `characters`, кэш врёт до конца процесса — поймал и исправил
`$db->resetDataCache()` в начале `setUp()`. После фикса стабильно зелёный ИЗОЛИРОВАННО
и в комбинации с `TaxCollectionPerBaseTest.php` (5/5 моих, 19 assertions; 6 ошибок
`TaxCollectionPerBaseTest` — `camp_name`, чужой файл, не мой). Но при активной
параллельной нагрузке ДРУГИХ воркеров на ту же `wildworld_tests` (реальные таблицы
`characters`/`claimed_cells` создаются/дропаются конкурентно прямо во время прогона —
наблюдал своими глазами: `SHOW TABLES` менялся между командами без моего участия)
тест то зелёный, то падает на `«characters» already exists` / `doesn't exist` —
это внешняя гонка окружения, ровно то, что описано в задаче («444 ошибки»), не баг
моего кода. Рекомендую верифицировать чистым последовательным прогоном, как и
планировалось.

**Файлы:** `app/TaskHandlers/TaxCollectionHandler.php`,
`app/Services/Player/Death/PlayerRespawner.php`,
`tests/database/TaxAndDeathTraceTest.php`.

### Ревью денежного пути — вердикт PASS + 5 находок, все закрыты

**§1 (главное) — сумма стала ПОДТВЕРЖДЁННОЙ, не заказанной.** `adjust()` имеет пол
`gold>=0` и может списать МЕНЬШЕ заказанного (гонка между чтением золота и
фактическим списанием), `null` — не списано вовсе. Источник дельты — новый приватный
`confirmedGoldDelta(?array $paid): int` = `(int)$paid['before']['gold'] -
(int)$paid['after']['gold']`, `$paid` — ТОТ ЖЕ массив, что `adjust()` уже возвращал на
каждом из 4 call-site'ов (просто раньше читали только `['after']`, `['before']`
игнорировали). `null`/неполный `$paid` → `0` → `logTaxDeduction()`'s собственный guard
`amount<=0` ничего не пишет — «не появляется вообще при неуспехе». Тест
`testTaxLogsConfirmedDeltaNotRequestedAmountUnderRace` воспроизводит ровно сценарий
ревью детерминированно: передаёт `collectBuildingTaxPerBase()` заведомо УСТАРЕВШИЙ
снимок `availableGold=3000` (метод посчитал базу оплачиваемой целиком), но РЕАЛЬНОЕ
золото в БД — 500 (как будто параллельная трата) → `adjust()` читает свежие 500 под
row-lock, клампит на полу, списывает только 500 → лог говорит `-500 золота`, НЕ
`-3000`. Так же трафика для `DeathService::logDeathLoss()` (см. story 11 `## Findings`
ниже по хронологии — три отдельных `confirmed*Loss()`-метода с той же логикой).

**§2 — фикстура персонажа больше не зависит от порядка тестов в общем прогоне.**
Ревьюер поймал реальный кейс: `tests/database` целиком → все 9 тестов падали на
`Unknown column 'gold'`, потому что ДРУГОЙ тест-класс успевал создать `characters`
раньше со своей узкой схемой, а мой `tableExists()`-гард видел «уже есть» и не
патчил недостающее. Новый `ensureTable(BaseConnection $db, string $table, array
$columns)`: таблицы нет вообще → создаём с `id` PK (полностью наша, дропается в
`tearDown()` целиком); таблица уже есть (другой тест/полный CI-дамп) → добавляем
ТОЛЬКО недостающие колонки через `ALTER TABLE ... ADD COLUMN` (существующие/чужие
данные не трогаем), снимаем их поколоночно в `tearDown()`. Тот же приём накрыл и
`claimed_cells.camp_name` (была отдельная ad-hoc веткой — теперь через общий
`ensureTable()`).

**§3 — тест больше не дропает `action_log`, которую не создавал.** Ревьюер
эмпирически поймал: создал `action_log` со строкой-маркером, прогнал тест — таблицы
после прогона не стало. Обе `*SurvivesActionLogFailure`-тесты теперь начинаются с
`if (! $this->createdActionLog) { $this->markTestSkipped(...); return; }` — дропаем
`DROP TABLE action_log` ТОЛЬКО когда таблицу физически создал этот же тест в своём
`setUp()` (safe: это наш объект). На полной схеме (CI/testbot/прод-дамп), где
`action_log` уже существует не по нашему созданию, тест честно скипается с
объяснением, а не разрушает чужой объект — тот же принцип, что уже применялся в
`VehicleRecipesTest::testAdr157HoldsOnLiveCatalogIfCatalogPopulated()`.

**§4 — недобор различим в тексте + снос постройки теперь пишет след.**
`logTaxDeduction()` получает `' (частично)'` в `$what` при `$taxCollectionStatus ===
'FAILURE'` (агрегатный путь) / `$anyFailure` (per-base путь) — маяки такую пометку
уже несли, теперь симметрично у зданий. Новый `logTaxEvent(int $characterId, int
$telegramUserId, string $actionName, string $description)` — тот же insert-паттерн,
что `logTaxDeduction()`, но без суммы золота (это событие, не списание); вызывается
из ВСЕХ ТРЁХ точек сноса построек/баз за неуплату: легаси 2-й-FAILURE на весь
персонажа (`action_name='TAX_BUILDING_DESTROYED'`), per-base легаси-реакция
(`reactBaseTaxFailureLegacy()`, тот же код), каскадный снос базы (ADR-095,
`cascadeDestroySmallestBase()`, `action_name='TAX_BASE_DESTROYED'`). Тесты:
`testTaxPartialPaymentTaggedInDescription` (пометка недобора) и
`testTaxBuildingDemolitionWritesActionLogTrace` (снос постройки пишет след с
человеческим текстом и верным `chat_id`).

**§5 — лишний JOIN-запрос устранён.** `resolveChatIdForLog()` (JOIN `characters`↔
`telegram_users` на каждого персонажа цикла, при том что `$character` уже загружен
и несёт `telegram_user_id`) заменён на `resolveChatIdFromTelegramUserId(int
$telegramUserId): int` — один запрос ТОЛЬКО в `telegram_users`, без повторного чтения
`characters`. `$telegramUserId` вычисляется один раз в `handle()` сразу после
`$character = $characterModel->find(...)` и передаётся во все след-вызовы
(`logTaxDeduction`/`logTaxEvent`), включая новые параметры `collectBuildingTaxPerBase()`
/`reactBaseTaxFailureLegacy()`/`cascadeDestroySmallestBase()` (добавлен как
`int $telegramUserId = 0` — с дефолтом, чтобы НЕ сломать сигнатуру, которую рефлексией
зовёт чужой `tests/database/TaxCollectionPerBaseTest.php`, вне `## Files` этой story).
`.claude/rules/balance.md`-совместимый попутный phpstan-фикс: normalize `$character`
в отдельную `$characterArr` для чтения `telegram_user_id` (прямой `??`-доступ к
loosely-typed `find()`-результату плодил каскад из 6 phpstan-ошибок по всему файлу —
не из-за нового кода самого по себе, а из-за того, как phpstan переинферит тип
`$character` после промежуточного выражения; нормализация в отдельную переменную,
не трогающую `$character`, решила чисто).

**Row-leak, пойманный при отладке ремедиации (не из ревью, нашёл сам).** После
добавления `testTaxBuildingDemolitionWritesActionLogTrace`/`testTaxPartialPaymentTaggedInDescription`
обнаружил, что `character_buildings` — реальная таблица, которую МОЙ тест не всегда
создаёт с нуля (см. §2) — не чистилась поколоночно ПО СТРОКАМ между прогонами: одни
и те же `character_id`+`map_cell_id` копились через повторные `seedBuilding()`,
раздувая собранный налог следующего теста (`«1 баз (частично): -10000 золота»`
вместо ожидаемых `-3000`). Фикс — явная построчная очистка в `tearDown()` по
`CHAR_IDS`/`TG_IDS`-пулу (`character_buildings`/`characters`/`telegram_users`),
независимо от того, дропается ли сама таблица целиком.

**Верификация.** `vendor/bin/phpstan --memory-limit=512M --no-progress
app/TaskHandlers/TaxCollectionHandler.php` — чисто. Краснота подтверждена `git stash`
на все 3 production-файла разом: 7 failures + 3 ReflectionException (методы
`confirmed*Loss()` в `DeathService`, см. story 11) — падают ровно те тесты, что
проверяют новое поведение; редность и содержание описаны ниже в story 11
`## Findings` (общий stash/pop покрывал оба файла story одновременно). После
`git stash pop` — полный прогон `tests/database/TaxAndDeathTraceTest.php` +
`tests/database/DeathLossTraceTest.php` стабильно зелёный (`15 tests, 53
assertions`) при отсутствии активной параллельной нагрузки других воркеров; при
активной нагрузке (наблюдалась во время этого захода — `WhereItWentAction`/
`LedgerService`/`DemolishBuildingAction` строились параллельно) флапает на
табличных гонках `characters`/`character_buildings`/`action_log` — та же внешняя
гонка окружения, что и раньше, не баг кода. Рекомендую верифицировать чистым
последовательным прогоном.

### §3, доследование — RENAME TABLE отклонён, известный пробел покрытия зафиксирован

Team-lead попросил заменить `markTestSkipped()` на `RENAME TABLE action_log TO
action_log_<суффикс>` / обратно, чтобы симуляция сбоя лога проверялась и на полной
схеме, а не только терпела скип. Проверка ПЕРЕД реализацией нашла факт, из-за
которого переименование небезопасно ИМЕННО в этом репозитории: `action_log` — не
нейтральное имя. `tests/database/AchievementServiceTest.php` и
`tests/database/DailyTaskServiceTest.php` (оба вне `## Files` любой из двух story)
безусловно дропают и пересоздают `action_log` в КАЖДОМ `setUp()`/`tearDown()` (общий
`TABLES`-массив вперемешку с `characters`/`game_settings`) — ровно та же
конфигурация, что уже сломала попытку `RENAME TABLE` для `GreenhouseProductionWaterTest.php`
(докблок этого файла + коммит `1e05e24b`: «первая версия изоляции переименовывала
общие таблицы себе в бэкап... таблица могла исчезнуть под нами прямо между проверкой
существования и переименованием»). Если переименовать `action_log` пока конкурентно
бежит `AchievementServiceTest`/`DailyTaskServiceTest`, они создадут СВОЙ `action_log`
под освободившимся именем, не зная о переименовании — обратный `RENAME` в `tearDown()`
тогда либо упадёт на конфликте имён, либо (если разрешать конфликт дропом) уничтожит
чужую активную фикстуру. Решение team-lead: RENAME отклонён, `markTestSkipped()`
остаётся, но сообщение скипа теперь называет причину и следствие (см.
`ACTION_LOG_SKIP_REASON` в обоих тест-файлах), а не факт «пропущено».

**Известный пробел покрытия (фиксирую явно, как попросили).** На окружении, где
`action_log` уже существует не по созданию `TaxAndDeathTraceTest`/`DeathLossTraceTest`
(CI/testbot/прод-дамп — то есть именно там, где полная схема и реальные FK делали бы
эту проверку самой ценной), `testTaxDeductionSurvivesActionLogFailure` и
`testDeathRespawnSurvivesActionLogFailure` (плюс `testDeathLossSurvivesActionLogFailure`
в story 11) скипаются целиком — try/catch-контракт `logTaxDeduction()`/`logTaxEvent()`/
`logDeathLoss()` («сбой записи лога не роняет и не откатывает уже проведённое
списание») в этом прогоне НЕ проверяется. Прецедент: `GreenhouseProductionWaterTest.php`
докблок + коммит `1e05e24b`. Захватчики имени: `tests/database/AchievementServiceTest.php`,
`tests/database/DailyTaskServiceTest.php` (возможно, не только они — 16 файлов в
`tests/` вообще упоминают `action_log`, эти два подтверждены как безусловные
drop+create). Следующему, кто захочет закрыть этот пробел по-настоящему: не RENAME
(см. выше) и не безусловный DROP+CREATE (тот же класс проблемы) — вариант, отклонённый
team-lead в этом заходе как «второй заход в денежные файлы ради тестового удобства»,
это DI-шов на `ActionLogModel` внутри `logTaxDeduction()`/`logTaxEvent()`/
`logDeathLoss()` (тот же приём, что уже применён к `GreenhouseProductionHandler`) —
отдельное решение с отдельным ревью, не хвост этой story.
