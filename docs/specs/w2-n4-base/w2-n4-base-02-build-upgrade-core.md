---
story: w2-n4-base-02
spec: w2-n4-base
status: todo
returned:
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

## Findings
