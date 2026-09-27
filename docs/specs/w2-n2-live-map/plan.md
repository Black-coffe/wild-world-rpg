# W2.N2 — живая карта, шаг и Поход с карты на нейтральном ядре (plan)

**Tier:** 2 · **Spec slug:** `w2-n2-live-map` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро, раздел «Реализация W2.N1»), ADR-189 (мост как фолбэк), ADR-152 (компас биомов), ADR-174 (транспорт), ADR-062, ADR-024, ADR-020
**Depends on:** w2-n1-hud-character (v0.51.675): `/play/view`, `WebNativeScreenService`, `site/_play/{hud,dock}.php`, дедуп `web_play_intents`

## Goal
Экран «Мир» перестаёт быть Telegram-экраном. Появляется сервис с моделью карты: сетка окна вокруг
игрока с кодами клеток (биом, туман, маркеры), расстояние до базы, статы, доступные действия. Бот
рендерит из неё текстовую карту и розу, а веб рисует кликабельную HTML-сетку в `/play`. Шаг и Поход
выносятся в сервисы, которые возвращают исходы и события без `chat_id`. Их зовут `MoveCharacterToDirectionAction`,
`MarchAction` и `CancelMarchAction` (теперь это рендереры), а в вебе — новые `op` у `/play/view`.
Публичный `/map` переводится на токены дизайн-системы и связывается с игровой картой. Заодно
закрываются два хвоста ревью W2.N1.

## Assumptions
- Tier 2 на верхней границе, как и N1: три review-единицы, каждая переписывает крупный handler бота.
  По разведке это примерно 350 строк `MoveSurfaceService`, 500 строк `TextMapService`, 730 строк шага
  и 810 строк Похода. Если story не укладывается, делим её, а не расширяем.
- Окно карты то же, что в боте: 12×12, смещение 6. Паритет важнее размера, более широкое окно не делаем.
- **Механика не меняется.** Шаг по-прежнему не проверяет воду и чужие лагеря, а Поход проверяет. Эта
  асимметрия уже есть в коде, и её выравнивание — вопрос к владельцу, не часть этой спеки.
  Hardcoded прирост за шаг (стат 0.02, опыт 0.03 × ранний множитель) переезжает в GameSettings
  (`world.move.stat_per_step`, `world.move.xp_per_step`, seed-миграция, прежние значения
  по умолчанию), так что поведение прода не меняется.
- `MarchingTaskHandler` (тик) не переписывается. Поход, запущенный из веба, пишет `task_settings`
  без `msg_id`, и тик идёт по уже существующему фолбэку «отправить новое сообщение». Для web-only
  (виртуальный id) отправка уходит в outbox моста ADR-189, как у любого фонового пуша. Веб читает
  прогресс Похода из строки `character_tasks` через HUD и `/play/inbox`, таймер тикает в браузере.
- Клик по клетке в вебе мапится на существующую механику: сосед → шаг; клетка на луче → превью
  Похода с направлением и `n` = расстояние по Чебышёву, `n` зажат в прежний `max_steps_per_order`.
  Остальные клетки дают подсказку (биом, координаты). Путь к произвольной клетке не прокладываем.
- Нерадикальные правки бота допустимы (ADR-190 §4): три копии текста карты
  (`MoveSurfaceService::buildMapText`, `MoveCharacterToDirectionAction::buildUpdatedMapText`,
  карта в `MarchAction`) сводятся к одному рендереру модели. Мелкий дрейф текста между ними при
  этом исчезает.
- Кнопки карты без своего экрана (остров, события, дроны, сбор, легенда, обзор, караван/поселение
  после шага) идут `op=bridge` через `BRIDGE_ROUTES` (ADR-190, раздел «Реализация», п. 2).
- `/map` остаётся публичным canvas-обзором, JS для него обязателен. PNG кэшируется по `filemtime`
  вместо `?v=Date.now()`. Тайлы и WebP — W3.P2. Координаты в подсказке переходят на 0..999.
