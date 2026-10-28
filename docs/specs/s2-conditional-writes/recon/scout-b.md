# Scout B — F16, F21, F23, F24, торговля, телепорт (2026-10-28, HEAD 17435721)

Два прохода + досверка Королевой (`TeleportUseAction`). Grep/Glob по каталогам в субагенте не работали.

| Пункт | Статус | Где | Суть / правка | Тесты |
|---|---|---|---|---|
| F16 | STILL PRESENT | `app/Services/Events/IntentApplier.php:52-76` (+ ресурсы `:143-151`, метеор `:166-178`, продление задачи `:211-213`) | абсолютная запись со снимка `$character` (gold `:63`, health/tired с полом 0.01, атрибуты). Правка: `CharacterStatsService::adjust($charId,$deltas,$floors)`; `adjust` по умолчанию зажимает gold ≥ 0 — сегодня gold не зажат. Только крон | PoC нет; событийные тесты — не проверено |
| F21 | STILL PRESENT | `Actions/FinishTaskAction.php:67-113` | `update(status=>interrupted)` без условия `:90`, штраф `adjust` `:113` безусловно → двойной тап = двойной штраф. Правка: `transitionIfCurrent(character_tasks,id,status,in_work,interrupted)`, не Applied → ветка «Задача не найдена или уже завершена.» `:76` | PoC нет |
| F23 | STILL PRESENT | `app/TaskHandlers/DeathRouletteHandler.php:64-95` | `findAll()` кандидатов → `rand` → `handlePlayerDeathAndReward` без клейма. Аудит предлагал колонку `death_roulette_claimed_at` (миграция); альтернатива — `NamedLock` на весь прогон. Только крон, прод: 0 наложений за 30 дней | PoC нет |
| F24 | STILL PRESENT, но форма иная | `CompleteRobotGatheringHandler.php:99` (+ выдача `:268-283` read-then-write), `CompleteRobotExplorationHandler.php:78` | `Worker.php:101-109` уже атомарно клеймит `in_work→completed` до `handle()` `:124`; запись в обработчике — избыточный дубль; условный переход внутри всегда дал бы Refused. Правка: убрать дубль + выдачу через относительный `increment`/`insertUnique` | PoC нет |
| `bulkSellResources` | CHANGED, частично | `Services/Player/Trade/ResourceTradeService.php:600-666` | транзакция, FOR UPDATE, токен, `decrementIfAtLeast` — есть (хотфикс 08.10). Открыто: `increaseGold` `:657` результат не проверяется. Правка по образцу `sellResource :187` | `tests/database/BulkSellResourcesTest.php` (8 методов, вкл. `testSameConfirmTwiceSellsOnce`); теста отказа `increaseGold` нет |
| «Забрать всё» регресс | не найден по имени | `tests/database/BaseStorageBotParityTest.php` — только экраны | регресс-тест одновременного «Забрать всё» отсутствует (по именам методов) | — |
| `sellResource()` | **FIXED** | `ResourceTradeService.php:118-191` | списание первым внутри `transBegin`, каждый отказ через `$fail()` с откатом. Пункт брифа закрыт хотфиксом 08.10 | — |
| Телепорт | STILL PRESENT (вред игроку, не дюп) | `Actions/Camp/TeleportUseAction.php:172-178`, `:227-234`; `Services/Player/TeleportUse/{TeleportItemConsumer,TeleportExecutor}.php` | CAS заряда коммитится (autocommit), затем `TeleportExecutor::teleport()` вне транзакции — сбой перемещения = заряд потерян | `TeleportChargeTest` (F7, зелёный) |

## Коллизии
Все пункты в разных продовых файлах. Два обработчика роботов — одному воркеру. Миграция (если F23 через колонку) — не параллельно с другими миграциями.
