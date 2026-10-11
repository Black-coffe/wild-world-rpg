---
story: web-onboarding-02
spec: web-onboarding
status: todo
returned:
tier: 3
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Экран законченной задачи сам заменяется её итогом — на стороне веба, без общего пути крона

## Goal
Opus: нужно решить, какое из входящих сообщений — итог именно этой задачи, не имея метки от крона. Ошибка подменит
экран чужим сообщением (шаг цепочки, титул).

**Поправка владельца 2026-10-12 (plan delta):** общий крон (`app/Controllers/Worker.php`) и путь отправки всем игрокам
(`WebDelivery::route()`, `BaseTaskHandler`, `Services/Telegram/Request.php`) не трогаются. Первая сборка story ставила
контекст задачи в `Worker` и меняла экран в `WebDelivery::route()` — обе правки откачены. Её правки
`WebScreenStore.php` и `WebActService.php` остаются в рабочем дереве (см. `## Implementation notes` первой сборки ниже)
и переиспользуются.

После этой story сохранённый экран `/play`, после действия которого у персонажа появилась новая задача `in_work`,
помнит её id (`task_id` в состоянии `WebScreenStore`, уже сделано). **При чтении состояния** — `WebActService::current()`
и `bootstrap()` (их зовут `Play::index`, а в story 03 — опрос `/play/inbox`) — если у экрана есть `task_id`, а строка
`character_tasks` этой задачи уже не `in_work`, экран заменяется её итогом: первым сообщением входящих этого
персонажа, созданным не раньше момента завершения задачи (`character_tasks.updated_at`) и не позже чем через окно
обработки `Config\WebPlay::taskResultWindowSeconds` (инфра-число, не баланс), **и не являющимся чужим по типу**.

**Фильтр по типу (поправка владельца 2026-10-12: «Ты выбрал вариант «веб-сторона» под обещание отсекать чужие
сообщения по типу, но в переписанной story 02 такого фильтра нет. Итогом считается просто первое сообщение после
задачи.»).** Выбор итога — чистая функция в новом `app/Services/Web/WebTaskResultPicker.php`
(`pick(array $task, list<array> $inboxRows): ?array`, без БД). Сначала она отбрасывает сообщения чужих типов, затем
берёт первое оставшееся по id. Чужие типы — фоновые сообщения, которые новичок получает вне итога задачи:
- шаг обучающей цепочки — клавиатура совпадает с `OnboardingChainCatalog::completion()` (`activeQuests` + `character`);
- подсказка из `OnboardingHintCatalog` (в т.ч. «🗓 Появились задания дня!») — текст совпадает с текстом каталога;
- уведомление о титуле (`TitleCheckCron`, «🎖 Новый титул» / «🎖 Новые титулы»);
- остальные фоновые отправители, которые могут сработать у L1–L5 в окне (совет дня, мировые события, рассылки) —
  воркер перечисляет их по коду и пишет список в `## Implementation notes`.
Сигнатура каждого типа выводится из его построителя (вызов каталога/константа), а не набивается строкой; где без
правки чужого файла сигнатуру не получить (`TaskHandlers/**` трогать нельзя), — константа в picker и тест, который
сверяет её с исходником отправителя (source-scan падает, если текст отправителя поменяли). Нет такого сообщения (задача прервана,
итог не пришёл, окно прошло) — экран заменяется коротким веб-сообщением «✅ <задача> завершена — итог во входящих 🔔»
без кнопки «❌ Прервать». Старый экран уходит в историю, `task_id` очищается, `rev` растёт. Пока задача `in_work`,
ничего не меняется.

## Requirements
> 2. Итог задачи (добыча, крафт) — на экране: экран законченной задачи сам заменяется её итогом, без «❌ Прервать».
> 6. Экран читается в media-off: приветствие, итог задачи и плашка нового сообщения несут весь смысл текстом; в веб-текстах нового первого экрана нет бот-лексики («ниже», «нижнее меню», «команда /…»).

## Files
- app/Services/Web/WebScreenStore.php
- app/Services/Web/WebActService.php
- app/Config/WebPlay.php
- app/Services/Web/WebTaskResultPicker.php
- tests/unit/Services/Web/WebTaskResultPickerTest.php
- tests/database/WebTaskResultScreenTest.php
- scripts/defects-task-result-foreign-check.php
- docs/defects/task-result-foreign-message.md
- docs/defects/fixtures/task-result-foreign-message-original.json
- docs/defects/fixtures/task-result-foreign-message-neighbour.json

## Non-goals
- **Не трогать** `app/Controllers/Worker.php`, `app/Services/Web/WebDelivery.php`, `app/TaskHandlers/**`,
  `app/Services/Telegram/Request.php`, `UpdatePipeline`: это путь задач и отправок всех игроков, включая Telegram.