- Мутации (`step`, `march_start`, `march_extend`, `march_resume`, `march_stop`) дедуплицируются
  по `intent_id` с суффиксом экрана в `web_play_intents`, после починки лимита длины (Ask 8).
- `map` отсутствует в `wildworld_tests` (по памяти, не проверено). DB-тесты модели и шага строят
  свою фикстуру таблицы из миграции. Рендер на реальном мире проверяется на preprod (Tier-3).
- `wave-check` даёт `verify-gap` на все три story, как в W2.N1: токен `vendor/bin/phpunit` читается как путь вне `## Files`. Проверка — полный набор (ячейка `## Commands`), и он включает тесты story, названные в `## Files`. `empty-glob` у story 02 — это новая seed-миграция `2026-12-13-100000_SeedMoveStepGainSettings.php`, файла ещё нет.
- `trace-check` помечает строки `## Request` как непокрытые: это цитаты-источники (ROADMAP, report, daily), их содержание несут `## Asks` 1–8, которые story цитируют дословно. Asks 9 (вердикты) и 10 (живой проход) закрывает Queen в конце сборки: вердикты — в plan/daily, проход — Tier-3 на preprod.

## Stories

**Wave 1**
- `w2-n2-live-map-01` — модель карты (`LiveMapService`), бот рисует «Мир» из неё, нативная сетка `/play` с розой без JS, док «Мир», хвосты W2.N1.

**Wave 2**
- `w2-n2-live-map-02` — сервис шага с событиями, `MoveCharacterToDirectionAction` становится рендерером; веб: шаг кликом и розой, события под картой; прирост за шаг в GameSettings.

**Wave 3**
- `w2-n2-live-map-03` — сервис Похода (превью, старт, продление, возобновление, стоп), `MarchAction`/`CancelMarchAction` — рендереры; веб: Поход с луча, прогресс в HUD, «Остановиться»; публичный `/map` на токенах + мостики.

**Wave 4**
- `w2-n2-live-map-04` — два minor ревью раунда 1 (решение владельца 2026-09-27): `msg_id` бот-Похода при создании строки, потолок `march_extend` на обоих путях.

## Contracts
- `POST /play/view` получает `view=map`. Новые `op`: `step` (`dir`), `march_preview` (`dir`, `n`),
  `march_start` (`dir`, `n`), `march_extend` (`n`), `march_resume`, `march_stop`. Мутации идут с
  `intent_id`. Ответ, как у N1: `{html, hud, alert, csrf}`. Без JS — PRG на `/play?view=map`.
  Координаты и id персонажа берутся только из сессии.
- `LiveMapService::forCharacter(int $characterId): array` — `{center{x,y,biome}, window{x0,y0,size},
  cells[][]{x,y,code,biome,marker,explored}, distance_to_base, stats, actions[], legend[]}`, без
  Markdown. Коды маркеров — те же, что в текущей приоритетной лестнице `TextMapService`.
- `MoveService::step(int $characterId, string $dir): array` — `{ok, code, from, to, cost, events[]}`.
  `events[]` — `{type, text, buttons[]}`: нейтральный текст и кнопки как callback-данные, которые
  бот отправляет как сейчас, а веб показывает флешами (кнопки — через мост).
- `MarchService` — `preview/start/extend/resume/stop(int $characterId, …): array` — исходы с кодами
  отказа. `status(int $characterId): ?array` — `{heading, steps_done, steps_planned, eta, paused_reason}`
  для HUD.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei Andrievskii, 2026-09-27 — «да» (стадия 02)
**Briefed:** <written by scripts/cycle.sh briefed>
**Branch:** vulyk/w2-n2-live-map
**Checked:** <written by scripts/human-check.sh>
**Council:** GREEN round 1, 2026-09-27, at 40433ecc, pack a320be6f49b7
**Shipped:** <written by scripts/ship-check.sh --record>
