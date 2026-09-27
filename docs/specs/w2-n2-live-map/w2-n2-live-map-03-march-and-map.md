---
story: w2-n2-live-map-03
spec: w2-n2-live-map
status: done
returned: DONE
tier: 2
worker: worker-code
model: opus
wave: 3
blocked_by: [w2-n2-live-map-02]
---

# Сервис Похода, Поход с карты в вебе, публичный /map

## Goal
`App\Services\World\MarchService` несёт логику `MarchAction`/`CancelMarchAction` без Telegram:
- `preview(dir, n)` — биом впереди, цена, ETA по местности, крюк транспорта, потолок `max_steps_per_order`;
- `start`, `extend`, `resume`, `stop`, `status`.

`msg_chat_id`/`msg_id` пишет только бот-рендерер, после отправки своего сообщения. `MarchAction` и
`CancelMarchAction` становятся рендерерами.

Веб:
- клик по клетке на луче открывает превью Похода: `op=march_preview`, `n` = расстояние по Чебышёву, зажатое в потолок, кнопки ➖/➕;
- «Выступить» — `march_start`;
- прогресс идущего Похода и «Остановиться» (`march_stop`) видны на карте и в HUD;
- после паузы есть продление и возобновление.

Публичный `/map`: инлайн-стили переводятся на токены `wildworld-ui.css` (0 радиусов, палитра,
шрифты), координаты подсказки — 0..999, PNG получает версию по `filemtime`. Поднять `?v=` у изменённых CSS/JS в `meta.php`. Вошедший игрок видит
«Играть отсюда» → `/play?view=map`, а в карте `/play` есть ссылка «Весь мир» → `/map`.

## Requirements
> 4. Поход — сервис превью/старта/продления/возобновления/остановки для обоих клиентов; веб видит идущий Поход (прогресс, ETA, «Остановиться») через HUD и опрос; тики в Telegram доставляются как раньше.
> 6. Публичный /map: только токены wildworld-ui.css, координаты 0..999, PNG кэшируется; вошедший видит «Играть отсюда» → карта /play, из карты /play — ссылка «Весь мир».
> 2. В /play — серверная сетка с кликом: соседняя клетка = шаг, клетка на одном из 8 лучей = превью Похода (направление, число клеток, ETA, цена), прочие — подсказка; роза направлений работает без JS.

## Files
- app/Services/World/MarchService.php
- app/Controllers/Telegram/Commands/Actions/MarchAction.php
- app/Controllers/Telegram/Commands/Actions/CancelMarchAction.php
- app/Services/Web/WebNativeScreenService.php
- app/Services/Player/CharacterSheetService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_map.php
- app/Views/site/_play/hud.php
- app/Views/site/map.php
- app/Controllers/Map.php
- public/assets/css/wildworld-ui.css
- public/assets/js/wildworld-play.js
- app/Views/site/_layout/meta.php
- public/ui-kit.html
- tests/database/MarchServiceTest.php
- tests/database/PlayViewControllerTest.php
- phpstan-baseline.neon

## Non-goals
- Не переписывать `MarchingTaskHandler` (тик, паузы, мини-события, доставку) — только читать его строку задачи.
- Не менять ключи и значения `world.march.*` / `world.march_events.*`.
- Не прокладывать путь к произвольной клетке — только 8 лучей.
- `/map`: не делать тайлы/WebP и не переписывать canvas-логику (это W3.P2); не показывать гостям игроков и базы.

## Map slice
`memory/map/world.md` (Поход, `world.march.*`, возврат на карту); `memory/map/tasks-worker.md`; `memory/map/website.md` (ADR-062, `/map`).

