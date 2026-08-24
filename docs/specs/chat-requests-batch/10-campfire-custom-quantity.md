---
story: chat-requests-batch-10
spec: chat-requests-batch
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [chat-requests-batch-04]
---

# «Своё число» на костре

## Goal
На экране количества у костра, рядом со ступенями, есть кнопка «📝 Своё число»: игрок
вводит количество вручную, и оно уходит в тот же запуск готовки. Без неё решение
владельца («ступени как у обычного крафта **плюс своё число**») выполнено наполовину.

## Requirements
> [18.08.2026] Анжела: «Так надо просто ввести возможность при крафте на костре сразу выбирать количество. Желаете скрафтить тушняк? Выберите количество: 1шт; 5шт; 50шт и т.д.»

## Files
- app/Controllers/Telegram/Commands/Actions/Craft/Cooking/CampfireCookingSelect.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- tests/unit/Craft/CampfireCustomQuantityTest.php

## Notes
Разведка 24.08 — механизм в проекте уже отработан, повторять его архитектуру, а не
изобретать свою:

- Кнопка-образец: `SellResourceAction.php:260` — `'📝 Своё число'`, callback
  `sellResource_{id}_custom`.
- Промпт: `SellResourceAction::promptCustomQuantity()` (`:97-115`) — `force_reply: true`,
  в текст промпта кладётся уникальный маркер (`SELL:{id}`, строка `:111`).
- Перехват: `GenericmessageCommand::execute()` (`:37-52`) читает `getReplyToMessage()`,
  матчит маркер в тексте промпта и роутит на приватный хендлер; парсинг числа —
  `preg_replace('/[^\d]/', '', $rawReply)` (`:302`).
- Обобщённого «спроси число» нет: каждый маркер написан руками. Новый маркер для готовки
  добавляется тем же способом — своя метка, свой `if`, свой приватный хендлер.

У обычного крафта «своего числа» НЕТ ни на одном экране — здесь оно появляется впервые в
крафт-ветке, поэтому образец берётся у продажи.

Кнопки количества у крафта показываются только для тех ступеней, что игрок реально может
себе позволить (`$q <= $maxCraftableItems`, `LumberjackAxeCraft1Action.php:183`). Введённое
руками число обязано проходить ту же проверку: не хватает ресурсов — честно сказать,
сколько выходит, а не запускать втихую меньшее и не падать.

## Non-goals
- Не добавлять «своё число» на экраны обычного крафта — там его нет и заявки на него нет.
- Не менять рецепты, время и стоимость готовки, не трогать `GenericCraftActionStart`.
- Не снимать 🔒-статус готовки: гейт ADR-167 «🔒 не стартует поверх 🔒» обязан
  отрабатывать и на введённом руками числе — проверить это тестом явно.
- Не переписывать диспетчер маркеров на обобщённый механизм: это отдельное решение,
  не попутная правка.

## Acceptance criteria
- [ ] Создан и зелёный `tests/unit/Craft/CampfireCustomQuantityTest.php` — падает на доправочном поведении.
- [ ] Введённый мусор («три», пустая строка, `0`, отрицательное) не запускает готовку и получает
      понятный ответ, а не молчание и не ошибку.
- [ ] Кнопка не мёртвая: маркер, который шлёт костёр, действительно матчится в
      `GenericmessageCommand` — покрыть тестом обе стороны, а не только отправку.
