# Живой проход на preprod-testbot (2026-09-30)

Релиз после merge 6c58d013, аккаунт 3 (тест-чар 491), временная email-identity; после прохода удалена.

| Проверка | Результат |
|---|---|
| `/account/reset`, `/account/reset/<token>` — заголовок | наш `Referrer-Policy: no-referrer` есть; сервер добавляет ещё `same-origin` ×2 — действует последняя, `same-origin`, она тоже не отдаёт Referer чужим доменам |
| Statable на страницах сброса | 0 — но на testbot `STATABLE_SITE_HASH` не задан (0 и на главной): проверка на проде после тега |
| Смена пароля без `current_password` | 422, «Неверный текущий пароль», `secret_hash` не изменился |
| Смена пароля с верным текущим | 302 → `/account?auth=email_added`, хэш сменился |

Хвост: откуда второй и третий `Referrer-Policy: same-origin` (фильтр CI4 или nginx) — не выяснено; на итог не влияет.