## Acceptance criteria
- [ ] Тест: превью, старт, продление, возобновление и стоп дают те же отказы и ту же строку `character_tasks` (`task_settings`, кроме `msg_*`), что прежний `MarchAction`. Старт из веба пишет строку без `msg_id`.
- [ ] Бот: пикер, настройка маршрута, «Выступить», остановка и возобновление выглядят как до правки; тик в Telegram редактирует то же сообщение.
- [ ] Веб: клик по клетке на луче показывает превью с числом клеток, ETA и ценой. «Выступить» запускает Поход, HUD и карта показывают прогресс, «Остановиться» останавливает. Повтор `intent_id` не создаёт дубль.
- [ ] `/map`: `grep` не находит в `map.php` ни `border-radius`, ни `box-shadow`, ни сырых hex; подсказка показывает 0..999; URL PNG не меняется между загрузками.
- [ ] Мостики «Играть отсюда» и «Весь мир» есть; превью Похода — в `ui-kit.html`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `MarchService` (новый): `preview/start/afterStart/attachMessage/extend/resume/stop/status` + чистые `clampOrderToCap`, `vehicleHookBlock`, `routeEtaMinutes`, `ray`; логика, тексты и `world.march.*` перенесены 1:1 из `MarchAction`/`CancelMarchAction`, ключи и дефолты не тронуты.
- `start()` пишет строку без `msg_*`; бот после `editOrSendText` зовёт `attachMessage()` (msg_id = нажатое сообщение, как раньше) — дописывает во все активные строки Похода без `msg_id`, поэтому и в ту, что тик мог успеть породить.
- Бот-рендереры: проверки `blockReason()` (переезд/эксклюзив) остались в handler'е — это рендер отказа байт-в-байт; сервис повторяет те же гейты нейтрально для веба. `MarchAction::vehicleHookBlock/routeEtaMinutes/clampOrderToCap` — тонкие обёртки (их зовут unit-тесты); `clampOrderToCap` стал `public static` (phpstan: unused private).
- Паритет бота: `MarchServiceTest::BOT_BEFORE` — 16 случаев (пикер, экран маршрута/потолок/край, старт с правкой и с фолбэком, нет задачи, эксклюзив, переезд, продление, возобновление, стоп идущего/паузы, пустые отказы), сняты с кода ДО правки, идут в отдельном процессе; `task_settings` сравниваются ksort'ом (порядок ключей `msg_*` другой).
- Веб: `op=march_preview` (клетка на луче дальше соседней, `n` = Чебышёв, зажим в потолок сервисом; без JS — flash `play_map_preview` + PRG), `march_start/extend/resume/stop` с дедупом `intent_id:march_*`; подсказки первого Похода — под захватом `WebDelivery`, как хуки шага.
- Прогресс: `CharacterSheetService::hud()` несёт `march` (`MarchService::status`, ETA по той же разбивке маршрута); HUD — строка с таймером `data-ends-at` и «Остановиться»/«Продолжить», карта — блок прогресса, «Продлить +5», «Остановиться». Опрос `/play/inbox` уже обновляет HUD — `wildworld-play.js` не менялся, его `?v=` не поднят; `wildworld-ui.css` → `?v=12`.
- `cellHint`: «Поход — скоро» заменён подсказкой про 8 линий; ссылка «Весь мир» → `/map` под картой.
- `/map`: `<style>` вьюхи вынесен в `wildworld-ui.css` (`.ww-map-*`, токены, 0 радиусов/теней), цвета легенды — из `BiomePalette` в контроллере, маркеры canvas читают CSS-токены (`--accent`, `--success`…), координаты подсказки 0..999, PNG — `?v=filemtime`, «Играть отсюда» → `/play?view=map` вошедшему при `web.play_enabled`. Canvas-логика не переписана.
- `ui-kit.html`: клетка на луче, превью Похода, блок прогресса и строка HUD (+ зеркало CSS).
- Видимые мелочи для Tier-3: маркер «Я» на `/map` теперь палитровый `--success` (был мятный), лоадер непрозрачный; `phpstan-baseline.neon` менять не пришлось.

## Findings
