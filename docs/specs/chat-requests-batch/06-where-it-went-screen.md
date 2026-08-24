---
story: chat-requests-batch-06
spec: chat-requests-batch
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [chat-requests-batch-05]
---

# Экран «Куда ушло»

## Goal
У игрока появляется экран, отвечающий на самый частый вопрос за полгода: последние
события, менявшие его запас, — налог, мировое событие, продажа, покупка, крафт, склад,
смерть — одной лентой, с датой и суммой.

## Requirements
> [08.06.2026] Ivan Divan: «лога движения средств тоже нету и не понятно нихера»

> [09.08.2026] Max Syskov: «У меня исчезло 50% ресурсов, сравнивал "сегодня 15:03" и "сейчас". все время был на базе»

## Files
- app/Controllers/Telegram/Commands/Actions/Economy/WhereItWentAction.php
- app/Services/Player/LedgerService.php
- app/Config/CallbackRoutes.php
- app/Controllers/Telegram/Commands/Actions/InventoryAction.php
- app/Database/Migrations/2026-08-24-100000_SeedLedgerDepthSetting.php
- tests/unit/Player/LedgerServiceTest.php
- app/Services/Display/MarkdownSafe.php (довесок team lead — санитизация description/event_name/log_summary перед рендером)

## Notes
Лента сшивается из ДВУХ источников: `action_log` (действия игрока плюс новые строки из
story 05) и `event_effects_log` (мировые события; поле `effect_details` — JSON с
`gold_delta` и `magnitude.resource_loss_percent`). Сортировка по времени, глубина —
ключ `GameSettings` с полной rationale.

Вход — кнопка на экране «Инвентарь» рядом со «Складом базы»: там игрок и замечает
пропажу. Кнопка безусловная (UX-DISCOVERABILITY), пустая лента объясняет себя, а не
показывает тупик.

Медиа нет, весь смысл в тексте. Markdown только парный.

## Non-goals
- Не заводить новых источников логирования — их пишет story 05.
- Не строить админский отчёт: экран только про своего персонажа (правило веб-тира
  «приватные поля только своему персу»).
- Не показывать чужие сделки и не раскрывать сверх того, что игра уже говорит.
- Не делать экспорт и постраничную навигацию: одна лента фиксированной глубины.
- Не заводить новую таблицу, поэтому `WipeManifest` в этой story не трогаем.

## Descoped

**Ревью-раунд (после первого прохода).** Whitelist кодов `LedgerService::ICONS` изначально
покрывал 5 из 7 категорий Goal. По итогам ревью добавлены: `TAX_BUILDING_DESTROYED`
(снос здания за неуплату), `BASE_STORAGE_DEPOSIT`/`_ALL`/`BASE_STORAGE_RETRIEVE_ONE`
(склад), `CRAFT_SHORTFALL_BUY` (докупка нехватки под крафт — реальная трата золота),
`SHUFFLE_RESOURCES`, `GAMBLE_WHEEL`, `GAMBLE_GUESS`, `ORACLE_BET`, `SELL_GEAR`,
`TRIBUTE_BUYOUT`, `DRONE_REPAIR_RUN`, `DRONE_CARGO_SEND`.

Сознательно НЕ добавлены (проверено по коду, не по памяти):

- **Крафт как таковой** (расход ресурсов на постройку/предмет через
  `TaskHandlers/Craft/*`) — нигде не пишет `action_log` вообще, ни в каком виде.
  Заводить новое логирование здесь — прямое нарушение Non-goal «не заводить новых
  источников логирования» (что в этой story, что в предыдущей). Игроку об этом честно
  сказано в футере экрана: «Крафт (расход на постройку/предмет) в ленте пока не виден —
  сам процесс крафта не оставляет следа в логе.» Кандидат на отдельную story (сначала
  логирование, потом видимость).
- **`BUY_CRAFT` / `SELL_CRAFT` / `CRAFT_PORTABLE_TELEPORT`** — эти три кода пишутся в
  `action_log` ТОЛЬКО через `logRejected()` (провалившиеся попытки, `action_status='REJECTED'`).
  Реального, успешного (`Completed`) списания под этими именами в кодовой базе нет вовсе
  (проверено grep'ом по всем call site'ам). Добавление их в whitelist либо ничего не
  показало бы (после фильтра по `action_status`, который добавлен этим же раундом), либо,
  без фильтра, показало бы игроку ЛОЖНУЮ потерю за отклонённую попытку купить/продать —
  то есть было бы хуже отсутствия, а не лучше.

