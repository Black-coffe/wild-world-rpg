---
story: craft-batch-price-confirm-02
spec: craft-batch-price-confirm
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [craft-batch-price-confirm-01]
---

# Подтверждение крупной партии: правило в ядре, экран в боте

## Goal
Правило «крупная партия требует подтверждения» живёт в ядре `CraftOrderService::start()`: без флага
подтверждения партия выше порога (по штукам или по золоту) получает `CONFIRM_REQUIRED` с итогом и ничего не
списывает. Бот рендерит это экраном с итогом партии; «✅ Запустить» стартует ровно её, «↩️ Изменить кол-во» возвращает к рецепту. Пороги — в
GameSettings, к механике — совет дня.

## Requirements
> Партии от 25 штук и дороже определённой суммы — через шаг подтверждения с итогом по золоту, ресурсам и времени, порог — в GameSettings.
> Нужен Tier-3 смоук на testbot.
> 3. Нажатие кнопки количества при партии ≥ `craft.confirm.min_qty` (дефолт 25) И итоговом золоте ≥ `craft.confirm.min_gold` (дефолт 50 000) — «И» по поправке владельца 2026-10-05 ничего не списывает и показывает экран подтверждения: имя рецепта, количество, итог золота, ресурсов, компонентов и времени партии; кнопки «✅ Запустить» и «↩️ Изменить кол-во» в одном ряду.
> 4. «✅ Запустить» стартует крафт ровно на подтверждённое количество без повторного экрана; «↩️ Изменить кол-во» ведёт на `info_callback` рецепта, а без него — в меню крафта. Партия ниже обоих порогов стартует сразу, как сейчас.
> 5. Пороги `craft.confirm.min_qty` и `craft.confirm.min_gold` — ключи GameSettings с rationale / effect / above / below, soft- и hard-границами; значение 0 выключает соответствующее условие. Идемпотентная seed-миграция, существующее значение не перезаписывается.
> 6. Все тексты экранов markdown-safe (парные `*`/`_`, имена рецептов не ломают разметку), читаются без картинки, на экране подтверждения нет одиночных кнопок в ряду.
> 7. Tips: идемпотентная seed-миграция `*SeedCraftBatchPriceTip.php` (ключ `title_en`, `tip_type='крафт'`, тон Роби, без чисел баланса) — про то, что время партии = время штуки × количество, и что крупную партию игра попросит подтвердить. Guide: не добавляем — правка экрана, не новая механика.
> 9. Порог подтверждения проверяет ядро `CraftOrderService::start()` — единственная точка старта и для бота, и для `/play`: без явного флага подтверждения партия выше порога возвращает код `CONFIRM_REQUIRED` с итогом партии и ничего не списывает. Ни бот, ни `/play` не держат своей копии правила.
> 8. Tier-3 смоук на preprod-testbot (тест-чар `telegram_user_id=25`): партия ниже порога стартует сразу со строкой «Списано»; партия выше порога показывает подтверждение, «↩️» возвращает к рецепту без списания, «✅» стартует и списывает ровно итог экрана.

## Files
- app/Services/Craft/CraftBatchConfirmPolicy.php
- app/Services/Craft/CraftOrderService.php
- tests/database/CraftOrderServiceTest.php
- app/Controllers/Telegram/Commands/Actions/Craft/GenericCraftActionStart.php
- app/Database/Migrations/2026-12-16-100000_SeedCraftConfirmThresholdSettings.php
- app/Database/Migrations/2026-12-16-100010_SeedCraftBatchPriceTip.php
- tests/unit/Craft/CraftBatchConfirmPolicyTest.php
- tests/unit/Craft/CraftBatchConfirmCallbackTest.php
- tests/database/PlayViewControllerTest.php

## Non-goals
- Не подписывать итоги на самих кнопках количества и не менять `CraftCardHelper::STEPS`.
- Не трогать рендер `/play` (`WebNativeScreenService`, `Play.php`, вьюха) — это story 03; веб в этой story только начинает получать `CONFIRM_REQUIRED` от ядра. Старые `StartCraft…2Action` не трогать.
- Не менять `craft_again_callback` рецептов и регулярку `shortfallRecipeKey()` (ADR-182).

## Map slice
`memory/map/craft.md` → Entry points (`CraftOrderService::preview()`), Gotchas (ADR-182, лимиты очереди в GameSettings).

