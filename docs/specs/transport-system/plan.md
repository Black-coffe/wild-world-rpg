# Система транспорта (plan)

**Tier:** 4 · **Spec slug:** `transport-system` · **Brief:** [brief.md](brief.md)
**Governed by:** [concept-final.md](concept-final.md) (источник истины по дизайну, отменяет `concept-draft.md`) ·
`mmorpg-vault/decisions/ADR-174-Transport-system.md` (**с поправкой владельца, см. Assumptions #1**) ·
ADR-019 (движение и есть разведка) · ADR-024 (admin-tunable balance) · ADR-131/138 (тонкий ридер, тестируемый без БД) ·
ADR-157 (анти-эксплойт цены) · ADR-171 (рюкзак и склад — общий пул) · ADR-172/173 (крафт-страховка, закрытый кран наград) ·
ADR-087 (WIPE-COVERAGE) · ADR-020 (media-off) · ADR-127 (/guide) · ADR-134 (tips)
**Depends on:** `docs/specs/transport-system/recon-digest.md` (4 отчёта drone-scout, 2026-08-19)

## Goal

Оживить мёртвую категорию `type='transport'`: пять машин (одна общая + четыре фракционные)
получают рецепт, экран, активацию и **реальный механический эффект** на самое частое действие
игры — Поход (953 за 14 дней). Эффект строится тремя величинами: усталость за клетку, длина
приказа и размер пачки клеток за тик (абсолютное целое из ряда {3,4,5}, не дробный множитель),
причём бонус смотрит на **фронтир**, а не на разведанный тыл. Грузовая машина ничего не создаёт
— уводит долю уже добытого на склад базы. Всё сидит на указателе `characters.active_vehicle_log_id`
и на килсвитче `world.vehicle.enabled`, дефолт `false`: до его включения арифметика перемещения
**байт-идентична сегодняшней**. Уровни и фракции решают, какая машина кому доступна; экран выбора
фракции обязан сказать об этом ДО необратимого выбора.

## Assumptions

<!-- Law 1. Всё ниже человек может ветировать на approval stop. -->

1. 🔴 **Поправка к ADR-174, инвариант №9.** ADR писался до решения владельца и утверждает
   «смерть забирает транспорт». Владелец в `brief.md → ## Answers` решил иначе:
   **«Разбивается, но не пропадает»**. План строится по решению владельца: транспорт
   исключается из `computeCraftLoss`, смерть обнуляет износ активной строки и нейтрализует
   указатель. ADR правит Queen отдельно; story написаны по решению владельца.
2. `## Answers` в `brief.md` — не блокквоты, поэтому `trace-check.sh` их не видит.
   Все `## Requirements` цитируются из абзаца запроса; решения владельца (грандфазер 21 строки,
   узаконенный скот Фермеров, «разбивается, но не пропадает») входят в story как **цели**,
   опираясь на этот раздел плана, а не на цитату.
3. **Скорость — абсолютное целое, не множитель** (concept-final §0.1). Там, где ADR-174 говорит
   «множитель клеток за тик», реализуется абсолютное значение с зажимом `max(base, value)`
   и `hard_max=5`.
4. На маршруте в **3 клетки** ETA пешком и на общей повозке совпадают (`ceil(3/3)==ceil(3/4)==1`).
   Читаемость бонуса на коротком маршруте обеспечивает строка усталости и длины приказа,
   а не минуты. Инвариант «минимум 1 минута разницы» проверяется на 4–5 клетках.
5. Пять рецептов авторятся **заново** из реальной палитры ресурсов. Легаси-поле
   `required_resources` в каталоге ссылается на несуществующие «Wooden Beams», «Rope», «Wheels»,
   «Horse», «Rubber», «Propane Burner» — оно не источник истины и не переносится.
6. JIT-подсказка кладётся в существующую инфраструктуру one-shot подсказок (`character_onboarding`,
   ключевой ряд), новая таблица не нужна. Если разведка на месте покажет иное — story 12 сообщает
   в RETURN REPORT, Queen пишет Plan delta.
7. `php spark images:generate` требует `images.api_key`, который живёт только в локальном `.env`.
   Story 07 добавляет **записи в `Config\ImageRegistry`**; сама генерация пяти картинок —
   шаг Queen в разделе `## Порядок выката`, не часть story.
8. Плот / Лодка с мотором / Парусник — **не трогаем** (заморожены за будущим водным ADR).
   Воздушный шар — `status: deprecated`. Верблюд остаётся мёртвым до пустынной линии.
9. 21 багованная строка владения — грандфазер: **ничего не удаляется и не изымается**,
   упоминание идёт строкой в анонсе.

## Stories

**Волна 0 — фундамент, ноль видимых игроку изменений**
- `transport-01` — `MarchPaceService`: схлопнуть дубль формулы ETA, оба call-site на него, тесты байт-идентичности.
- `transport-02` — `VehicleEffectsService` (чистый профиль без БД) + seed `world.vehicle.*` с килсвитчем `enabled=false`.
- `transport-03` — колонка `characters.active_vehicle_log_id` + `VehicleActivationService` (активация/снятие/износ/самолечение) + `WipeManifest`.

**Волна 1 — механика**
- `transport-04` — Поход: персональная пачка клеток за тик, адресный износ, ETA-разбивка в превью.
- `transport-05` — одиночный шаг: множитель усталости + вынос трёх hardcoded-констант в `world.move.*`.
- `transport-06` — пять рецептов в `Config\CraftRecipes` + миграции каталога (переименование, deprecate, цены).
- `transport-07` — проводка generic-крафта: `Worker.php`, `CallbackRoutes`, строки `tasks`, `ImageRegistry`, сведение эмодзи категории.
- `transport-08` — карго-сплит в `GatherResultPersister` с инвариантом сохранения массы.
- `transport-09` — смерть **разбивает, но не забирает**: исключение транспорта из `computeCraftLoss` + обнуление износа.

**Волна 2 — поверхность**
- `transport-10` — экран «🚚 Мой транспорт»: активация/снятие, износ в клетках, окупаемость, витрина фракционных машин, вход из Персонажа.
- `transport-11` — экран выбора фракции: строка на каждую фракцию с её машиной и честным «навсегда».
- `transport-12` — крючок «🔒 Транспорт — с 6 уровня» в экране Похода, JIT-подсказка, раздел `/guide`.
- `transport-13` — советы (новый + обновление живого `MarchSpeed`) и канон `GAME_DESCRIPTION.md`.

**Почему волна 3 из ADR схлопнута.** ADR-174 держал смерть отдельной волной; её файл
(`LootProcessor`) не пересекается ни с одной story волны 1, а зависит только от волны 0
(`VehicleActivationService::breakActive()`). Держать её последней означало бы, что между
волнами 1 и 2 у 13 грандфазерных владельцев транспорт **изымается** смертью — ровно то
поведение, которое владелец отменил. Поэтому `transport-09` едет в волне 1.
Бывшая «волна 3 — доказательство» — это не story, а `## Порядок выката` ниже.

## Contracts

Интерфейсы, согласованные ЗДЕСЬ. Воркеры волны 0 пишут их параллельно и не изобретают на месте.

**Профиль транспорта** — плоский массив, единственная валюта между сервисами:

```
[
  'key'                  => ?string,  // null = нет транспорта / килсвитч off
  'cells_per_tick'       => int,      // АБСОЛЮТНОЕ значение для запрошенного terrain, >= base, hard_max 5
  'tired_factor'         => float,    // множитель усталости за клетку, нейтраль 1.0
  'max_steps_per_order'  => int,      // нейтраль = world.march.max_steps_per_order
  'cargo_share'          => float,    // 0.0 .. 0.33, нейтраль 0.0
  'wear_per_cell'        => int,      // 0 при нейтрали
]
```

**`App\Services\World\MarchPaceService`** (чистый, без БД; story 01):
- `cellsPerTick(int $base, array $profile): int` — `min(5, max($base, $profile['cells_per_tick']))`
- `etaMinutes(int $cells, int $cellsPerTick, int $minutesPerCell): int` — `ceil($cells / $cellsPerTick) * $minutesPerCell`
- `stepDueInterval(int $minutesPerCell): int` — `max(0, $minutesPerCell - 1)`, **профиль сюда не попадает никогда**
- `tiredCostPerCell(float $base, array $profile): float` · `healthCostPerCell(float $base, array $profile): float` (здоровье профиль не трогает — возвращает базу)

**`App\Services\World\VehicleEffectsService`** (story 02): конструктор `(?GameSettingsService $settings = null, ?array $overrides = null)`.
- `neutralProfile(): array` — контракт выше в нейтрали
- `profileFor(?string $vehicleKey, string $terrain): array` — `$terrain` ∈ `MarchPaceService::TERRAIN_EXPLORED|TERRAIN_UNEXPLORED|TERRAIN_COLD`
- `isEnabled(): bool` — `world.vehicle.enabled`; `false` → `profileFor()` всегда отдаёт нейтраль

**`App\Services\Player\VehicleActivationService`** (story 03):
- `resolveActive(int $characterId): ?array` — `['log_id'=>int,'key'=>string,'charges'=>int]`; читает `WHERE id = ? AND character_id = ?`; промах → указатель в `NULL`, возврат `null`, **исключения нет**
- `activate(int $characterId, int $logId): bool` — чужая строка → `false` без записи; повторная активация **переставляет** указатель
- `deactivate(int $characterId): void`
- `spendCharges(int $characterId, int $cells, int $wearPerCell): int` — списывает с той самой строки по `id`, читает через `effectiveCharges()` с зажимом `min(dur, base)`, возвращает остаток; на нуле бонус исчезает, поход **не блокируется**
- `breakActive(int $characterId): void` — износ активной строки в 0 + указатель в `NULL`; строку **не удаляет** (смерть)

**Ключи машин** (единые для GameSettings, профиля и `ImageRegistry`):
`cart` · `mtb` · `snowmobile` · `draft_cart` · `drone_auto`.
**Ключи рецептов** (литералы callback `genericCraft_<Key>_1`):
`LightCart` · `MountainBike` · `Snowmobile` · `DraftCart` · `AutonomousDrone`.

**GameSettings** (категория `world`, все с `rationale/effect/above/below/recommended/hard`):
`world.vehicle.enabled=false` (killswitch) · `world.vehicle.cargo.max_share=0.33` ·
`world.vehicle.<key>.cells_per_tick_explored` · `.cells_per_tick_unexplored` · `.cells_per_tick_cold` ·
`.tired_factor` · `.max_steps_per_order` · `.cargo_share` · `.charges_full` · `.wear_per_cell` ·
`.required_level` · `.required_faction`.
Значения из `concept-final.md §1` (пол усталости 0.375 → `tired_factor` не ниже 0.75; `hard_max` клеток 5):

| key | explored | unexplored | cold | tired_factor | order | cargo | charges | wear |
|---|---|---|---|---|---|---|---|---|
| `cart` | 4 | 4 | 4 | 0.90 | 90 | 0.20 | 300 | 1 |
| `mtb` | 4 | 5 | 5 | 0.80 | 90 | 0.00 | 350 | 1 |
| `snowmobile` | 4 | 4 | 5 | 1.10 | 120 | 0.25 | 400 | 2 |
| `draft_cart` | 4 | 4 | 4 | 0.75 | 120 | 0.33 | 400 | 1 |
| `drone_auto` | 3 | 3 | 3 | 0.75 | 90 | 0.00 | 350 | 1 |

`world.move.health_cost_base=0.1` · `world.move.tired_cost_base=3.35` ·
`world.move.danger_tired_surcharge=1.15` — дефолты **равны нынешним hardcoded** (story 05).

**Callback-маршруты** (регистрирует story 10 — в том числе те, что зовёт story 12):
`vehicleScreen` · `vehicleActivate_<logId>` · `vehicleDeactivate` · `vehicleShowcase` · `vehicleLockInfo`.
Story 07 регистрирует только пять `genericCraft_<Key>_1` и свой экран категории.

**Класс местности** решается **по следующей клетке**, один lookup за тик (тот же, что уже
делает `MarchAction::biomeAhead()`). Холодные биомы — Горы + Тундра.

## Порядок выката (два тега в один день)

**Шаг 1 — ядро + общий транспорт** (видят 616 игроков уровней 1–4 как цель, владеют — с 6):
волны 0–1 без фракционных рецептов и без карго (`world.vehicle.<фракционный>.*` остаются, но
рецепты 4 фракционных машин выкатываются с `required_level` уже на месте — гейт закрывает их сам),
`world.vehicle.cargo.max_share=0` на старте. Тег на `develop`, миграции через `deploy/post-deploy.sh`.
**Если выедет только один шаг — это должен быть первый.**

**Шаг 2 — фракционные машины + груз**: волна 2 (экраны, витрина, экран фракции, /guide, советы)
+ подъём `world.vehicle.cargo.max_share` до 0.33 через admin UI.

Между шагами: включить `world.vehicle.enabled=true` **на preprod-testbot**, замерить темп и
частоту встреч, только потом флипать на проде через admin UI (кэш GameSettings 60с).

**Перед тегом:** сверить состав диффа (в репозитории бывает параллельная сессия);
прогнать `php spark images:generate` для пяти новых ключей `ImageRegistry` локально и
закоммитить файлы; анонс с отдельной строкой про 21 грандфазерную строку.

**Что смокать Tier 2** (MCP Chrome, 1440/768/375, console clean):
`/admin/game-settings/world` — все ключи `world.vehicle.*` и `world.move.*` видны, имеют
rationale/above/below, Reset-to-default работает; `/admin/wipe` preview показывает
`characters.active_vehicle_log_id` в полезной нагрузке сброса.

**Что смокать Tier 3** (Telegram Web со 2-го аккаунта, testbot `telegram_user_id=25`):
крафт повозки от кнопки в «🚚 Транспорт» до готового предмета; экран «🚚 Мой транспорт»
(активация → снятие → повторная активация); Поход 5+ клеток с транспортом и без — сверить
ETA превью с фактом и увидеть строку разбивки; экран выбора фракции; витрина у персонажа
**без** фракции (🔒 объясняет путь); `disable_media=1` — все экраны полны текстом;
износ до нуля → поход идёт, бонуса нет, предупреждение пришло; смерть → «разбита», строка на месте.

## Integration gate

Перед каждой волной: `bash scripts/wave-check.sh docs/specs/transport-system`
После последней волны:
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Open questions (recon — если воркер не найдёт по карте, Queen шлёт drone-scout)

1. Точный путь и класс **экрана выбора фракции** (story 11) — в дайджесте его нет.
2. Точный путь `GatherResultPersister`, `LootProcessor`, `CraftTypeLabels`, `CraftedResourcesAction`,
   `MarchingTaskHandler` (дайджест называет классы, но не директории) — story ставят глоб.
3. Существует ли готовый one-shot механизм JIT-подсказок с ключом (assumption 6).
4. Реальное распределение размеров стека добычи — нужно, чтобы `floor(qty × share)` не
   оказался нулём в 90% случаев (урок «процент от одной штуки = ноль»). Snapshot с прода
   до включения `cargo.max_share`.

## Descoped

*(empty)*

## Plan deltas

*(empty)*

**Approved:** <owner, date>

## Процесс (закрывает процессные требования брифа)

- **«собери редколлегию, проведи брейншторм»** — исполнено до нарезки story. Панель из пяти:
  гейм-дизайнер, лор-надзиратель, игрок 1–9 ур., игрок 30+ ур., архитектурный консилиум.
  Все пятеро вынесли `revise`; их находки развернули четыре решения черновика (абсолютные
  клетки вместо множителей, фронтир вместо тыла, усталость и длина приказа вместо скорости,
  дрон Инженеров не грузовой). Итог — `concept-final.md` §0; вердикты не хранятся отдельными
  файлами, они уже применены к концепту и к story.
- **«через AvtoPilot всё это запусти»** — после одобрения план исполняется `/vulyk-build`:
  волны воркеров, один коммит на story, затем `/vulyk-review` (`lead-review` +
  `drone-acceptance`), Tier-2/Tier-3 смок и выкат по §Порядок выката. Отдельной story
  на автопилот нет и быть не может: это способ исполнения плана, а не единица работы.
- **`transport-01` не имеет прямой строки-заказа за собой** — это архитектурная предпосылка:
  ETA считается в двух местах (превью маршрута и обработчик), и до появления персонального
  профиля дубль обязан схлопнуться, иначе самый частый экран игры начнёт врать владельцу
  транспорта. Обоснование — ADR-174, развилка 2.

## Открытое допущение (решает владелец при одобрении)

Фраза брифа «и персонажа перевозить» прочитана как **«везёт самого выжившего»** —
в противопоставление грузовой роли, — а не как «везёт второго игрока-пассажира».
Пассажирских механик (посадить другого персонажа, ехать вдвоём) в плане нет: в игре
нет ни групп, ни совместных задач, и это была бы отдельная подсистема, а не свойство
транспорта. Если имелось в виду именно катание второго игрока — это отдельная спека.

## Поправка контракта (внесена в билд, волна 1)

**Отображение «предмет → профиль машины».** Воркер `transport-04` остановился с
`NEEDS_CONTEXT`: `VehicleActivationService::resolveActive()` отдаёт `key` = сырое
`crafted_items.name_eng`, а профили в `GameSettings` живут под собственными ключами
машин (`world.vehicle.<key>.*`). Совпадения между ними нет, а `profileFor()` на
незнакомый ключ молча возвращает нейтраль — то есть без явного отображения каждая
активированная машина не делала бы ничего и никогда, и ни один тест этого бы не заметил.

Решение: единственный владелец ключей машин — `VehicleEffectsService`. В нём заводится
константа-карта и резолвер:

| `crafted_items.name_eng` | ключ профиля |
|---|---|
| `LightCart` | `cart` |
| `MountainBike` | `mtb` |
| `Snowmobile` | `snowmobile` |
| `DraftCart` | `draft_cart` |
| `AutonomousDrone` | `drone_auto` |

`keyForItemNameEng(string $nameEng): ?string` — единственный легальный способ перевода.
🔴 Тихая нейтраль на неизвестном имени запрещена: тест обязан падать, если хоть один
из пяти рецептов `Config\CraftRecipes` несёт `item_name_eng`, которого нет в карте.
Это ловит расхождение между story-06 (кто именует предметы) и story-04 (кто их читает).

## Добавлено в ходе сборки

- **`transport-14` — подключение разбивания машины к живому потоку смерти.** Воркер
  story 09 реализовал и покрыл тестами `breakActiveVehicleOnDeath()`, но `DeathService`
  не входил в её `## Files`, и вызова из реального потока не появилось. Способность без
  вызова — это BUILT-BUT-DEAD с зелёными тестами; разрыв закрывается отдельной story,
  а не дописыванием чужого файла задним числом.
- **`transport-15` — свернуть строку про груз в существующее сообщение о добыче.**
  Story 08 не могла этого сделать: форматтер сообщения о добыче не входил в её `## Files`,
  и увезённый груз анонсируется вторым сообщением подряд. При 267 добычах за две недели
  это удвоение потока в чат у активного игрока — шум, а не информация.