## Acceptance criteria
- [x] Создан и зелёный `tests/unit/Player/LedgerServiceTest.php` — он обязан падать на доправочном поведении,
      иначе гейт зелёный впустую (урок «скан исходника ≠ покрытие»).
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/InventoryAction.php`

## Findings

**Экран.** `WhereItWentAction` (`whereItWent`) — edit-in-place текст (без фото,
`MediaSender::editTextOrSend`), вход — безусловная кнопка «🧾 Куда ушло» на «Инвентарь»
между «Складом базы» и «Действия» (3 в ряд, не одиночка). `LedgerService::entries()`
всегда фильтрует по `character_id` вызывающего — чужие данные физически не запрашиваются.

**Источники, сшитые по времени (свежее сверху):**
- `action_log`, только economy-коды: `TAX_BUILDINGS`/`TAX_BEACONS`/`DEATH_RESPAWN`
  (story 05), `DEATH_LOSS` (story 11, уже несёт состав потерь), плюс уже жившие
  `BUY_RESOURCE`/`SELL_RESOURCE`/`BULK_SELL`. `description` показан КАК ЕСТЬ, не
  парсится — так экран не сломается, когда следующая story обогатит текст.
- `event_effects_log` (join на `events.name`), из `effect_details` JSON читаются
  `log_summary` и `gold_delta` (посчитаны диспетчером событий, здесь не пересчитываются).

**Глубина ленты** — `economy.ledger.depth` (int, default 15, category `resources`,
soft 5–30, hard 1–50), полный rationale/effect/above/below в
`app/Database/Migrations/2026-08-24-100000_SeedLedgerDepthSetting.php`. 15 — расчёт на
«вчера было, сегодня нет» при обычном темпе экономики персонажа, но не простыня в одном
сообщении. `LedgerService` поддерживает тест-оверрайд глубины/DB-соединения вторым/третьим
аргументом конструктора (тот же паттерн, что `VehicleEffectsService`), поэтому unit-тесты
не трогают `game_settings` вовсе.

**Пустая лента объясняет себя** («движения не записаны — не было налога/торговли/события/
смерти») — не тупик. **Caption ≤ 1024** — `renderScreen()` режет строки заранее, резервируя
бюджет под саму пометку об обрезке (иначе пометка, дописанная постфактум, сама выталкивала
текст за лимит — поймано тестом `testTextFitsWithinTelegramLimitOnWorstCaseDepth`, было
1060 символов до фикса).

**Чем тест краснел на доправочном поведении:** `LedgerService.php` — новый файл, `mv` его
в сторону и прогон `LedgerServiceTest.php` дал `Error: Class "App\Services\Player\LedgerService"
not found` на всех 11 тестах, вернул файл — снова зелёные 12/12 (12-й — тест
discoverability, добавлен после первой стабилизации). Это не модификация существующего
поведения (файлы новые), поэтому «доправочное» = «фичи ещё нет вовсе» — тот же смысл, что
и «стеш диффа», но для untracked-файлов делается через временный `mv`.

**tableExists()-гейт в тесте.** Локальная `wildworld_tests` разреженная — `action_log`,
`event_effects_log`, `events` (и `game_settings`) в ней не migrated. Поймал ту же ловушку,
про которую предупреждал team lead: `tableExists()` (cached=true) кэширует список ДО того,
как я вызываю его в проверках `! tableExists(...)` перед `CREATE TABLE` — первый
`resetDataCache()` в начале `setUp()` этого не покрывает, нужен ВТОРОЙ `resetDataCache()`
ПОСЛЕ всех `CREATE TABLE`, иначе `LedgerService::entries()` тем же соединением видит
стухший список и молча возвращает 0 строк (не падение — тихий 0, что даже коварнее).

**Довесок team lead (денежный путь): markdown-санитизация.** `description`/`events.name`
несут имена ресурсов/крафта из БД (`resources.name`, `crafted_items.name_rus`) — непарный
`_`/`*` в имени валит рендер ВСЕГО экрана тихим Telegram 400 (легаси-Markdown без
бэкслеш-эскейпа). Добавлен `MarkdownSafe::text()` (новый метод в уже существующем
`app/Services/Display/MarkdownSafe.php` — вырезает `* _ \` [ ]`, тот же список `BREAKING`,
что и у `MarkdownSafe::name()`, без fallback: пустая строка для целого предложения —
легитимный редкий исход). Применён в `actionLine()` (description) и `eventLine()`
(event_name + log_summary). Собственная разметка экрана (заголовки/выделение) не тронута —
только чужой текст из данных, как и требовалось. Обрезка длинной ленты режет ЦЕЛЫМИ
строками (не подстрокой), поэтому непарного `*`/`_` от самой обрезки не бывает —
структурно, не нужно отдельно защищаться.

