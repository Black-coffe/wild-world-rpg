<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Крафт, ремонт, экономика предметов

## Purpose
Рецепты и их выполнение, длительность, нехватка сырья, модификаторы, износ и просрочка
расходников, дерево крафта и калькулятор.

## Entry points
- `app/Services/Player/CraftService.php` — оркестрация крафта; `Player/Craft/` — подпроцессы.
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
- Ловушка (exploit-audit, `docs/specs/exploit-audit/REPORT.md` #4, `EA-economy-01`):
  `BuyCraftConfirmAction` не имеет ни одной проверки `qty > 0` на всём пути от разбора callback
  до записи — отрицательное `qty` печатает золото и раздувает сток торговца (нужен модифицированный
  клиент, кнопки шлют только положительные значения). Тот же незащищённый вход, `qty=0`, пишет
  фантомную строку в `transactions` (`EA-economy-02`).

## Vault
`mmorpg-vault/tech-writing/craft/` · `mmorpg-vault/apps/player/index.md`