- [ ] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php`

## Findings

**Кнопка + промпт (`CampfireCookingSelect`):** на шаге количества добавлена
кнопка «📝 Своё число» (`cook_qtyCustom_<Key>` / `cookPreserves_qtyCustom_<Key>` —
роутится тем же exact-роутом `cook`/`cookPreserves`, т.к. `CallbackqueryCommand`
резолвит по ПЕРВОМУ сегменту, `CallbackRoutes.php` не тронут). Клик шлёт
ForceReply-промпт с маркером `COOK:<RecipeKey>` — образец `SellResourceAction::promptCustomQuantity()`
(`:97-115`) буквально скопирован: `force_reply:true, selective:true`, маркер без
скобок (баг 2026-05-11 с Markdown, тот же урок учтён).

**Перехват (`GenericmessageCommand::execute()`):** новая ветка `preg_match('/COOK:([A-Za-z0-9]+)/', ...)`
рядом с существующими SELL/BUY/NAME/RELOCATION (`:37-52` из разведки) →
`handleCookQuantityReply()`. Валидация: `isKnownCookingRecipe()` (неизвестное
блюдо → «больше не готовят», без исключения), затем строго `^\d+$` на
`trim($rawReply)` — НЕ `preg_replace('/[^\d]/', '', ...)`, как у SELL: тот
вариант молча превращал бы «-5» в «5», а story явно требует честный отказ на
отрицательное. Верхний потолок `COOK_QTY_MAX=100000` — защита от переполнения
при касте огромной цифровой строки в `(int)`, НЕ баланс (не GameSettings-ключ,
как `CAPTION_LIMIT`); реальный практический потолок всё равно ставит нехватка
сырья через `CraftShortageService`.

**Не задублирован обобщённый механизм:** прошедшее валидацию число уходит
СИНТЕТИЧЕСКИМ `CallbackQuery` (`data: genericCraft_<Key>_<qty>`, from/chat —
из входящего сообщения) прямо в `GenericCraftActionStart::handle()` — тем же
путём, что и кнопка. Списание ресурсов/золота, 🔒-гейт ADR-167
(`exclusiveConflictText()`), очередь, `CraftShortageService` — ни строчки не
скопировано, всё это уже там и остаётся ЕДИНЫМ для кнопки и «своего числа».

**Ступени количества гейтятся ресурсами (доп. находка по чек-листу):**
на шаге количества обычные ступени `[1,5,10,25,50,100]` теперь фильтруются
той же формулой, что `LumberjackAxeCraft1Action::calculateMaxCraftableItems()`
(`floor(есть/нужно)`, минимум по ресурсу) — до этой story костёр показывал ВСЕ
6 кнопок независимо от наличия сырья, а обычный крафт — только доступные;
это и было расхождение «два соседних экрана ведут себя по-разному», которое
просила избежать story 04. Почищено: `CampfireCookingSelect::affordableSteps()`/`maxCraftableItems()`
(чистые функции, формула продублирована по тому же принципу, что и в ~27
карточках крафта — общего предка у формулы в проекте нет, см. докблок
`CraftCardHelper`), гейтит и обычные кнопки, и (природно, через
`GenericCraftActionStart`/`CraftShortageService`) «своё число». Ноль доступных
ступеней → тот же `CraftCardHelper::fallbackButton()`, что у обычного крафта
(«🛒 Чего не хватает?»), а не пустой список кнопок.

**Тест-стратегия (`tests/unit/Craft/CampfireCustomQuantityTest.php`, 7 тестов):**
- (A) `testCampfireSendsForceReplyPromptWithRoutableMarker` — реальный `handle()`
  на `cook_qtyCustom_MushroomSoup`, проверяет caption содержит `COOK:MushroomSoup`
  и `force_reply`.
- (B) `testGarbageReplyDoesNotStartCraftAndAnswersUnderstandably` — СКВОЗНОЙ прогон:
  текст промпта из (A) кормится в РЕАЛЬНЫЙ `GenericmessageCommand::execute()`
  (не скан исходника, не мок) как `reply_to_message.text`, с шестью вариантами
  мусора («три», пусто, `0`, `-5`, «5 шт», «3.5») — каждый получает `❌`-ответ,
  ни один не создаёт `character_tasks` (assert по `countAllResults()` до/после).
- `testUnknownRecipeMarkerIsRejectedGracefully` — маркер `COOK:NotARealDish` →
  «больше не готовят», без исключения.
- `testValidCustomQuantityRespectsExclusiveLockGate` — сеет ЧУЖУЮ 🔒-задачу
  (`gatherTestBlocking`, `parallel_execution_allowed=0`, `status=in_work`) тому
  же персонажу, шлёт валидное «5» → ответ содержит `🔒` и имя блокирующей
  задачи, а `character_tasks` НЕ прирастает (тот же ADR-167 гейт, что и у
  кнопки, отработал и на ручном вводе).
- `testAffordableStepsMatchRegularCraftGatingFormula` /
  `testQuantityButtonsAcceptPreFilteredStepsFromAffordableSteps` — гейтинг
  ступеней (обильно → все 6; впритык → [1,5]; ноль → []).
- `testUnknownDishIsNotAKnownCookingRecipe` — pure-function, без DB.

**Red-check:** `git stash` диффа `CampfireCookingSelect.php` +
`GenericmessageCommand.php` (тест НЕ стешился) → прогон дал 6 errors
(`Call to undefined method isKnownCookingRecipe/affordableSteps`, `fopen` там,
где до фикса неизвестное блюдо падало в photo-путь `handleDishList`) + 1
failure (`COOK:` не матчился, ответ ушёл в generic fallback «Не понял
команду») → `git stash pop` → снова зелёный (7/7, 39 assertions). Падало
именно на отсутствии фикса, не на постороннем.

**Ограничение среды (как в story 04):** сценарии, ведущие к УСПЕШНОМУ старту
крафта (photo-экран `GenericCraftActionStart`'а), в этом наборе НЕ прогонялись
end-to-end — `Request::encodeFile(base_url(...))` реально уходит в сеть под
`app.baseURL=http://example.com/` из `phpunit.xml.dist`. Все прогнанные тесты
намеренно попадают в ТЕКСТОВЫЕ ветки (`sendError`/`CraftShortageService`
недостачи/промпт) — success-путь не проверялся PHPUnit'ом, для него нужен
Tier-3 smoke (правило `telegram-ux.md`), который в рамках этой сессии не
запускался.

**Устойчивость теста к общей БД:** в процессе работы полный прогон один раз
показал `Table 'tasks' doesn't exist` — соседний `VehicleCraftWiringTest`
временно DROP+CREATE `tasks` под свою изолированную схему и не гарантирует
восстановление к моменту запуска других тестов. Добавил `tableExists()`-гард
и на `tasks`/`character_tasks` в СВОЁМ тесте (создаю только если отсутствуют,
удаляю в tearDown только созданное) — после этого `CampfireCustomQuantityTest`
устойчив к порядку. Похожая же коллизия (`characters already exists` между
`MarchingTransportTest`/`VehicleCraftWiringTest`) осталась в полном прогоне —
это ЧУЖИЕ файлы (`tests/unit/Transport/*`), не по этой story.

**Полный `vendor/bin/phpunit`:** зелёный (3378 тестов, 0 ошибок/падений,
дважды подряд) — за вычетом описанной выше устойчивости к порядку, ничего
дополнительно чинить не пришлось.
