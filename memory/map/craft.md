<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-06

# Scout report: Крафт, ремонт, экономика предметов

## Purpose
Рецепты и их выполнение, длительность, нехватка сырья, модификаторы, износ и просрочка
расходников, дерево крафта и калькулятор.

## Entry points
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
inbound: `CraftCommand`, крафт-actions, `Worker`/TaskHandlers завершения.
outbound: ресурсы персонажа, `GameSettings`, `Images`.

## Gotchas
- Крафт не должен печатать золото: `цена продажи × 1.10 ≤ золото + стоимость сырья`.
- Цена на экране обязана приходить из сервиса сделки, а не из сырого поля БД.
- Списание — `deductCraftedItem`, не `update()` с raw-set.
- Любое число баланса (стоимость, время, вероятность) — в `GameSettings` с rationale, не в коде.
- **ЗАКРЫТО (2026-09, exploit-fix-02, H1).** `EA-economy-01`/`EA-economy-02` — было: ни одной
  проверки `qty > 0` на пути `BuyCraftConfirmAction`/`SellCraftConfirmAction`, отрицательное `qty`
  печатало золото и раздувало сток торговца, `qty=0` писал фантомную строку в `transactions`.
  Гвард `qty > 0` теперь стоит на самом экране (не на побочном эффекте `VendorDailyLimitService`),
  сразу после каста `(int) $quantity`, до любого чтения/записи. Отдельная честная причина отказа —
  не переиспользует текст `VendorDailyLimitService::refusalText()` («монеты торговца кончились»).
- **(2026-09, ADR-181) Отмена очереди крафта** (`CancelQueuedCraftAction`) — снятие строки
  `character_tasks` теперь условный `DELETE ... WHERE status='queued'` **первым** шагом транзакции,
  три возврата (ресурсы/крафт/золото) — только при подтверждённом снятии. Раньше строка удалялась
  безусловным `Model::delete()` последней. См. `mmorpg-vault/tech-writing/handlers/craft/CancelQueuedCraftAction.md`.
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
  — иначе экран расходится с гейтом старта `GenericCraftActionStart::checkResources()`. T3-утилиты
  (`UtilityRecipePreviewT3Action`) получили паритет с обычными карточками — ряд кнопок количества
  вместо зашитой единственной «1 шт.».

## Vault
`mmorpg-vault/tech-writing/craft/` · `mmorpg-vault/apps/player/index.md`
