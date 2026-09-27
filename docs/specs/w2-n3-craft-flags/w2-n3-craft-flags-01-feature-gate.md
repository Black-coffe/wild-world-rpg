---
story: w2-n3-craft-flags-01
spec: w2-n3-craft-flags
status: done
returned: DONE
tier: 1
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Гейт флагов фич рецептов в ядре крафта; CI на PHP 8.3

## Goal
`CraftOrderService` знает, какие рецепты выключены флагом фичи (рыбные блюда по
`cooking.fish_dishes.enabled`, DroneScout/Cargo/Repair/Combat по `DroneService`), и отказывает им
гейтом с новым кодом и текстом «Этот рецепт сейчас недоступен.» в `gateError()` (а значит в `start()`
и `preview()`), первым после «нет рецепта». Публичный метод ядра (например `recipeEnabled(string)`)
заменяет `WebNativeScreenService::recipeShown()` — список живёт в одном месте. Бот
(`GenericCraftActionStart`) рендерит отказ как любой гейт и пишет `logRejected`. `deploy.yml` гоняет
тесты на PHP 8.3.

## Requirements
> 1. Прямой callback `genericCraft_<Key>_<qty>` рецепта, выключенного флагом фичи (рыбные блюда `cooking.fish_dishes.enabled`, дроны по флагам `DroneService`), в боте — отказ без старта и списания, с понятным текстом и записью отказа, как у других гейтов; флаги проверяет ядро `CraftOrderService`, веб берёт ту же проверку, второй копии списка нет.
> 2. Видимые рецепты в боте ведут себя как раньше: тексты, кнопки и порядок гейтов не меняются.
> 3. CI (`deploy.yml`) гоняет тесты на PHP 8.3, как прод; набор зелёный.

## Files
- app/Services/Craft/CraftOrderService.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- tests/database/CraftFeatureGateTest.php
- .github/workflows/deploy.yml
- phpstan-baseline.neon

## Non-goals
- Не трогать сезонный гейт, остальные гейты и их порядок для видимых рецептов.
- Не менять каталог бота и кнопки; не менять composer.json.

## Map slice
`memory/map/craft.md`, `memory/map/website.md`.

## Acceptance criteria
- [ ] Тест: бот-старт (handler, как в снимках `CraftOrderServiceTest`) рыбного рецепта при выключенном флаге — текст «Этот рецепт сейчас недоступен.», нет строки `character_tasks`, сырьё/золото не списаны, `action_log` с `CRAFT_<Key>`; тест краснеет без гейта.
- [ ] Тест: дрон при выключенном флаге — тот же отказ; при включённом — старт как раньше.
- [ ] `WebNativeScreenService` не содержит своего списка рыбных рецептов/дронов; `WebCraftStartTailsTest` и `CraftOrderServiceTest` зелёные без правок.
- [ ] `deploy.yml`: `php-version: '8.3'`; YAML валиден.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `CraftOrderService`: новый код `FEATURE_OFF = 'recipe_feature_disabled'`, публичный `recipeEnabled(string)` (FISH_RECIPES + флаг ухи через свой `GameSettingsService`, дроны через `DroneService($this->gameSettings)`); гейт первым в `gateError()` → действует в `start()`, `preview()` и докупке.
- `WebNativeScreenService`: удалены `FISH_FLAG`/`FISH_RECIPES`/`recipeShown()` и ставшие лишними `use`; `visibleRecipes()` фильтрует `$this->orders->recipeEnabled()`.
- `GenericCraftActionStart` не тронут: общий путь отказа уже шлёт `message` и пишет `logRejected` по `log`.
- `deploy.yml`: `php-version: '8.3'` (YAML проверен `yaml.safe_load`). `phpstan-baseline.neon` не понадобился.
- Тест `CraftFeatureGateTest` (бот-хэндлер, отдельный процесс, media off): уха и дрон-разведчик off → отказ/лог/без записи, on → старт; превью ядра. С выключенным гейтом краснеют все 3 теста (проверено).
- Сюрприз: ENUM `action_status` исходной миграции не знает `REJECTED` (в снимках `CraftOrderServiceTest` тоже `""`) — тест сверяет `action_name` и причину, не статус.

## Findings
