# F2 continuation — что осталось закрыть

**Состояние на 2026-05-04 после v0.4.0 ship.**

## ✅ Закрыто в v0.3.0 / v0.4.0

| F2.# | Что | Где |
|---|---|---|
| F2.1 | Arsenal cutover (генерик handler через recipe-config) | `GenericBuildingAction` + `Buildings.php` |
| F2.2 | Bandage cutover (генерик craft completion) | `GenericCraftCompletionHandler` + `CraftRecipes.php` |
| F2.4 | TaskDispatcher (DI container, foundation) | `app/Services/Tasks/TaskDispatcher.php` (готов, без caller'ов) |
| F2.5 | CallbackRouter — первый wildcard wire-in (`move_dir_*`) | `CallbackqueryCommand:65-72` |
| F2.6 | CharacterRepository — first wire-in (RewardService gold) | `app/Repositories/`, `RewardService::grantRewards` |
| F2.9 | TaskHandlerInterface + BaseTaskHandler (foundation) | `app/TaskHandlers/Contracts/`, `BaseTaskHandler.php` |
| F2.10 | GameBalance config + first wire-in (TaxCollectionHandler) | `app/Config/GameBalance.php`, `TaxCollectionHandler::handle` |
| F2.11 | unit-тесты DamageService + EffectService + CallbackRouter | `tests/unit/Services/PVE/`, `tests/unit/Services/Telegram/` |
| F2.11+ | unit-тесты Buildings + CraftRecipes + GameBalance | `tests/unit/Config/` |
| (infra) | Integration test infra (MySQL test DB + CI service) | `phpunit.xml.dist`, `.github/workflows/deploy.yml` |
| (infra) | CharacterRepository integration tests | `tests/database/CharacterRepositoryAdjustGoldTest.php` |

**Тестов:** 66+ unit, 8 integration. Всего 80+, ~120+ assertions.

---

## ⏳ Осталось в F2

### F2.3 — AttackPlayerAction decomposition

**Размер:** 1116 LOC, 13 моделей в конструкторе. **Самый большой god-класс.**

**План декомпозиции (4 сервиса, ADR-009 vault):**

1. **PvpDamageCalculator** — чистая функция, считает урон с учётом
   формулы из `DamageService`, биом-модификаторы, lucky strike, one-shot.
   Зависит только от `config('GameBalance')` + `EffectService`.
   Покрывается 15-25 unit-тестами без БД.

2. **PvpRoundOrchestrator** — крутит раунды до победителя или 150 раундов.
   Использует `PvpDamageCalculator`. Ведёт лог раундов в массив.
   Возвращает {winner_id, loser_id, rounds, log}. Тестируется через
   моки калькулятора — 10-15 тестов.

3. **PvpRewardOrchestrator** — после боя: вызывает `RewardService::grantRewards`
   для победителя (уже использует F2.6 `CharacterRepository`),
   `DeathService::handleDefeat` для проигравшего.

4. **PvpEncounterController** (то что останется в `AttackPlayerAction`) —
   orchestrator: dispatch callback, validate input, lock участников
   через `CharacterRepository::lockAndReadEconomyState`, вызвать
   `RoundOrchestrator`, вызвать `RewardOrchestrator`, отправить
   Telegram-уведомления через `BaseAction::safeSendPhoto`.
   ~150-200 LOC вместо 1116.

**Acceptance criteria:**
- 1:1 поведение с legacy на 5 fixture сценариях:
  - lvl 50 vs lvl 50 (равный бой)
  - lvl 100 vs lvl 30 (победа фаворита)
  - lvl 30 vs lvl 100 (поражение, потеря экспа)
  - агрессия в безопасном биоме (penalty)
  - lucky strike + one-shot edge case
- Все 21 константы PvP — через `config('GameBalance')` (F2.10 wire-in)
- Юнит-тесты на каждую из 4 сервисов
- Integration test через `CIDatabaseTestCase`: stub-character'ы,
  PvpEncounterController отрабатывает, DB state правильный

**Risk:** PvP — hot path на проде. Регресс ломает геймплей. Стратегия:
- Сначала ВСЕ ТЕСТЫ зелёные с legacy (документируют текущее поведение)
- Потом extract один сервис, тесты остаются зелёными
- Повторить 4 раза
- Финал — `git rm` legacy 1116 LOC, тег и ship

**Ожидаемое сокращение:** 1116 → ~300 LOC (4 сервиса + orchestrator), плюс тесты.

---

### F2.7 — GatherTaskHandler decomposition

**Размер:** 640 LOC.

**Известные проблемы:**
- N+1 запрос к БД в гард-цикле (~3-6k лишних SQL/мин на 635 чарах) —
  см. `lore/refactor/Performance-DB.md`.
- Логика добычи перепутана с биом-модификаторами и tool-износом.

**План декомпозиции (3 сервиса):**

1. **GatherFormulaService** — формула:
   `foundResources = resources × time_factor × difficulty_factor × random_multiplier`
   (см. `lore/admin/Tips-categories.md`). Чистая функция, ~10 unit-тестов.

2. **ToolDurabilityService** — учёт износа инструмента, F0.4 уже добавил
   индексы. Здесь decoupling от Gather, чтобы независимо тестировать.

3. **GatherCompletionOrchestrator** — что остаётся в `GatherTaskHandler`:
   читает task, вызывает GatherFormulaService, вызывает ToolDurability,
   batch-update `character_resources` (исправляет N+1), отправляет
   уведомление через `BaseTaskHandler::safeSendPhoto`.

**Risk:** gather — самое частое действие в игре. Ошибка = массовая
потеря/удвоение ресурсов. Стратегия — DB snapshot перед миграцией +
сравнение after/before на тестовых runs preprod через DB-shrink-time
трюк (см. `runbooks/deploy.md` Tip 4).

**Ожидаемое:** 640 → ~250 LOC + N+1 fix.

---

### F2.8 — DeathService decomposition

**Размер:** 415 LOC (`Services/Player/DeathService.php`) + 242 LOC
(`TaskHandlers/DeathRouletteHandler.php`) = 657 total.

**План:**

1. **DeathPenaltyCalculator** — вычисляет сколько XP/статов теряем,
   используя `config('GameBalance')->deathExpLossPercent` /
   `deathStatLossPercent`. Чистая функция.

2. **DeathInventoryProcessor** — рулетка потери предметов из инвентаря.
   Конфиг через `GameBalance`.

3. **DeathTeleporter** — переносит персонажа на base или в random безопасный
   биом. Использует `CharacterRepository::updateStats` для координат.

4. **DeathOrchestrator** (что остаётся в `DeathService`) — координирует
   три выше, использует `CharacterRepository::adjustExperience` для
   атомарного списания (F2.6 wire-in).

**Risk:** death — destructive flow. Нужно очень осторожно. Но малый
размер класса делает декомп tractable.

**Acceptance:**
- 1:1 на 4 сценариях: смерть на базе, в дикой местности, с маяком,
  в горячей зоне (вулкан)
- Юнит-тесты на каждый сервис
- Integration test через DB-fixture character + simulated death

**Ожидаемое:** 657 → ~280 LOC.

---

### F2.12 — View partials

**Размер:** не определён. Применяется к admin views в `app/Views/`.

**Цель:** вынести шапки/футеры/sidebar в partials через CI4 view layouts.
Сейчас admin/*.php имеют скопированный header/footer/menu.

**Risk:** низкий — UI-only, не трогает game logic.

**Effort:** ~2-4 часа. Hold для отдельной session, не критично к F2 closure.

---

## 🎯 Рекомендованный порядок работы (по risk × value)

1. **F2.8 DeathService** — самый малый (415 LOC), defensible (death уже
   с гардами от F0). Хороший starter для god-class decomp pattern.
2. **F2.7 GatherTaskHandler** — фиксит N+1 заодно (real perf win).
3. **F2.3 AttackPlayerAction** — самое тяжёлое, оставить на конец когда
   pattern decomposition отработан на F2.7/F2.8.
4. **F2.12 View partials** — параллельно или после, любая session.

После всех 4 — F2 closed. F1.3 (`declare(strict_types=1)`) и F1.4
(Models → Entity) — тогда можно делать массово (когда есть unit-тесты
для проверки поведения).

---

## 📋 Что НЕ делаем сейчас (намеренно)

- Удаление `Worker::processUnlinkedActions` — strangler ещё неделя
  наблюдений (см. `wiki/hot.md`).
- Бамп прочих CI4-пакетов — не блокируют F2.
- Migration 21 здания / 41 рецепта — это копи-paste по pattern F2.1/F2.2,
  делается партиями по 5 при наличии времени, не блокирует ничего.
