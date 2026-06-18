# Аудит SILENT-NO-OP (тихие отказы)

**Дата:** 2026-06-18
**Принцип (директива владельца):** любое действие, которое игрок не может выполнить,
ОБЯЗАНО показать причину. «Нажал кнопку → в ответ тишина» = bug. Память:
`feedback_no_silent_action_failures.md`. Новая категория дрейфа **SILENT-NO-OP**
(поверх BUILT-BUT-DEAD / BUILT-BUT-INVISIBLE).

Триггер: живой игрок (2026-06-18) не понял, почему добыча «молча не идёт» (нехватка
выносливости) и почему лагерь «не ставится». Добыча оказалась реальным silent-фейлом
(починена отдельно, `GatherAction`); лагерь — ложная тревога (3-шаговый путь, не баг).

## Методология

6 параллельных агентов-аудиторов прошли **~105 файлов** в `app/Controllers/Telegram`,
содержащих `Request::emptyResponse()` / тихие `return`. Каждый `return` в `handle()`
классифицирован по рубрике:

- 🟢 **LEGIT** — на этом пути игроку уже показано сообщение (`sendMessage`/`sendPhoto`/
  `MediaSender`/`editMessageText`), либо alert-тост (`answerCallbackQuery [show_alert=true]`),
  либо сервис сам отписался (`checkRelocationAndBlock` → шлёт сообщение и возвращает true).
- 🔴 **SILENT-BUG** — осмысленное нажатие → guard/ошибка БЕЗ сообщения и БЕЗ алерта.
- 🟡 **fall-through** — `emptyResponse` достижим только устаревшим/битым callback (не живой
  кнопкой), но «часики» зависают.

## Результаты

| Кластер | Файлов | 🔴 | 🟡 | 🟢 |
|---|---|---|---|---|
| Core (движение/поход/задачи/мир) | 10 | 1 | 1 | 16 путей |
| Camp/Buildings (карточки зданий) | 12 | 0 | 0 | 12 |
| Camp actions + роботы + телепорт | 16 | 1 (orphan) | 0 | 15 |
| Craft (tools/components/weapons/armor/cooking) | 50 | 0 | 0 | 50 |
| NPC + Settlement + Quest | 12 | 0 | 0 | 12 |
| PVP + Drone + Games + Poll + Objects + Sell + Caravan | 15 | 0 | 4 | 11 |

**Вывод:** код в целом дисциплинирован — `alert()` / `sendError()` / `checkRelocationAndBlock`
покрывают подавляющее большинство. Реальные дыры точечные.

## Исправлено (этот заход)

| # | Файл:строка | Что было | Фикс |
|---|---|---|---|
| 1 ⭐ keystone | `SystemCommands/CallbackqueryCommand.php:61` | Любой нераспознанный callback (мёртвая/устаревшая кнопка) → `emptyResponse` без сообщения и без снятия «часиков». Корень всего класса (истор. инциденты `npcAct_`/`npcDlg_`). | `log_message('warning', unrouted)` + `answerCallbackQuery [show_alert]` «Кнопка устарела, открой меню заново: /start». **Страховочная сетка для ВСЕХ мёртвых кнопок.** |
| 2 | `Camp/BuildHandPumpAction.php` | `handle()` делал `return true;` без отправки для валидного игрока. Orphan — `buildHandPump` не маршрутизирован и нигде не эмитится (legacy удалён). | Удалён файл (dead code) + почищены 3 записи `phpstan-baseline.neon` (−3 baseline). Любая теоретическая `buildHandPump`-кнопка теперь ловится keystone'ом. |
| 3 | `Games/FortuneWheelAction.php:84` | Нераспознанный суб-экшен → `emptyResponse`, часики висят. | `answerCallbackQuery [show_alert]` «Кнопка устарела, открой Колесо заново». |
| 4 | `Games/FortuneWheelAction.php:130` | `handleSpinWheel` без суммы ставки → `emptyResponse`, часики висят. | `answerCallbackQuery [show_alert]` «Не удалось определить ставку…». |
| 5 | `Sell/SellResourceAction.php:80` | Нераспознанный формат `sellResource_*` → `emptyResponse`. | `answerCallbackQuery [show_alert]` «Открой продажу заново». |
| 6 | `Sell/BuyResourceAction.php:93` | Switch fall-through (пустой id/qty) → `emptyResponse`. | `answerCallbackQuery [show_alert]` «Открой магазин заново». |

**Не требует фикса:** `MarchMiniInvestigateAction:48` (🟡) — сообщение отправляется
через `MediaSender::editTextOrSend`, «тишина» возможна лишь при не-fallback'ящемся
MediaSender (рантайм, не код).

## Почему keystone закрывает класс

Слои роутинга callback'ов: wildcard → prefix → exact. Если ни один не сматчил
(устаревший ключ, опечатка, удалённый хендлер, прод-кнопка из старого сообщения после
редеплоя) — раньше игрок видел чистую пустоту + висящие «часики». Теперь — понятный
alert + гашение спиннера + запись в лог (всплывёт в daily log review). Это превращает
**весь** будущий класс «мёртвая кнопка» из silent-бага в самообъясняющийся отказ.

## Связанное

- Принцип: `memory/feedback_no_silent_action_failures.md`
- Конституция: правило UX-Discoverability (фичу видно) + ONBOARDING-COVERAGE (just-in-time)
- Добыча (первый кейс): `GatherAction` подсказка выносливости, v0.51.461
