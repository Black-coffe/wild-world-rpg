<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-27

# Scout report: Крафт, ремонт, экономика предметов

## Purpose
Рецепты и их выполнение, длительность, нехватка сырья, модификаторы, износ и просрочка
расходников, дерево крафта и калькулятор.

## Entry points
- `app/Services/Craft/CraftOrderService.php` — **ядро старта** для бота и веба (W2.N3, ADR-190):
  `preview()`, `start()`, `gateError()`; коды исхода — константы. `GenericCraftActionStart` — рендерер.
- `app/Services/Craft/CraftQueueService.php` — очередь: `rows()`/`forCharacter()` (ETA), `cancel()`,
  `promoteNext()`. Рендереры: `ShowCraftQueueAction`, `CancelQueuedCraftAction`, `GenericCraftCompletionHandler`.
- `app/Config/CraftCatalog.php` — дерево верстак→категория→рецепты для `/play?view=craft` (не бот).
- `app/Services/Player/CraftService.php` — оркестрация крафта; `Player/Craft/` — подпроцессы.
- `app/Services/Craft/CraftCardHelper.php` — единственное место, считающее доступность сырья
  (пул рюкзак+склад, ADR-171) и строящее ряды кнопок количества (`STEPS = [1,5,10,25,50,100]`,
  `quantityRows()`) для карточек крафта; `fallbackButton()` — выход из тупика «нельзя ни одной».
- `app/Services/Craft/CraftDurationService.php` + `CraftDurationBreakdown.php` — время.
- `app/Services/Craft/CraftShortageService.php` — чего не хватает.
- `app/Services/Craft/ItemModifierService.php`, `ConsumableExpiryService.php` (просрочка → heal ×50%).
- `app/Services/CraftTree/CraftTreeService.php`, `app/Services/CraftCalculator/CraftCalculatorService.php`.
- `app/Services/Crafting/RequiredResourcesParser.php` — разбор строки требований.
- `app/Services/Player/CraftInsuranceService.php`, `NpcRepairService.php`.
- Модель `CraftedItemsModel`; TaskHandlers — `app/TaskHandlers/Craft/`.

## Key types / contracts
Канон верстаков: **два верстака, три уровня**. Числа в текстах берутся из кода, не из головы.
Предмет — четыре источника правды сразу: имя, рецепт, описание и арт; они обязаны совпадать.

## Dependencies
inbound: `CraftCommand`, крафт-actions, `Worker`/TaskHandlers завершения, веб `WebNativeScreenService`.
outbound: ресурсы персонажа, `GameSettings`, `Images`.

## Gotchas
- Крафт не должен печатать золото: `цена продажи × 1.10 ≤ золото + стоимость сырья`.
- Цена на экране обязана приходить из сервиса сделки, а не из сырого поля БД.
- Списание — `deductCraftedItem`, не `update()` с raw-set.
- Любое число баланса (стоимость, время, вероятность) — в `GameSettings` с rationale, не в коде.
- **(exploit-fix-02)** `BuyCraftConfirmAction`/`SellCraftConfirmAction`: гвард `qty > 0` на самом
  экране, сразу после каста, до любого чтения/записи; своя причина отказа.
- **(ADR-181, с W2.N3 — в `CraftQueueService::cancel()`)** отмена очереди — условный
  `DELETE … WHERE status='queued'` **первым** шагом транзакции, возвраты только при снятии; продвижение —
  условный `UPDATE … WHERE status='queued'`. Возврат — туда, откуда списал старт
  (`task_settings.consumed{resources{backpack,storage},crafted_items,gold}`); без `consumed` — в рюкзак.
- **(W2.N3-01) Лимиты очереди — GameSettings** `craft.queue.max_per_recipe` (10) / `craft.queue.max_slots`
  (3), не `Config\GameBalance` (поля удалены). Перепроверяются под `SELECT … FOR UPDATE` строки персонажа.
- Рыбные рецепты — один список `CraftOrderService::FISH_RECIPES` (w2-n4-base-03); `CampfireCookingSelect::FISH_RECIPES` — алиас.
- Ядро в `action_log` не пишет: отказ несёт `log{reason,extra}`, пишет рендерер (`CRAFT_<Key>`).
- **(2026-09, ADR-181) Списание ресурсов** — `CharacterResourceModel::decreaseResources()` удалено
  (читало-считало-писало, при нехватке удаляло строку и рапортовало успех); заменено
  `decrementIfAtLeast()` через `ConditionalWriteService` во всех семи бывших вызывающих.
- **(2026-09, craft-quantity-parity) `craft_again_callback` НЕ рисует кнопку «Крафтить ещё».**
  Кнопку строит `GenericCraftCompletionHandler` из ключа рецепта. Поле рецепта в
  `Config\CraftRecipes` служит источником `RecipeKey` для
  `CraftShortageService::shortfallRecipeKey()` (регулярка `^genericCraft_(.+)_\d+$`) — смена его
  формы (`genericCraft_<Key>_<qty>`) молча убивает кнопку докупки при нехватке для этого рецепта.
  Гейт по ВСЕМ рецептам конфига — `CraftRecipesTest::testCraftAgainCallbackResolvesForEveryRecipeConfigured`.
  Контракт зафиксирован: `mmorpg-vault/decisions/ADR-182-Craft-again-callback-is-the-recipe-key-contract.md`.
- **(2026-09, craft-quantity-parity) Доступность сырья на карточке крафта — только через
  `CraftCardHelper::available()`** (пул рюкзак+склад), не через `CharacterResourceModel` напрямую
  — иначе экран расходится с гейтом старта `CraftOrderService::checkResources()`. T3-утилиты
  (`UtilityRecipePreviewT3Action`) получили паритет с обычными карточками — ряд кнопок количества
  вместо зашитой единственной «1 шт.».

- **(2026-09-23, bugs-info-0923-02) `crafted_items.type='food'` нигде не применяется**:
  Аптечка и Провизия читают только `drug`. `CraftedResourcesAction` помечает такие строки
  «не применяется, выводится из обращения» и печатает путь «💊 Аптечка → 🍲 Провизия».
  Вывод из обращения — ADR-185, ветка `vulyk/craft-shelf-coverage` не влита и правит тот же файл.

## Vault
`tech-writing/services/{CraftOrderService,CraftQueueService}.md`, `tech-writing/config/CraftCatalog.md`,
`tech-writing/handlers/craft/` · `mmorpg-vault/apps/player/index.md`
