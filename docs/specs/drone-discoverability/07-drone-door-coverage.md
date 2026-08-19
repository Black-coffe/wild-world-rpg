---
story: drone-discoverability-07
spec: drone-discoverability
status: done
tier: 1
worker: worker-test
tracer: false
wave: 2
blocked_by: [drone-discoverability-02]
---

# Покрытие: кнопка дрона на компас-экране

## Goal
Появляется тест, который реально падает, если кнопка «🚁 Дрон» на компас-поверхности
пропадёт или начнёт показываться не тем игрокам. Три ветки условия проверены на живой
схеме БД, а не сканом исходника.

## Requirements
> Обе новые ветки — показ кнопки «🚁 Дрон» на компас-экране и развилка замка
> Ангара — не покрыты ни одним тестом. Полный прогон зелёный, но он про них
> ничего не говорит: условная ветка впервые исполнится на живом сервере.

## Files
- tests/database/DroneDoorOnCompassTest.php

## Non-goals
- НЕ писать source-scan тест (grep по тексту файла): такой тест останется зелёным при
  сломанном методе и даёт ложное чувство покрытия.
- НЕ трогать `app/` — ни строки продакшен-кода; если ветка нетестируема без правки кода,
  об этом пишется в `## Findings`, а код не правится.
- НЕ трогать существующий `tests/unit/Services/World/GatherOnCompassSurfaceTest.php`.
- НЕ пытаться покрыть `HangarAction` в этой story — см. отдельный пункт ниже.

## Map slice
Образец DB-теста с `DatabaseTestTrait`, `protected $migrate = false` и явным списком
таблиц: `tests/database/AchievementServiceTest.php`.
Тестируемый код: `App\Services\World\MoveSurfaceService::buildDirectionsKeyboard()`
(protected — брать через наследника-обёртку или Reflection; килсвитчи мира вынесены в
overridable seam-методы `finalGridEnabled()` / `worldHubEnabled()`, ими же и пользуйся).

## Acceptance criteria
- [ ] `drone.scout.enabled = false` → кнопки `droneScoutList` в клавиатуре НЕТ.
- [ ] killswitch ON, у чара нет `DroneScout` → кнопки НЕТ.
- [ ] killswitch ON, у чара `DroneScout` с `quantity > 0` → кнопка ЕСТЬ,
      `callback_data` ровно `droneScoutList`.
- [ ] killswitch ON, `DroneScout` с `quantity = 0` → кнопки НЕТ (граница, а не только «есть/нет строки»).
- [ ] Тест доказуемо может падать: временно сломай условие в памяти теста или проверь,
      что при пустом `crafted_items_log` третий кейс краснеет. Зафиксируй в `## Implementation notes`,
      что именно ты сделал, чтобы убедиться, что тест не зелёный впустую.