- Не трогать `Play.php`, вьюхи, JS и `WebInboxService` (кроме чтения через его публичный API): показ новой ревизии на
  опросе и плашка — story 03.
- Не трогать нативные экраны (`WebNativeScreenService`, `view=craft|base`): они не хранятся (допущение плана).
- Не вводить новую колонку/таблицу: `rev`/`task_id` живут в JSON существующей колонки `screen`.
- Чужие по типу сообщения (шаг цепочки, подсказка, титул, совет, событие) итогом не становятся никогда — даже если
  пришли первыми в окне; они идут плашкой (story 03).

## Map slice
`memory/map/tasks-worker.md` → Gotchas («Worker ставит completed ДО handle()» — `updated_at` задачи = начало обработки,
итог пишется во входящие в ту же секунду или чуть позже); `memory/map/website.md` → «Игра на сайте»;
`docs/specs/web-onboarding/recon.md` → «Веб-доставка».

## Acceptance criteria
- [ ] Действие на `/play` (мост), которое стартует задачу (добыча), сохраняет экран с `task_id` этой задачи. Действие
      без новой задачи пишет `task_id = null`.
- [ ] Задача экрана `in_work` → `current()` возвращает экран без изменений, `rev` прежний.
- [ ] Задача стала `completed`, во входящих есть сообщение в окне → `current()` возвращает экран из этого сообщения
      (текст или caption, фото, кнопки — целиком, тот же `message_id`), прежний экран первым в `history`, лимит истории
      соблюдён, `rev` +1, `task_id` очищен. «❌ Прервать» на экране нет.
- [ ] Шаг цепочки «💧 Первый ресурс в кармане!» и итог добычи в одну секунду, шаг с меньшим id (порядок из прохода
      2026-10-11) → на экран встаёт итог добычи, шаг остаётся плашкой.
- [ ] В окне только чужие (шаг цепочки, титул, подсказка) → экран «✅ … завершена — итог во входящих 🔔».
- [ ] По каждому чужому типу — unit-тест picker'а; где сигнатура — константа, тест сверяет её с исходником отправителя.
- [ ] `docs/defects/task-result-foreign-message.md` (цитата владельца выше) — `status: block`,
      `check: php scripts/defects-task-result-foreign-check.php <arg>`; проверка гоняет `WebTaskResultPicker::pick()` на
      JSON-фикстуре `{task, rows, expect_message_id}` и падает (exit 1), если выбран не ожидаемый. Фикстуры: original —
      шаг цепочки раньше итога в ту же секунду; neighbour — титул и подсказка «Появились задания дня!» раньше итога.
      Проверка падает на picker'е без фильтра (только окно) — показать в `## Implementation notes`.
      `bash scripts/defects-check.sh` зелёный.
- [ ] Сообщение раньше `updated_at` задачи или позже окна итогом не берётся.
- [ ] Задача `interrupted` или итога нет за окно → экран «✅ … завершена — итог во входящих 🔔» без «Прервать», `rev` +1.
- [ ] Повторное чтение после замены экран не трогает (`task_id` уже очищен); замена идемпотентна при двух параллельных
      чтениях (блокировка строки состояния, как в `applyCapture`).
- [ ] `revision()` и `screenMessageIds()` отдают актуальные значения. Старое состояние без `rev`/`task_id` читается как
      `rev = 0`, `task_id = null`.
- [ ] `git diff` ветки не содержит `app/Controllers/Worker.php` и `app/Services/Web/WebDelivery.php`.
- [ ] Тест на БД строит схему из миграций (не рукописный DDL) и покрывает всё выше.
- Media-off: тест рендера показывает, что итог на экране несёт весь смысл текстом/caption без фото (ask 6).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
Первая сборка (до поправки владельца) — что из неё осталось в дереве:
- `app/Services/Web/WebScreenStore.php`: колонка `screen` теперь `{rev, task_id, messages}` (голый список старой формы читается как rev 0 / task_id null); `revision()`, `screenMessageIds()`, `screenTaskId()`, `replaceScreenForTask()`; `applyCapture(..., ?int $taskId = null)`; `rev` +1 только когда запись меняет экран.
- Пометка: экран сменился → `task_id` = новая задача действия или null; экран не сменился (только alert) → новая задача помечает, иначе пометка остаётся.
- `app/Services/Web/WebActService.php`: снимок MAX(id) `character_tasks` до действия, после — новейшая `in_work` с id больше снимка.
- Откачено: `WebDelivery::setTaskContext()`/замена в `toInbox()`, контекст в `Worker`. `tests/database/WebTaskResultScreenTest.php` писался под старую схему — переписать.

## Findings
