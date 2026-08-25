---
story: community-chat-bot-12
spec: community-chat-bot
status: done
tier: 3
worker: worker-code
tracer: false
wave: 5
blocked_by: [community-chat-bot-09, community-chat-bot-11]
---

# /admin/community: очередь модерации, отзыв, метрики

## Goal
Руки владельца. Экран, где черновик становится сказанным в чате словом — единственный
путь из `draft` в `approved`. Здесь же отзыв ошибочного ответа, удаление данных игрока
по запросу и четыре метрики, по которым видно провал.

## Requirements
> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически, сам от имени бота или от моего имени

> когда мы запускаем cloud code, проходился по всем веткам, собирал информацию и чтобы где-то ее агрегировал

## Files
- app/Controllers/Admin/CommunityController.php
- app/Views/admin/community_index.php
- app/Views/admin/community_answer_form.php
- app/Views/admin/layouts/_aui_sidebar.php
- app/Config/Routes.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## Non-goals
- Не менять дизайн-систему: только токены и компоненты `admin-ui.css` (`.aui-*`), никаких
  Hyper-градиентов, глубоких теней и цвета вне палитры (ADR-128).
- Не вызывать `$auth->user()` — в этом проекте у `Authentication` есть только
  `getCurrentUser()`. Авторизация приходит из группы маршрута `filter => 'login'`,
  контроллер сам ничего не проверяет.
- Не трогать `GameSettingsController` — крутилки уже есть на своём экране.
- Не строить редактор банка «на будущее»: правка текста черновика перед одобрением — да,
  массовые операции — нет.

## Map slice
`memory/map/admin.md`. Эталон CRUD — `app/Controllers/Admin/GameTipsController.php`
(`extends BaseAdminController`, `find()` → `failNotFound()` → `validate()` → `audit()` →
`redirectWithSuccess()`). Sidebar — `app/Views/admin/layouts/_aui_sidebar.php`, группа
«Операции» рядом с «Советы в игре» и «Сообщение всем». Маршруты — внутри
`$routes->group('admin', ['filter' => 'login'], …)`, `Routes.php:36`.

## Contract (из plan.md §5, §9, §13)
Экран очереди: открытые вопросы и черновики, сгруппированные по топику, с исходным текстом
игрока и предложенным ответом. Действия: **Одобрить** (→ `approved`, отправка реплаем через
`CommunityChatSender`, запись пополняет банк), **Правка** (текст меняется до одобрения),
**Отклонить** (→ `rejected`), **Отозвать** (→ `revoked` + поправка в тот же топик — без этой
кнопки единственный способ починить ошибку это искать её глазами).

Одобрение обязано пройти `CommunityGuard` ещё раз: владелец мог отредактировать текст.

Кнопка **«Стереть всё от этого игрока»** — обещание из закрепа даётся только если она
реально работает.

Четыре метрики на экране: доля сообщений, на которые отвечает бот, а не другой игрок
(**если игроки перестали отвечать друг другу — бот победил, а чат умер**); доля отказов
гварда (рост = пополнить банк, а не «гвард работает»); открытые вопросы старше 72 часов
(инцидент, не «низкий приоритет»); топ повторяющихся вопросов (карта дыр онбординга).

Каждое действие — `audit()` в `admin_audit_log`.

## Acceptance criteria
- [ ] Все маршруты живут внутри admin-группы за фильтром `login`; неавторизованный
      получает редирект, а не экран.
- [ ] Одобрение отправляет ответ реплаем и переводит строку в `approved` — атомарно: при
      отказе отправки статус не меняется.
- [ ] Отредактированный перед одобрением текст проходит `CommunityGuard` заново.
- [ ] Отзыв переводит в `revoked`, запись перестаёт матчиться, поправка уходит в тот же топик.
- [ ] «Стереть всё от этого игрока» удаляет его строки из `community_messages` и
      подтверждает числом удалённых.