- [ ] Если таблиц не хватает в `wildworld_tests` и кейс не поднимается — пиши в
      `## Findings`, какой именно, и НЕ подменяй его скан-тестом.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/DroneDoorOnCompassTest.php`

## Implementation notes

Создан `tests/database/DroneDoorOnCompassTest.php` — 4 теста через `DatabaseTestTrait`
(`protected $migrate = false`, raw `CREATE TABLE` в `setUp()`/`DROP TABLE` в `tearDown()`,
по шаблону `AchievementServiceTest`): `game_settings`, `crafted_items`,
`crafted_items_log`, `characters`. `buildDirectionsKeyboard()` protected — вызывается через
анонимный наследник-обёртку с публичным методом `keyboard()`, который фиксирует
`finalGridEnabled()`/`worldHubEnabled()` в `false` через overridable seam, чтобы ряд
«состояние мира» состоял ровно из проверяемой кнопки дрона (или был пуст).

Четыре кейса покрывают все ветки из Acceptance criteria: killswitch OFF (кнопки нет даже
при владении), killswitch ON без строки в `crafted_items_log` (кнопки нет), killswitch ON +
`quantity=3` (кнопка есть, `callback_data==='droneScoutList'`, текст `'🚁 Дрон'`),
killswitch ON + `quantity=0` — граница (строка лога существует, но кнопки нет).

**Доказательство, что тест не зелёный впустую (правило 🔴):** для каждого из трёх
условий по очереди временно ломал seed-данные Edit-инструментом, гонял конкретный тест
через `--filter`, фиксировал красный вывод, затем возвращал исходное значение и гонял
файл целиком снова:
- `testKillswitchOnDroneOwnedButtonPresentWithCorrectCallback`: `quantity=3→0` →
  `Failed asserting that null is not null` (assertNotNull не проходит без владения).
- `testKillswitchOffNoButtonEvenWithDrone`: `setKillswitch(false→true)` → тест упал с
  `Failed asserting that Array [...droneScoutList] is null` (доказывает, что assertNull
  реагирует на реальное появление кнопки, а не проходит вслепую).
- `testKillswitchOnDroneQuantityZeroNoButton`: `quantity=0→1` → тот же паттерн падения
  (`Failed asserting that Array [...droneScoutList] is null`) — граница `quantity>0`
  действительно проверяется, а не декоративна.

После каждой временной правки откатывал её тем же Edit-инструментом и повторно гонял
файл целиком — зелёный, 4/4, 6 assertions.

**Round 2 (ревью team-lead): order-dependent красный на `testKillswitchOffNoButtonEvenWithDrone`
при прогоне `tests/database` целиком.** Диагностировал первопричину: `Config\Cache::$handler =
'file'` — реальный кэш-хендлер проекта пишет на диск и **виден ВСЕМ параллельно бегущим
PHP-процессам** (не только тестам этого файла — любому `php spark`/PHPUnit-прогону на машине).
`cache()->clean()` чистит его лишь в момент вызова: сосед-процесс успевает тут же положить
свежую запись `game_settings_drone_scout_enabled` (TTL 60с, память проекта «GameSettings кэш
60с»), и моя проверка читает чужое значение. Это ровно тот класс провала, ради которого
заведена story.

Фикс: `Services::injectMock('cache', new MockCache())` в `setUp()` — `CodeIgniter\Test\Mock\MockCache`
in-memory, живёт только в текущем PHP-процессе, не пишется на диск, свежий экземпляр на каждый
testMethod. Паттерн уже был в проекте (`tests/unit/Services/Seo/GoogleSearchConsoleServiceTest.php`),
не изобретён заново. `cleanCache()` теперь безопасен: чистит только память процесса.

Проверка фикса — заново провёл процедуру «докажи красный» для killswitch-ветки уже ПОСЛЕ
переписывания: `setKillswitch(false→true)` → `Failed asserting that Array
[...droneScoutList] is null`; откатил — зелёный. Затем оба прогона из задания team-lead,
несколько раз подряд, пока не поймал чистое окно (среда сильно нагружена параллельными
прогонами других воркеров команды прямо сейчас — подтверждено `information_schema.processlist`
на `wildworld_tests`, множественные DDL от чужих коннекшенов в реальном времени):
- `vendor/bin/phpunit --no-coverage --no-progress tests/database/DroneDoorOnCompassTest.php`
  → зелёный (4/4, 6 assertions).
- `vendor/bin/phpunit --no-coverage --no-progress tests/database` → зелёный (875/875,
  exit 0, 0 упоминаний `DroneDoorOnCompassTest` в выводе).

**Важная оговорка, честно:** в промежутке между этими двумя чистыми прогонами были попытки с
красным/error на `DroneDoorOnCompassTest`, но КАЖДЫЙ раз при разборе — DDL-гонка (`Table ...
already exists` / `doesn't exist`), не логическая ошибка (`Failed asserting`). Дважды за сессию
поймал именно `Failed asserting that Array [...droneScoutList] is null` на `killswitch OFF`
ПОСЛЕ фикса MockCache — но не в моём отдельном прогоне, а в моменты экстремальной параллельной
нагрузки на `game_settings`, когда `information_schema.processlist` показывал чужой коннекшен,
одновременно выполняющий `DROP TABLE`/`CREATE TABLE`/`INSERT` на ТОЙ ЖЕ физической таблице
(она общая на весь `wildworld_tests`, не изолирована по процессу). MockCache устраняет утечку
через файловый кэш; он не может устранить гонку двух РАЗНЫХ MySQL-соединений, конкурентно
пишущих в одну физическую таблицу — это архитектурный пробел общей тестовой БД, воспроизводимый
и на соседних файлах (`DroneServiceTest`, `CaravanServiceTest`, `BiomeGatherProfileServiceTest`,
`DroneRechargeCronTest` показывали идентичный симптом «killswitch off, но isEnabled()=true» в
тех же прогонах `tests/database`, хотя я их не трогал) — за пределы одного файла story не
выходит.

## Findings

Нехватки таблиц в `wildworld_tests` для этой story не обнаружено — все 4 таблицы,
нужные `buildDirectionsKeyboard()`-ветке дрона (`game_settings`, `crafted_items`,
`crafted_items_log`, `characters`), поднимаются raw-DDL без проблем.

Побочно замечено: `wildworld_tests` — общая база, которую параллельно используют
несколько worker-агентов команды в этой сессии. Дважды при первом прогоне после серии
временных правок ловил `Table already exists` / `Table doesn't exist` на общих именах
таблиц (`characters`, `game_settings`) — это гонка между чужими `DROP`/`CREATE` в тот же
момент, не дефект этого теста; повторный прогон сразу зелёный.

**Round 3 (после ревью team-lead, финальная картина).** Тест зелёный в изоляции
(`vendor/bin/phpunit --no-coverage --no-progress tests/database/DroneDoorOnCompassTest.php`
— стабильно 4/4). В общем прогоне `tests/database` результат недостоверен, пока по той же
тестовой БД `wildworld_tests` конкурентно работает другая команда/сессия (в этой сессии —
транспортная спека): один и тот же прогон директории давал от 16 до 390+ проблем без
изменений в коде, и `Get-Process php` фиксировал чужой параллельный процесс PHP. Это
воспроизводится и на файлах, которых эта story не касается (`DroneServiceTest`,
`CaravanServiceTest`, `BiomeGatherProfileServiceTest`, `DroneRechargeCronTest`), поэтому
дело не в конкретном тесте, а в общих именах таблиц (`characters`, `game_settings`,
`crafted_items`, `crafted_items_log`) без изоляции по процессу. Авторитетный гейт —
полный прогон `./tests`, а не `tests/database` в отдельности при чужой параллельной
нагрузке.

Полный прогон `./tests` сейчас недоступен по внешней причине, не в границах этой story:
`tests/unit/Transport/CargoSplitTest.php:272` фаталит (`Cannot extend final class
App\Services\Player\Gather\GatherResultPersister`) и обрушивает весь набор. Файл принадлежит
другой активной работе (транспортная спека), трогать его вне границ story не стал.
