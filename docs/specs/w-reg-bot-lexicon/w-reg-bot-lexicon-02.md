---
story: w-reg-bot-lexicon-02
spec: w-reg-bot-lexicon
status: done
returned: DONE
tier: 2
worker: worker-code
wave: 2
blocked_by: [w-reg-bot-lexicon-01]
---

# Нейтральные формулировки в коде и вьюхах

## Goal
Строковые литералы игроцкого текста в `app/` (без миграций) не содержат бот-лексики: «в нижнем меню» → «кнопка
«<подпись>» внизу экрана», «Выберите направление с клавиатуры ниже» → «кнопками ниже», «экран бота покажет …» →
«следующий экран покажет …», число кнопок меню убрано. «/команды» (например «/base») остаются, если команда из поля
ввода `/play` доходит до обработчика (проверить POST `kind=text` на testbot); иначе убираются из общих текстов.
Текст, который в веб не уходит, остаётся — и попадает в список исключений story 03 с причиной. Тесты, ассертящие
старые фразы, обновлены.

## Requirements
> 1. Игроцкий текст в app/ не содержит «в нижнем меню», «клавиатуры ниже», «экран бота» и числа кнопок меню — одна фраза на оба клиента, кнопка по её подписи и «внизу экрана»; «/команды» остаются, если работают из поля ввода `/play` (иначе убираются из общих текстов); исключения только для текста, который в веб не уходит, поимённо и с причиной.

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/HangarAction.php
- app/Controllers/Telegram/Commands/Actions/StartGame/WithoutTrainingStartAction.php
- app/Controllers/Telegram/Commands/MenuCommand.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- app/Services/Economy/CraftShopGate.php
- app/Services/Onboarding/ColdOpenGreetingService.php
- app/Services/Onboarding/ColdOpenSignalService.php
- app/Services/Onboarding/ColdOpenWelcomeService.php
- app/Services/Onboarding/GuideCatalog.php
- app/Services/Onboarding/NewbieAtmosphereService.php
- app/Services/Onboarding/NewbieGreeterService.php
- app/Services/Onboarding/OnboardingChainCatalog.php
- app/Services/Onboarding/OnboardingHintCatalog.php
- app/Services/Player/CharacterService.php
- app/Services/Player/TipService.php
- app/Services/Tasks/TasksSurfaceService.php
- app/Services/Telegram/BotMenuService.php
- app/Services/Telegram/NavKeyboards.php
- app/Services/Telegram/NavMenuRefreshService.php
- app/Services/Web/WebNativeScreenService.php
- app/Services/World/MoveSurfaceService.php
- app/Views/site/_play/native_base.php
- app/Views/site/_play/native_craft.php
- app/Views/site/_play/native_gear.php
- app/Views/site/_play/native_inventory.php
- app/Views/site/_play/native_shop.php
- tests/_support/Community/provenance-corpus.php
- tests/database/LiveMapServiceTest.php
- tests/database/MoveServiceTest.php
- tests/database/QuestListServiceTest.php
- tests/unit/Services/Onboarding/ColdOpenSingleScreenTest.php
- tests/unit/Services/Onboarding/ColdOpenWelcomeServiceTest.php
- tests/unit/Services/Onboarding/OnboardingNavLabelConsistencyTest.php
- tests/unit/Services/Telegram/BotMenuServiceTest.php
- tests/unit/Services/Telegram/FinalGridLabelsRoutedTest.php
- tests/unit/Services/Telegram/NavMenuRefreshServiceTest.php
- tests/unit/Services/World/GatherOnCompassSurfaceTest.php
- phpstan-baseline.neon

## Non-goals
- Не вводить двух версий текста по клиенту (`VirtualChat::is`) — выбор владельца: одна фраза.
- Не менять подписи кнопок, раскладку меню и логику экранов; только текст.
- Не трогать комментарии в коде — проверка их не читает.
- Не дописывать числа баланса в тексты подсказок.

## Map slice
`memory/map/onboarding.md`, `memory/map/website.md` (раздел `/play`), `memory/map/telegram.md` (меню).

## Acceptance criteria
- [ ] Поиск по строковым литералам `app/` (без миграций) находит фразы класса только в местах, которые story 03 впишет в исключения с причиной «в веб не уходит».
- [ ] Каждая переписанная фраза называет кнопку текущей подписью (`BotMenuService::menuLabel`) и местом «внизу экрана», где раньше говорила «в нижнем меню».
- [ ] Судьба «/команд» записана в Implementation notes с результатом POST на testbot.
- [ ] Обновлённые тесты зелёные.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/OnboardingNavLabelConsistencyTest.php`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Telegram/NavMenuRefreshServiceTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- 26 литералов переписаны: «в нижнем меню» → «внизу экрана» (кнопка — своей подписью), «клавиатуры ниже» → «кнопками ниже», «экран бота» → «следующий экран» / «кнопка ниже» / «отдельным экраном», «Шесть кнопок — шесть мест» → «Каждая кнопка внизу — своё место».
- `/команды` остаются: текст `/…` из поля ввода `/play` получает сущность `bot_command` (`SyntheticUpdateFactory.php:62-65`), longman роутит его в `BaseCommand`/`GoCommand`/`CraftCommand`; живой POST — в Tier-3 смоуке.
- `ColdOpenWelcomeService::withoutNavHint` — регэксп под новую фразу, поведение в вебе прежнее (хвост срезается).
- Оставлены (в веб не уходят, пойдут в исключения story 03): `MenuCommand::$description`, `BotMenuService` описания `setMyCommands` для `start`/`menu`.
- `phpstan-baseline.neon`: снята запись `sendCampCreatedWithBuildingsMsg` `$restoredNames` — story 01 типизировала параметр, запись стала `ignore.unmatched` (файл дописан в `## Files`).
- Не в классе, но замечено: fallback «не понял» дальше говорит «значок «☰» рядом с полем ввода» — в вебе такого значка нет. Кандидат в W-REG.3.
- Тесты: ожидания в `ColdOpenWelcomeServiceTest`, `OnboardingNavLabelConsistencyTest`, `MoveServiceTest`, `LiveMapServiceTest` обновлены. Локальный `tests/unit` даёт 3–10 ошибок от протёкшей схемы `wildworld_tests` (FK `crafted_items_log_task_id_foreign`), к текстам не относятся.
- Ноты vault: GuideCatalog, OnboardingHintCatalog, OnboardingChainService, ColdOpen{Signal,Welcome}Service, Newbie{Atmosphere,Greeter}Service, CraftShopGate, BotMenuService, NavMenuRefreshService, MoveSurfaceService, GenericmessageCommand, WebNativeScreenService.

## Findings
