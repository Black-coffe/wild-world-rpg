# ADR harvest — docs/specs/cron-delivery-integrity

Source: `## Главное решение плана` and `## Plan deltas` in `docs/specs/cron-delivery-integrity/plan.md`,
cross-checked against `brief.md`. Existing ADR numbers checked in `C:/Projects/mmorpg-vault/decisions/`
— highest file present is ADR-186 (`ADR-186-Base-standoff-window-before-field-pvp.md`); ADR-188 was
proposed in `angela-second-base-bugs` on 2026-09-14 but no `ADR-188-*.md` file exists in the vault
(not yet accepted). Next free: **ADR-188**.

---

## Proposed: ADR-188

```markdown
---
type: adr
id: ADR-188
title: Гарантия подъёма Telegram-моста живёт в точке отправки, не у вызывающего
status: proposed
date: 2026-09-15
supersedes: []
relates: []
tags: [telegram, reliability, cron, notifications, gate]
---

# ADR-188 — Гарантия подъёма Telegram-моста живёт в точке отправки, не у вызывающего

## Context

Из `## Главное решение плана` (plan.md, cron-delivery-integrity):

> Гарантию ставим **в точке отправки, а не в точке вызова.** Сегодня она стоит у вызывающего: каждый
> из ~80 completion-хендлеров и ~50 крон-задач обязан вспомнить про `$this->telegram()` перед тем,
> как дёрнуть сервис. Один забыл — сообщение теряется молча. Так и случилось дважды: с
> `StandoffExpiryHandler` и с `AutoPveHandler`.
>
> Правильное место — сам отправляющий сервис: он **единственный**, кто точно знает, что сейчас пойдёт
> запрос в Telegram. Этот приём в проекте уже есть и работает: `EventNotificationSender` и
> `LevelUpNotifier` сами поднимают мост перед отправкой, поэтому их хендлеры безопасны независимо от
> того, кто и откуда их зовёт. Расширяем именно его, а не аудит вызывающих.
>
> Почему не аудит: разведка прошла 24 крон-хендлера и нашла ноль опасных — в том числе цепочка
> `EventTickHandler` → `EventDispatcher` → эффекты Telegram не трогает вообще, а
> `EventActivationHandler` безопасен через самоинициализирующийся `EventNotificationSender` (он же
> образец для `-02`). Осталось ~80 completion-хендлеров под `Worker.php` (он сам мост не поднимает —
> `Worker.php:121-124`), а цепочки там двухзвенные (`AutoPveHandler` → `PvEService` →
> `PveNotificationSender`), и простым грепом не ловятся. Аудит такого размера даст список, который
> протухнет со следующим новым хендлером. Гейт даст инвариант.

Дополняется контрактом из `## Contracts` и деltой контракта (plan.md, 2026-09-15):

> `App\Services\Telegram\TelegramBridge::ensure(): bool` — идемпотентно поднимает мост
> (`new Telegram(API_KEY, BOT_USERNAME)` + `Request::initialize`) из `getenv('telegram.*')`;
> `true` — мост готов, `false` — не удалось (одна строка `log_message('error', …)` с причиной).
> **Никогда не бросает.** Повторный вызов после успеха — no-op.

> **Контракт `TelegramBridge` расширен (отчёт воркера `-01`).** Кроме `ensure(): bool` —
> `instance(): ?Telegram` (базовым `telegram()` нужен объект) и `reset(): void` (только для тестов:
> состояние статическое). `BaseTaskHandler::telegram()`/`BaseObjectHandler::telegram()` теперь
> `?Telegram` — `bool` сломал бы override в `tests/database/StandoffExpiryHandlerTest.php`, вне
> скоупа. Неудача `ensure()` не кэшируется: каждый вызов пробует снова и пишет одну `error`-строку —
> принято, `-03` решает про шум. Отклонено: кэшировать провал — ключ может появиться после деплоя
> без рестарта.

Из `brief.md` (`## Что известно фактически`) — три подтверждённых инцидента этого класса дефекта:

1. **`community-chat-bot`, 2026-08-25** — тот же класс дефекта (упомянут как первое повторение в
   `## Предложение сверх заказанного`).
2. **`StandoffExpiryHandler`, 2026-09-11** — аварийная ветка `new Telegram('invalid','invalid')` в
   `BaseTaskHandler::telegram()` бросает то же исключение, которое ловит; уронила CI на `d514f203`.
3. **`AutoPveHandler`, воспроизведено настоящим кроном на preprod 2026-09-11** — бой засчитан
   (`battle_logs`, здоровье 100.00 → 1.00), игрок не получил сообщения:
   `WARNING - PvE notify failed (бой уже засчитан): Call to a member function getBotUsername() on
   null`. `AutoPveHandler` — единственный из просмотренных крон-хендлеров, не наследующий
   `BaseTaskHandler`, поэтому мимо него прошла чистка, применённая к пяти другим хендлерам.

