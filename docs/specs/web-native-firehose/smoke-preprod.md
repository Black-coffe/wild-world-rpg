# Preprod-смоук — web-native-firehose

**Дата:** 2026-10-11 · **Окружение:** testbot, релиз `2026-10-11-194309` (`develop` @ `ab4620ae`, run 38168830648 ✓) ·
**Тир:** 3 автономно: `curl` под web-only персонажем 522 `Веб92432` (account 15, виртуальный tg `-4503599627370511`),
вход remember-токеном, `POST /play/view` с CSRF.

## Строки `player_action_log` персонажа 522 (новые — #859–863)
| id | source | action_name | raw_input | status |
|---|---|---|---|---|
| 859 | web | play | — | ok (`GET /play`) |
| 860 | web | me | `view=me` | ok |
| 861 | web | step | `view=map&op=step` | ok |
| 862 | web | nope | `view=me&op=nope` | rejected («Эта кнопка уже недоступна…», http 400) |
| 863 | web | guide | `guide` | ok (мост: один тап — одна строка) |

`character_id` = 522 во всех строках, `telegram_user_id` — NULL.

## WAU (ROADMAP §0: `player_action_log`, `source <> 'task'`, уникальные `character_id` за 7 дней)
- До смоука: **1** (491); у 522 за 7 дней — 0 строк.
- После: **2** — `491,522`. Web-only игрок попадает в WAU.
- «Активные 7д» админ-дашборда (`DashboardAnalyticsService::activeMovers`) читают `explored_cells`, а не firehose:
  веб-шаг идёт тем же ядром `MoveService`, поэтому веб и бот там равны (ограничение «только новые клетки» общее).

## Лог и уборка
`ERROR|CRITICAL` за сегодня — пусто. `account_tokens` remember account 15 удалён, cookie-jar и хелпер удалены.
Шаг на север у 522 оставлен (testbot).