## Acceptance criteria
- [ ] Политика: qty ≥ min_qty или gold ≥ min_gold → true; 0 выключает своё условие; оба 0 → никогда; тест на границах (24/25, 49 999/50 000) и на соседней форме (min_qty=0, золото выше порога).
- [ ] Разбор колбэка: `genericCraft_Key_50` → qty 50 без флага подтверждения; `genericCraft_Key_50_ok` → qty 50 с флагом; `genericCraft_Key` → qty 1; длина `genericCraft_SpringPrimroseInfusion_100_ok` ≤ 64 байт.
- [ ] Ядро: `start(int $characterId, string $recipeKey, int $qty, bool $confirmed = false)`; при `needsConfirm` и `!$confirmed` — код `CONFIRM_REQUIRED` ДО транзакции и до любого списания, в ответе итог из `preview()`; database-тест: 25 шт без флага — золото, ресурсы и `character_tasks` не изменились; с флагом — старт. `preview()` отдаёт `needs_confirm`.
- [ ] Порог сверяется по тем же числам, что списывает старт (золото = `gold_required` × qty), гейты нехватки и очереди отрабатывают раньше подтверждения.
- [ ] Экран подтверждения строится из `CraftOrderService::preview()`; при отказе превью (нехватка, гейт) — тот же путь ошибки/нехватки, что сейчас, без экрана подтверждения.
- [ ] Обе миграции идемпотентны (повторный `up()` ничего не дублирует), `php -l` чистый.
- [ ] Tier-3 смоук по ask 8 выполнен Queen после деплоя на preprod; результат записан в Implementation notes.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- Ядро: `CraftOrderService::start(..., bool $confirmed = false)`, код `CONFIRM_REQUIRED`, ответ `batch{qty, gold, resources, crafted_items, minutes_total}` (тип `Batch`); проверка после гейтов и сырья, до транзакции. `preview()` отдаёт `needs_confirm`. Политика — `CraftBatchConfirmPolicy` (кэш GameSettings, дефолты-страховки 25 / 50 000 = значения сида).
- Бот: `parseCallback()` (4-й сегмент `ok`), `confirmText()` / `confirmKeyboard()` — чистые статики, `notifyConfirmBatch()` шлёт через `MediaSender::editOrSend` с фото рецепта. «↩️» — `info_callback` (есть у 107 из 112 рецептов), иначе `WorkbenchChoice`.
- Миграции `2026-12-16-100000_SeedCraftConfirmThresholdSettings` (категория `craft`, rationale/effect/above/below, soft 10–50 / 20k–200k, hard 0–100 / 0–10M) и `2026-12-16-100010_SeedCraftBatchPriceTip` (`CraftBatchPrice`, `крафт`, без чисел; соседний `CraftQuantityBatch` — где кнопки, этот — что партия стоит). Новых таблиц нет — WipeManifest не меняется.
- Тесты: политика на границах и соседней форме; колбэк ≤64 байт на самом длинном ключе; экран ≤1024 на всех рецептах ×100; DB — 25 шт без флага не трогает состояние, с флагом стартует, нехватка раньше подтверждения, порог из GameSettings, сид идемпотентен. В тест-хелпере политики кэш GameSettings чистится на каждую модель (60-секундный кэш переживает смену модели внутри теста).
- Промежуточное состояние ветки: до story 03 `/play` на партии выше порога получит `confirm_required` и покажет текст отказа вместо панели — в develop это не уезжает, story 03 на той же ветке.
- Tier-3 смоук (ask 8), preprod-testbot 2026-10-05, тест-чар 491 (`telegram_user_id=25`), автономный POST апдейтов на вебхук: стенд 160 000 💰 / 210 металлов / 80 пластика / 55 проводки. `craftPreviewT3Utility_SapperShovel` → firehose `ok`; `genericCraft_SapperShovel_25` → `ok`, состояние не изменилось, задачи нет (экран подтверждения); «↩️» (`craftPreviewT3Utility_SapperShovel`) → `ok`; `genericCraft_SapperShovel_25_ok` → `ok`, списано ровно 150 000 / 200 / 75 / 50, задача `in_work` ×25 с `consumed` в `task_settings`. Ни одного `undelivered` — Markdown экранов не словил 400. Визуал сообщений в Telegram Web не смотрели (только доставка и данные).
- Попутно: первый прогон дал `missing_materials` с `have:0` сразу после вставки строк стенда; повтор на тех же строках прошёл, а `spark`-проба из CLI видела ресурсы — причина не установлена (не код спеки: нехватка корректно сработала раньше подтверждения).
- Tech-writing: `services/CraftOrderService.md`, `services/CraftBatchConfirmPolicy.md` (новая), `handlers/craft/GenericCraftActionStart.md`, `apps/player/index.md`.

## Findings