Redness подтверждена: временно откатил 3 вызова `MarkdownSafe::text()` до `$description`/
`$eventName`/`trim($rawSummary)` как есть (`sed`, не руками) — 3 новых теста упали
(`does not contain "_"`, «непарная * роняет...»), вернул фикс — снова 15/15 зелёных.

**Верстка кнопок.** `InventoryAction` — третья кнопка в существующий ряд `[Склад базы,
Действия]` → `[Склад базы, Куда ушло, Действия]`, не сингл-ряд. `WhereItWentAction` — ряд
`[↩️ Назад → inventory, 🛒 Магазин → shop]`.

**Player-facing-вердикт (`.claude/rules/player-facing.md`):**
1. Discoverability — да, безусловная кнопка на «Инвентарь» (проверено тестом
   `testWhereItWentCallbackIsRoutedAndReachableFromInventory`).
2. Онбординг — контекстной JIT-подсказки НЕ добавил: экран сам себя объясняет с первого
   попадания (пустая лента объясняет причину, заполненная — самоочевидна по формату
   «дата — что случилось»). Отдельного шага в обучающей цепочке не заводил — вне Files
   истории (`OnboardingHintService`/`OnboardingChainCatalog` не в списке), и сам экран не
   требует предзнаний, чтобы быть понятным.
3. `/guide` — вердикт «да, стоит», но раздел НЕ добавлен: `GuideCatalog` не входит в
   `## Files` истории (Law 3 — не трогать файлы вне списка). Флагирую как follow-up:
   короткий раздел «где посмотреть, куда делись ресурсы» в «Путь новичка» примет прямой
   ответ на оба вопроса из Requirements.
4. Совет (`/tips`) — вердикт «да, стоит»: этот экран прямо отвечает на два реальных вопроса
   игроков (Ivan Divan, Max Syskov) и заслуживает проактивного «Совета дня». НЕ добавлен —
   `*Seed<Что>Tip.php` в `## Files` истории тоже нет. Follow-up.

**Известное ограничение (не в скоупе, честно называю).** `BUY_RESOURCE`/`SELL_RESOURCE`/
`BULK_SELL` пишут `description` служебной строкой (`res=12 qty=3 gold=-45`,
`ResourceTradeService.php:180`, `SellResourceAction.php:300`, `BulkSellAction.php:159`) —
не полноценная фраза, как у налога/смерти. Экран показывает её как есть (Non-goal —
description не парсится) с иконкой и категорийной меткой-префиксом («🛍️ Покупка: res=12
qty=3 gold=-45»), но сам служебный хвост остаётся нечитаемым. Эти три файла НЕ в `## Files`
истории — правка их описаний в человекочитаемый текст (по образцу story 05/11) — отдельная
маленькая story, не блокирует эту.

**phpstan L9 находки на новых файлах** (не только `InventoryAction.php` из Verification, но
и `LedgerService.php`/`WhereItWentAction.php`/`CallbackRoutes.php` по памяти
`feedback_phpstan_rerun_after_new_files`): generics на `BaseConnection`/`ResultInterface`,
`(string)$mixed` — все убраны штатными паттернами репо (`@var BaseConnection<object, object>`,
narrow-хелпер `resultArray()` по образцу `CraftTreeService::fetchAll()`, `str()`-хелпер вместо
прямого каста).

