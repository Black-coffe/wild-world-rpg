---
story: w2-n4-base-02
spec: w2-n4-base
status: done
returned: DONE
tier: 2
worker: worker-code
model: opus
wave: 2
blocked_by: [w2-n4-base-01]
---

# Ядро записи: каталог стройки, карточка, атомарный старт и апгрейд

## Goal
`App\Services\Buildings\BuildOrderService` (`catalog`, `preview`, `start`) и
`App\Services\Buildings\BuildingUpgradeService` (`preview`, `apply`) несут все гейты стройки и апгрейда
в прежнем порядке и с прежними кодами отказа в `action_log`. `BuildListAction`,
`GenericBuildingInfoAction`, `GenericBuildingAction` и `UpgradeBuildingAction` становятся рендерерами.
Ядро закрывает гонки: старт больше не «проверил, потом списал», а апгрейд не проходит без золота и не
поднимает уровень дважды.

## Requirements
> 1. База — одно ядро для бота и веба: обзор, выбор базы, список построек, карточка, старт стройки и апгрейд — сервисы с моделью экрана; handler'ы бота — рендереры, тексты и кнопки прежние (кроме п.5).
> 4. Атомарность: ресурсы/предметы/золото — условной записью; лимиты и «уже строится» — под блокировкой; двойное нажатие / два клиента не дают вторую стройку.
> 5. Мульти-база: обзор, «Строить», карточка, апгрейд, «назад» несут номер базы, ядро перепроверяет его в обоих клиентах.

## Files
- app/Services/Buildings/BuildOrderService.php
- app/Services/Buildings/BuildingUpgradeService.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeApplier.php
- app/Services/Player/BuildingUpgrade/BuildingUpgradeValidator.php
- app/Controllers/Telegram/Commands/Actions/Camp/BuildListAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/GenericBuildingInfoAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/GenericBuildingAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/UpgradeBuildingAction.php
- tests/database/BuildOrderServiceTest.php
- tests/database/BuildingUpgradeServiceTest.php
- tests/unit/Camp/BuildBotParityTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать легаси `StartBuild*`/`Build*Construction` и completion-handler'ы, кроме доставки завершения web-only, если она теряется (тогда — в Findings и минимальная правка по образцу N3).
- Не трогать `RoboticsWorkshopUpgradeAction`, ремонт, снос.
- Не менять длительности, стоимости и гейты: только перенос и атомарность.

## Map slice
`memory/map/bases.md` — Entry points (апгрейд через `CallbackPrefixDispatcher`), Gotchas (ADR-181); `memory/map/craft.md` — образец `CraftOrderService` (условное списание пула).