## Options

1. **Аудит ~80 completion-хендлеров под `Worker.php` поштучно** — нашёл бы текущие дыры, но список
   такого размера протухает со следующим новым хендлером; `Worker.php` сам мост не поднимает
   (`Worker.php:121-124`), и цепочки двухзвенные, не ловятся грепом. Отклонено.
2. **Гарантия в точке отправки: каждый отправляющий сервис сам поднимает мост через общий помощник
   `TelegramBridge`**, по уже работающему в проекте образцу (`EventNotificationSender`,
   `LevelUpNotifier`). Принято.

## Decision

Ответственность за живой Telegram-мост несёт **отправляющий сервис**, а не вызывающий его
хендлер/крон-задача. Общий помощник `App\Services\Telegram\TelegramBridge` даёт контракт:

- `ensure(): bool` — идемпотентно поднимает мост из `getenv('telegram.*')`; `true`/`false`, **никогда
  не бросает**; неудача пишет одну `error`-строку с причиной и не кэшируется (ключ может появиться
  после деплоя без рестарта); повторный вызов после успеха — no-op.
- `instance(): ?Telegram` — объект моста для мест, которым он нужен напрямую (`BaseTaskHandler`,
  `BaseObjectHandler`).
- `reset(): void` — только для тестов, сбрасывает статическое состояние.

Любой новый сервис в `app/Services/**`, вызывающий `Request::send*`/`Request::edit*`, обязан поднять
мост через `TelegramBridge` сам, а не полагаться на то, что это сделал вызывающий. Инвариант
проверяется детерминированным гейтом (story `-04`, по образцу `WipeManifestCoverageTest`): скан
`app/Services/**` на вызовы `Request::send*`/`edit*` без подъёма моста и явного списка исключений с
причиной, плюс поведенческий тест реального пути отправки без ключа (скан исходника сам по себе не
является покрытием).

## Consequences

- Пять копий лгущей аварийной ветки (`BaseTaskHandler`, `DeathService`, `GatherResultPersister`,
  `LevelUpNotifier`, `BaseObjectHandler`) заменены одним общим помощником, который не бросает.
- Новый отправляющий сервис безопасен независимо от того, кто и откуда его вызывает — вызывающему
  (хендлеру, крон-задаче) больше не нужно помнить об инициализации моста.
- Гейт (`-04`) — единственная защита от рецидива; без него следующий сервис может повторить дефект
  молча, как уже случилось трижды при одном и том же зафиксированном уроке.
- `BaseTaskHandler::telegram()`/`BaseObjectHandler::telegram()` возвращают `?Telegram`, а не `bool` —
  вызывающий код обязан проверять на `null`, не полагаться на исключение.
- Аудит ~80 completion-хендлеров под `Worker.php` явно не проводится; гейт закрывает класс дефекта,
  но не гарантирует, что уже существующий код перед этой спекой безопасен без прогона гейта на нём.

## Revisit when

Владелец захочет поштучную перепись существующих completion-хендлеров под `Worker.php` (названо в
`## Что осталось за рамками` как отдельная разведка) — тогда потребуется решить, распространять ли
этот же контракт на код, который гейт ещё не видел, или писать миграционный план.
```

---

## Judged, did not earn an ADR

- **`-05` («Бумага») не worker-story, tech-writing и ADR идут после merge** (2026-09-15 delta) —
  caste/process assignment per `CLAUDE.vulyk.md` Project bindings, not a decision future code needs
  to re-derive.
- **Asks 1–5 сформулированы Queen по делегированию владельца** (2026-09-15 delta) — records how the
  brief's requirements were phrased when no grill ran; bookkeeping about paperwork provenance, not
  architecture. The requirements themselves are already the plan's stories, not a separate decision.
- **`## Verification` — только полный набор, не placeholder на один файл** (2026-09-15 delta) — how
  `close-story` reads the `## Commands` table cell; VULYK tooling convention, same category as the
  identical item already judged in `angela-second-base-bugs`.
- **Полный набор — только на свежей пустой базе, как в CI** (2026-09-15 delta) — a local test-
  environment finding (shared `wildworld_tests` is pre-broken, unrelated to Telegram) with its own
  recipe; operational, not a constraint on code that does not exist yet. Candidate for a `memory/`
  learning, not an ADR.

## Gaps — reason missing, no ADR possible

None found. Every decision-shaped item in `## Главное решение плана` and `## Plan deltas` carried
its own stated reason (and was judged above, either merged into ADR-188 or ruled bookkeeping).

> Нумерация (Queen, 2026-09-15): ADR-187 уже занят предложением `angela-second-base-bugs/adr-proposals.md` («удалённое управление через Вышку связи»), поэтому это предложение — ADR-188.
