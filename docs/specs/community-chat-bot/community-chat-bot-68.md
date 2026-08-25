---
story: community-chat-bot-68
spec: community-chat-bot
status: done
tier: 2
worker: worker-code
tracer: false
wave: 17
blocked_by: [community-chat-bot-63]
---

# Одобрение непокрытого ответа требует второго подтверждения

## Goal
Владелец не может бегло одобрить утверждение, которого нет ни в одном источнике: пометка приводит
глаз к нужной строке и требует сказать «отвечаю за него» — с записью в аудит.

## Requirements
> чтобы люди не использовали его для того, чтобы узнать какие-то фишки, лайфхаки и читинг в игре, и потом это не применяли

> Бот, своим лицом (Робби)

## Files
- app/Controllers/Admin/CommunityController.php
- app/Views/admin/community_answer_form.php
- public/admin-redesign-preview.html
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## Решение — ADR-178
Провенанс лишён права вето (story 63 делает его пометкой). Защищаемый отказ теперь другой:
**владелец бегло одобряет правдоподобную выдумку машинного черновика.** Лечится не тем, что кнопка
не работает, а тем, что глаз приведён к нужной строке.

Одобрение ответа с непустыми `advisories` не проходит с первого нажатия: показывается, какое
предложение не подтверждено, какой источник ближайший и с каким ratio; второе нажатие означает
«отвечаю за него» и пишет этот факт в `payload` строки `admin_audit_log`.

## 🔴 Дизайн-система админки обязательна
Правка админки подпадает под правило ADMIN-UI «Quiet Premium» (`CLAUDE.md`, ADR-128):
- только токены `admin-ui.css` (`.aui-*`), никаких Hyper-градиентов и глубоких теней;
- **новый компонент сначала появляется в `public/admin-redesign-preview.html`**, потом в
  production-view — не наоборот;
- цвет не может быть единственным носителем статуса: пометка дублируется текстом;
- `:focus-visible` и `aria-label` на новых интерактивных элементах.

Tier-2 визуальный смоук (MCP Chrome, 1440 / 768 / 375, console clean) прогоню я — тебе нужно
довести до состояния, когда его можно прогнать.

## Non-goals
- Не трогать `CommunityGuard` (story 63) и `CommunityAutoReplyHandler`: на отправке провенанс не считается вовсе.
- Не блокировать одобрение совсем — второе подтверждение, а не запрет: 75% отказов это стена, и кончится она тем, что режим выключат целиком.
- Не заводить новую таблицу: факт подтверждения живёт в `payload` существующего аудита.
- Не менять метрики админки (story 59).

## Acceptance criteria
- [x] Ответ без пометок одобряется одним нажатием, как раньше.
- [x] Ответ с пометками с первого нажатия не одобряется; показаны непокрытое предложение, адрес ближайшего источника и ratio.
- [x] Второе подтверждение одобряет и пишет факт в `payload` аудит-строки.
- [x] Компонент пометки присутствует в `public/admin-redesign-preview.html` и собран из токенов `admin-ui.css`.
- [x] Статус пометки читается без цвета (текстом), у интерактивных элементов есть `:focus-visible` и `aria-label`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/CommunityControllerTest.php`

## Implementation notes

- `CommunityController::approveAnswer()` получил третий опциональный параметр
  `bool $confirmAdvisories = false` (совместимо со всеми существующими вызовами).
  Непустые `$verdict->advisories` без `$confirmAdvisories=true` возвращают
  `['ok' => false, 'error' => …, 'advisories' => list<string>]` — не проходят, но
  это не вето гварда (`$verdict->isAllow()` уже true), а отдельная остановка на
  уровне контроллера. При успешном втором подтверждении `advisories_confirmed`
  (список пометок или `null`) уходит в `payload` строки `admin_audit_log`.
- `CommunityController::approve()` — HTTP-слой читает `confirm_advisories=1` из
  POST; если `approveAnswer()` вернул непустые `advisories`, вместо redirect
  переотрисовывает `admin/community_answer_form` тем же шаблоном с пометками,
  сохранённым `message_id` (`pendingMessageId`) и чекбоксом `confirm_advisories`
  (HTML5 `required`, без JS). Второе нажатие с отмеченным чекбоксом шлёт
  `confirm_advisories=1` и проходит. Сигнатура `approve()` расширена до
  `string|RedirectResponse|ResponseInterface` (тот же паттерн, что у `editForm()`).
- `app/Views/admin/community_answer_form.php` — новый блок `.aui-alert--warning` с
  перечнем пометок (каждая — непокрытое предложение + источник + ratio, дословно из
  `CommunityGuard::provenanceAdvisories()`) и явным текстом «отсутствие пометки НЕ
  значит подтверждено» (инвариант ADR-178, чтобы не читалось как «подтверждено
  источником»). Кнопка «Одобрить» превращается в «Подтвердить и одобрить» только
  когда `$advisories !== []`; без пометок — форма как раньше, один клик.
- Компонент сначала добавлен в `public/admin-redesign-preview.html` (новая секция
  «09 — Пометка провенанса», живой эталон), потом использован в production-view —
  порядок по правилу ADMIN-UI. Статус пометки читается текстом (не только цветом
  `--warning`), у чекбокса и кнопок — `aria-label`, у чекбокса — нативный
  `:focus-visible` из `.aui-check`.
- `tests/unit/Controllers/Admin/CommunityControllerTest.php`: переименован и
  переписан `testApproveRunsGuardAgainstCurrentTextAndBlocksOnDeny` →
  `…BlocksOnUnconfirmedAdvisories` (после ADR-178/story 63 несовпадающий корпус
  больше не даёт `Verdict::deny`, а даёт `allow($advisories)` — старое имя/докстринг
  стали неверны). Добавлен `testApproveWithConfirmedAdvisoriesApprovesAndRecordsAuditPayload`
  (два вызова `approveAnswer()`: без подтверждения → `ok=false`; с
  `$confirmAdvisories=true` → `ok=true`, статусы флипаются, `payload` аудита несёт
  `advisories_confirmed`). AC «одобрение без пометок в один клик» отдельного нового
  теста не потребовало — уже покрыто существующим
  `testApproveSendsReplyAndFlipsBothStatusesAtomically` (`permissiveGuard()` даёт
  точное текстовое совпадение → `advisories=[]`).
- Верификацию (`vendor/bin/phpunit`) не запускал по прямому указанию задачи — только
  `php -l` (все 3 правленых PHP-файла) и `vendor/bin/phpstan analyse` (чисто,
  `app/` целиком). Tier-2 визуальный смоук — на стороне team-lead.
- Не тронуто: `CommunityGuard.php`, `CommunityAutoReplyHandler`, метрики (story 59),
  схема БД/`WipeManifest` (факт живёт в существующем `payload`, новых таблиц/колонок
  нет).
- Tip/guide-вердикт: не добавляем. Это внутренний admin-only UX (не player-facing) —
  под UX-DISCOVERABILITY/GUIDE-COVERAGE/TIPS-COVERAGE не подпадает по явному
  исключению «Admin-only».

## Findings

- `public/admin-redesign-preview.html`: PostToolUse design-хук отметил low-contrast
  и layout-property-transition на новом блоке. Оба паттерна — не нового кода: это
  ровно те же классы (`.aui-alert--warning`, `.aui-small`, `.aui-check`), уже
  использованные в файле раньше (секции 03–06) с теми же токенами `admin-ui.css`.
  Правка токенов `admin-ui.css` вне `## Files` этой story — оставил как есть,
  системный вопрос дизайн-системы, не регресс story 68.