## Acceptance criteria
- [ ] Снимки бота до/после: список «Строить» (замки уровня, «уже построено»), карточка (хватает / не хватает), старт (успех, нет материалов, не на базе, лимит), апгрейд (запрос, подтверждение, отказы) — текст байт-в-байт; кнопки — с `_b<id>` там, где база известна.
- [ ] Два параллельных `start()` на ресурсы для одной стройки: одна задача, одно списание; второй — отказ с кодом, без частичного списания.
- [ ] `apply()` без золота → отказ, уровень и ресурсы нетронуты; два параллельных `apply()` → уровень +1 и одна оплата.
- [ ] `start()`/`apply()` с базой, которую `resolveForBase` не подтверждает, → отказ, ничего не пишется.
- [ ] `task_settings.base_cell` пишется, как сейчас (ADR-102).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `App\Services\Buildings\BuildOrderService`: `catalog()` (список «🏗 Строить» — порядок/налог прежние, Навес новичку, замки уровня, `built_count` из `amount` на базе), `preview()` (гейты карточки в прежнем порядке + материалы/зависимости/время/оговорки; модель полная при любом коде), `start()` (гейты старта в прежнем порядке с прежними кодами `BUILD_<Key>` в `log`; сам лог пишет бот через `logRejected`, веб — своим путём).
- `App\Services\Buildings\BuildingUpgradeService`: `preview()`/`apply()` поверх `BuildingUpgradeValidator` + `BuildingUpgradeApplier`; хуки после апгрейда (очки фракции, квест FarmersHarvest) переехали из `UpgradeBuildingAction` в ядро — общие для веба. Отклонение от контракта плана: апгрейд адресуется типом постройки `buildings.id` + база (как у бота `upgrade_building_<id>[_b<id>]`), а не `char_building_id`.
- Атомарность (ask 4). Старт: материалы проверяются по снимку, списываются в транзакции условной записью (`decrementIfAtLeast` по строке рюкзака; предметы с `deleteWhenEmpty`), лимит базы перепроверяется под `SELECT … FOR UPDATE` строки персонажа; любой отказ — откат целиком (код `race`/`cell_full`). Апгрейд (`BuildingUpgradeApplier::apply`): золото — `decrementIfAtLeast` (было `CharacterStatsService::adjust` с полом 0 → при гонке апгрейд бесплатный), уровень — `UPDATE … WHERE level = nextLevel-1` (второе подтверждение откатывает свою оплату). Порядок «ресурсы → золото → уровень» сохранён (insurance-06).
- Материалы стройки — рюкзак (`character_resources`), как было у бота. План предполагал пул рюкзак+склад — это расхождение плана с кодом, не менял (non-goal: гейты/стоимости не трогать). Plan delta.
- Ask 5: карточка и старт принимают `_b<id>` (раньше суффикс ломал разбор ключа: «Неизвестное здание: Workshop_b1»), ядро требует `resolveForBase() == on_base`. Список «Строить» с суффиксом несёт его в `genericBuildInfo_<Key>_b<id>`, карточка — в `genericStartBuild_<Key>_b<id>` и в «🏗 Строить»/«🏠 База» отказа Навеса. Кнопка «❌ Отмена» апгрейда (`Base`) живёт в `BuildingUpgradeMessageFormatter` — вне файлов story, суффикс не добавлен (Finding).
- Паритет: `BuildBotParityTest` — 27 сценариев (список: новичок с замками и Навесом, ветеран, с суффиксом; карточка: хватает, не хватает, зависимости, уровень, не на базе, нет лагеря, дубль, суффикс; старт: успех, дубль, нехватка, зависимости, уровень, не на базе, лимит базы, суффикс; апгрейд: запрос/нет золота/нехватка ресурсов/не на базе/суффикс, подтверждение/нет золота/суффикс). Снимок ДО снят с кода после story 01: тексты, кнопки, задачи, остатки, золото, уровни, `action_log`. После — байт-в-байт; сознательные отличия: суффикс в кнопках сценариев с суффиксом (ожидание для «до»-сломанных суффиксных берётся у близнеца без суффикса) и `"have":N` числом, а не строкой, в описании отказа `missing_materials`.
- Гонки в `BuildOrderServiceTest`/`BuildingUpgradeServiceTest` детерминированы: слушатель `DBQuery` на точке между проверкой и записью, второе соединение делает то, что сделал бы параллельный клиент (забирает последний предмет; ставит свою стройку в последний слот; тратит золото; закоммичивает тот же апгрейд). Проверено: одна задача/одно списание, откат без частичного списания, +1 уровень и одна оплата, без золота апгрейд не проходит. `task_settings.base_cell` пишется (ADR-102).
- Завершение стройки у web-only: `GenericBuildingCompletionHandler` шлёт в `telegram_users.telegram_id` — у веб-игрока это виртуальный чат, `WebDelivery` кладёт во входящие. Не теряется, правка не нужна.
- Тестовая гигиена: `BuildBotParityTest` лежит в `tests/unit` рядом с тестами, читающими общие таблицы тест-БД — чужие таблицы на время теста переименовываются и возвращаются, кэш списка таблиц соединения сбрасывается (`tableExists()` соседей иначе видел снесённое).
- `BuildingUpgradeApplier::$statsService` больше не используется для золота; оставлен `protected` как шов `PoolAdoptionRepairUpgradeTest` (тест подменяет его рефлексией).
- phpstan-baseline: удалено 36 устаревших записей (экраны перестали собирать данные сами).

## Findings
- «❌ Отмена» на экране подтверждения апгрейда ведёт на голый `Base` — суффикс базы не несёт (файл `BuildingUpgradeMessageFormatter` вне story). Для мульти-базы это возврат в пикер, а не на ту же базу; тот же хвост, что в multibase-picker.