**Полный `vendor/bin/phpunit`** — НЕ смог зафиксировать чистый прогон: 6+ последовательных
запусков дали разное число тестов (3388→3399→3400→3402→3403→3406) и разное число
падений/ошибок (564→617→231→404→45→19→20→41), причём ни одно падение ни разу не
называло мои файлы — падали `AchievementServiceTest`, `TaxAndDeathTraceTest`,
`DemolishBuildingTest`, `PlayerEconomyServiceTest` («Table 'characters' already exists») и
т.п., классически по образцу гонки `tableExists()`-гейтов между параллельными воркерами
на общей `wildworld_tests` (та же ловушка, про которую предупреждал team lead, но между
процессами, не внутри одного). Изолированный прогон `tests/unit/Player/LedgerServiceTest.php`
стабильно зелёный (12/12) во всех повторах. Рекомендую team lead перепрогнать полный набор,
когда параллельные story этой волны завершатся и перестанут одновременно
создавать/дропать одни и те же гостевые таблицы.

## Ревью-раунд (вердикт BLOCK → закрыто)

Все 4 блокера + 3 мелочи из ревью денежного пути закрыты в `LedgerService.php` и
`WhereItWentAction.php` (плюс `above_effect_text` в миграции глубины ленты):

1. **Whitelist 5/7 → 15 кодов + честный `## Descoped`** (выше в этом файле). Добавлен
   фильтр `action_status='Completed'` — без него `SELL_GEAR`/`ORACLE_BET` показали бы
   REJECTED-попытки как реальные потери (нашёл при добавлении: тот же `action_name`
   пишется и `logActivity()`, и `logRejected()`).
2. **Пустая лента переписана обобщённо** — не перечисляет категорий (не устареет при
   следующем добавлении кода), не утверждает фактов о мире шире собственной видимости.
3. **Мировые события фильтруются по `gold_delta`/`magnitude.resource_loss_percent`**
   (raw SQL `JSON_EXTRACT` в `where()`, третий параметр `false` — иначе builder
   пытается экранировать сырую строку как идентификатор) — HP/атрибутные эффекты
   больше не вытесняют налог/торговлю из ленты. `magnitude.resource_loss_percent`
   (был назван в Notes, но не читался) теперь реально используется.
4. **Бюджет 1024→4096** (текстовое сообщение, не подпись к фото) + двухпроходная
   упаковка строк: резерв под пометку «…и ещё N» применяется ТОЛЬКО когда обрезка
   реально нужна (первый проход без резерва; если что-то не влезло — второй проход
   уже с резервом). Раньше резерв вычитался всегда, теряя последнюю строку на ровном
   месте.

Отдельно проверено по коду (не вслепую): `WhereItWentAction` действительно НЕ
делал edit-in-place — «🎒 Инвентарь» это PHOTO-сообщение (`InventoryAction::handle()`
шлёт `caption`), а `editMessageText` не умеет превратить фото в текст; `MediaSender`
документирует это сам (`editTextOrSend()` — комментарий про «клик по photo-сообщению»)
и молча уходит в fallback новым сообщением при каждом заходе. Раньше в Findings/docblock
это ошибочно называлось «edit-in-place» — исправлено и в тексте, и в коде: переключено
на прямой `Request::sendMessage()`, тот же паттерн, что у `BaseStorageListAction`
(соседний текстовый экран, тоже открывается из фото-Инвентаря).

`sourcesComplete()` — новый метод: `entries()` теперь помнит, были ли ОБА источника
(`action_log`, `event_effects_log`) реально прочитаны, и `renderScreen()` рендерит
честную ветку «не смогли проверить» вместо «ничего не менялось», когда таблица
пропала. Проверено тестом с реальным `DROP TABLE` посреди теста (не мок).

Redness подтверждена реконструкцией до-ревьюшной версии файла (сохранена копия,
раньше читанная в этой же сессии) — прогон нового/изменённого набора тестов дал 7
явных failures, ровно по тем формулировкам, которые проверяют новое поведение;
восстановление фикса вернуло 25/25 зелёных.

Тест-файл вырос с 12 до 25 тестов; все стабильно зелёные при повторных прогонах.
phpstan на `LedgerService.php` + `WhereItWentAction.php` — 0 ошибок.