- [ ] Метрика «бот против живых» считается за окно, а не за всё время.
- [ ] Каждое действие оставляет строку в `admin_audit_log`.
- [ ] Пункт появился в sidebar в группе «Операции» и подсвечивается на своём URL.
- [ ] Вьюхи используют только `.aui-*`; в новом коде нет `border-radius`, `box-shadow`,
      `text-shadow`, инлайновых `#hex`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/`

Tier-2 визуальный смок обязателен отдельно: MCP Chrome на `/admin/community`,
вьюпорты 1440 / 768 / 375 **CDP-эмуляцией, не resize**, console clean.

## Implementation notes

- `CommunityController` (new): `index()` показывает открытые вопросы `community_messages`
  (`new`/`escalated`, сгруппированы по `message_thread_id`), черновики `community_answers`
  (`status='draft'`) и активный банк (`status='approved'`) + 4 метрики §13. Бизнес-логика
  вынесена в тестируемые публичные методы без HTTP-цикла: `approveAnswer(int $answerId, ?int
  $messageRowId)`, `revokeAnswer(int $answerId, ?string $correctionText)`,
  `eraseMessagesFromPlayer(int $telegramUserId)`, `computeMetrics(?DateTimeImmutable $now)`.
  Тонкие HTTP-обёртки (`approve`/`revoke`/`erase`/`save`/`reject`/`editForm`) читают
  `$this->request->getPost()` и делегируют.
- `community_answers` не несёт FK на конкретное `community_messages` (её `source_ref` — это
  провенанс ТЕКСТА, формат `guide:…`/`tip:…`/`post:…`, как в `CommunityGuard::defaultCorpus()`,
  не id вопроса-триггера). Поэтому «на какое открытое сообщение ответить черновиком» — явный
  выбор владельца в форме (`<select name="message_id">` в `community_index.php` и
  `community_answer_form.php`), а не автоматический матч; `null` = «одобрить только в банк, без
  отправки сейчас». Assumption зафиксирован в докблоке класса.
- Отзыв находит исходное сообщение через `community_messages.answered_by_id = $answerId`
  (то же поле уже пишет `CommunityAutoReplyHandler` для авто-ответов) и шлёт туда же поправку
  реплаем — работает и для авто-ответов, и для ручных одобрений, без новой колонки.
- Метрики (§13): «бот против живых» = бот-ответы / (бот-ответы + человек-человеку) за 7 дней;
  человек-человеку определяется JOIN'ом `community_messages` самой на себя по
  `reply_to_message_id = message_id` с разными авторами (сообщения бота в таблицу вообще не
  попадают — вебхук не получает апдейт на собственную отправку, поэтому reply на строку из
  этой же таблицы структурно не может быть ответом боту). «Отказы гварда» = `escalated /
  (answered + escalated)` за окно. «Просрочено >72ч» — НЕ windowed (живой инцидент-счётчик).
  «Топ повторов» — группировка по `answered_by_id` (какой банк-ответ использован чаще).
  Окно 7 дней — код-константа `METRICS_WINDOW_DAYS`, не GameSettings-ключ: это аналитическое
  окно отчёта, не игровой баланс (правило ADMIN-TUNABLE BALANCE не применяется).
- Routes: `admin/community` (+ `answer/(:num)/{edit,save,approve,reject,revoke}`, `erase`) —
  внутри существующей `admin`-группы (`filter=>'login'`), `app/Config/Routes.php`. Sidebar —
  пункт «Чат сообщества» в группе «Операции», `_aui_sidebar.php`.
- Вьюхи только на `.aui-*` (карточки/таблицы/kpi-grid/badge/form), новых компонентов не
  вводилось — весь набор (`.aui-grid--kpi`, `.aui-badge--*`, `.aui-actions` и т.д.) уже был в
  `admin-ui.css`/`admin-redesign-preview.html`, изменения UI-kit не потребовались.
- Guide/tips-ревью (конституция): это admin-only экран (`/admin/community`), под
  UX-Discoverability/Onboarding/Guide/Tips-coverage не подпадает (player-interaction нет).

## Findings

- 🔴 **Конфликт с существующим `CommunityChatSender` (story 06), не мой файл.**
  `checkGates()` требует `community.autoreply.enabled=true` безусловно — в том числе для
  вызовов ИЗ ЭТОЙ story (ручное одобрение владельцем). План §4 (`below_effect_text` ключа
  `community.autoreply.enabled`) прямо обещает: «false — отвечает только владелец вручную
  через `/admin/community`». Как построено сейчас, при `autoreply.enabled=false` ручное
  одобрение с выбранной целью тоже не отправится (`sendAnswer()` вернёт false из-за общего
  гейта) — атомарность моей стороны это корректно обработает (черновик останется `draft`,
  ошибка покажется владельцу), но обещанный «ручной режим при выключенном авто» не работает
  до тех пор, пока `CommunityChatSender::checkGates()` не научится отличать ручной вызов от
  авто-тика. `CommunityChatSender.php` не в `## Files` этой story (Закон 3) — не чинил,
  оставляю на отдельную story/ремонтный круг.

### Приёмка Queen

Находка воркера (конфликт с `CommunityChatSender`) подтверждена и вынесена в **story 18** —
чинить чужой закоммиченный файл внутри этой story нельзя (Закон 3).

Приняты без изменений: выбор целевого сообщения владельцем через `<select>` вместо
автоматического матча (у `community_answers` нет FK на вопрос-триггер, `source_ref` — это
провенанс текста); окно метрик как код-константа (аналитика отчёта, не игровой баланс).

⏳ **Не сделано и требует живого браузера:** Tier-2 визуальный смок `/admin/community`
на 1440 / 768 / 375 CDP-эмуляцией, console clean. Конституция проекта требует его до
push публичных/админских вьюх. Вынесено в открытые хвосты релиза.

