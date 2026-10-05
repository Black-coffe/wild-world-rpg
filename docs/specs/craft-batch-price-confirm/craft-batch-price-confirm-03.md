---
story: craft-batch-price-confirm-03
spec: craft-batch-price-confirm
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 3
blocked_by: [craft-batch-price-confirm-02]
---

# Подтверждение крупной партии в /play

## Goal
В `/play` ступени количества и поле «Своё число» при партии выше порога не запускают крафт, а показывают
панель подтверждения с тем же итогом, что и бот: ядро отвечает `CONFIRM_REQUIRED`, веб рендерит его. Правила в
вебе нет — только рендер ответа ядра и повторная отправка с флагом подтверждения.

## Requirements
> Если на сайте в /play есть выбор количества, там партия может запуститься без подтверждения. Уточни этот момент и смотри, чтобы он не ломал и не рассинхронивал логику.
> 10. В `/play` ступени количества и поле «Своё число» при партии выше порога ведут на панель подтверждения с тем же итогом золота, ресурсов, компонентов и времени, что у бота (из `preview()`), с «✅ Запустить» и «↩️ Изменить кол-во»; ниже порога — старт сразу, как сейчас; повтор `intent_id` по-прежнему ничего не делает.
> 11. Панель подтверждения в `/play` собрана из существующих классов `wildworld-ui.css` / `play-*` (ADR-062, без новых компонентов) и проверена на testbot: `/play?view=craft` на 1440 / 768 / 375, консоль чистая.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_craft.php
- tests/database/WebCraftStartTailsTest.php
- tests/database/PlayViewControllerTest.php

## Non-goals
- Не копировать порог или формулу в веб: только `CONFIRM_REQUIRED` / `needs_confirm` от ядра.
- Не трогать `op=bridge` и `craftRoute()`: мост пускает только `genericCraft_<Key>_1` к экрану нехватки.
- Не вводить новых CSS-компонентов и JS; без JS всё работает через PRG.

## Map slice
`memory/map/website.md` → `/play`; `memory/map/craft.md` → Entry points.

## Acceptance criteria
- [ ] `craftStart()` получает `confirmed`; на `CONFIRM_REQUIRED` не пишет отказ в `action_log` как ошибку и возвращает состояние панели (PRG на `/play?view=craft&…&recipe=<Key>&confirm=<qty>`).
- [ ] Панель: имя, количество, итог золота / ресурсов / компонентов / времени из `preview(qty)`; форма «✅ Запустить» (`op=craft_start`, `qty`, `confirmed=1`, новый `intent_id`) и ссылка «↩️ Изменить кол-во» на карточку рецепта.
- [ ] Тест: 25 шт без `confirmed` — ничего не списано, ответ — панель; с `confirmed=1` — старт; повтор того же `intent_id` — ничего; ниже порога — старт сразу, как сейчас.
- [ ] `curl` `/play?view=craft` → 200/302, как до правки; Tier-2 проверка на testbot на 1440 / 768 / 375 выполнена Queen после деплоя.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
