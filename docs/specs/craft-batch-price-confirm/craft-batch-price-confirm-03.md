---
story: craft-batch-price-confirm-03
spec: craft-batch-price-confirm
status: done
returned: DONE
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
- `WebNativeScreenService::craftStartOutcome(..., bool $confirmed)` → `{alert, confirm}`; `craftStart()` остался обёрткой (`['alert']`) — прежние вызовы и тесты не тронуты. `confirm_required` ядра — не отказ: в `action_log` не пишется.
- `craftModel`: ключ карточки `confirm` (`confirmPanel()`) — `preview(qty)` ядра, только при `ok && needs_confirm && qty ≤ max_qty`; иначе обычные кнопки (порог сменили, сырьё ушло).
- `Play`: `craftNav` читает `confirm` (`^[1-9][0-9]{0,3}$`), `op=craft_start` передаёт `confirmed === '1'`; ответ `confirm` кладётся в навигацию → PRG `/play?view=craft&…&confirm=N` (с JS — тот же рендер в JSON).
- Вьюха: панель `play-native-note` + `play-craft-facts` + `play-craft-reqs` + `play-kb-grid` — только существующие классы, нового CSS и компонента нет (ui-kit не меняется, `?v=` не бампается). Кнопки: `$act` с `confirmed=1` и `$go` назад на карточку рецепта.
- Тесты: настоящее ядро через веб (`WebCraftStartTailsTest`: 25 шт без подтверждения — ничего не списано и не залогировано, панель в HTML, подтверждение стартует, повтор intent — ничего, 24 шт — сразу; дефолтные пороги — дешёвая партия 25 стартует сразу); контроллер (`PlayViewControllerTest`: PRG на `confirm=5`, панель, `confirmed=1` стартует, мусорный `confirm` отброшен).
- Tier-2 на testbot (1440/768/375, консоль) — после деплоя на preprod.
- Tech-writing: `services/WebNativeScreenService.md`, `controllers/Play.md`.

## Findings
