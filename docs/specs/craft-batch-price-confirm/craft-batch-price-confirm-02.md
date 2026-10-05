---
story: craft-batch-price-confirm-02
spec: craft-batch-price-confirm
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [craft-batch-price-confirm-01]
---

# Подтверждение крупной партии крафта

## Goal
Нажатие «Крафт Nшт» при партии выше порога (по штукам или по золоту) ничего не списывает и показывает экран с
итогом партии; «✅ Запустить» стартует ровно её, «↩️ Изменить кол-во» возвращает к рецепту. Пороги — в
GameSettings, к механике — совет дня.

## Requirements
> Партии от 25 штук и дороже определённой суммы — через шаг подтверждения с итогом по золоту, ресурсам и времени, порог — в GameSettings.
> Нужен Tier-3 смоук на testbot.
> 3. Нажатие кнопки количества при партии ≥ `craft.confirm.min_qty` (дефолт 25) ИЛИ итоговом золоте ≥ `craft.confirm.min_gold` (дефолт 50 000) ничего не списывает и показывает экран подтверждения: имя рецепта, количество, итог золота, ресурсов, компонентов и времени партии; кнопки «✅ Запустить» и «↩️ Изменить кол-во» в одном ряду.
> 4. «✅ Запустить» стартует крафт ровно на подтверждённое количество без повторного экрана; «↩️ Изменить кол-во» ведёт на `info_callback` рецепта, а без него — в меню крафта. Партия ниже обоих порогов стартует сразу, как сейчас.
> 5. Пороги `craft.confirm.min_qty` и `craft.confirm.min_gold` — ключи GameSettings с rationale / effect / above / below, soft- и hard-границами; значение 0 выключает соответствующее условие. Идемпотентная seed-миграция, существующее значение не перезаписывается.
> 6. Все тексты экранов markdown-safe (парные `*`/`_`, имена рецептов не ломают разметку), читаются без картинки, на экране подтверждения нет одиночных кнопок в ряду.
> 7. Tips: идемпотентная seed-миграция `*SeedCraftBatchPriceTip.php` (ключ `title_en`, `tip_type='крафт'`, тон Роби, без чисел баланса) — про то, что время партии = время штуки × количество, и что крупную партию игра попросит подтвердить. Guide: не добавляем — правка экрана, не новая механика.
> 8. Tier-3 смоук на preprod-testbot (тест-чар `telegram_user_id=25`): партия ниже порога стартует сразу со строкой «Списано»; партия выше порога показывает подтверждение, «↩️» возвращает к рецепту без списания, «✅» стартует и списывает ровно итог экрана.

## Files
- app/Services/Craft/CraftBatchConfirmPolicy.php
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- app/Database/Migrations/2026-12-16-100000_SeedCraftConfirmThresholdSettings.php
- app/Database/Migrations/2026-12-16-100010_SeedCraftBatchPriceTip.php
- tests/unit/Craft/CraftBatchConfirmPolicyTest.php
- tests/unit/Craft/CraftBatchConfirmCallbackTest.php

## Non-goals
- Не подписывать итоги на самих кнопках количества и не менять `CraftCardHelper::STEPS`.
- Не трогать веб `/play` и старые `StartCraft…2Action`.
- Не менять `craft_again_callback` рецептов и регулярку `shortfallRecipeKey()` (ADR-182).

## Map slice
`memory/map/craft.md` → Entry points (`CraftOrderService::preview()`), Gotchas (ADR-182, лимиты очереди в GameSettings).

## Acceptance criteria
- [ ] Политика: qty ≥ min_qty или gold ≥ min_gold → true; 0 выключает своё условие; оба 0 → никогда; тест на границах (24/25, 49 999/50 000) и на соседней форме (min_qty=0, золото выше порога).
- [ ] Разбор колбэка: `genericCraft_Key_50` → qty 50 без флага подтверждения; `genericCraft_Key_50_ok` → qty 50 с флагом; `genericCraft_Key` → qty 1; длина `genericCraft_SpringPrimroseInfusion_100_ok` ≤ 64 байт.
- [ ] Экран подтверждения строится из `CraftOrderService::preview()`; при отказе превью (нехватка, гейт) — тот же путь ошибки/нехватки, что сейчас, без экрана подтверждения.
- [ ] Обе миграции идемпотентны (повторный `up()` ничего не дублирует), `php -l` чистый.
- [ ] Tier-3 смоук по ask 8 выполнен Queen после деплоя на preprod; результат записан в Implementation notes.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
