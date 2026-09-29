# Ask 7 — живой проход на preprod-testbot (2026-09-30)

Релиз после merge 03db8f73, тест-чар 491 (аккаунт 3). Без мутаций игры; код `/web` выпущен сервисом на сервере.

| Проверка | Результат |
|---|---|
| GET `/account/login` | поле почты 0, пароль 0, ссылка на сброс 0, «Ввести код из бота» 1 |
| GET `/account/reset`, `/account/reset/<token>` | 302 → `/account/login` |
| POST `/account/login`, `/account/register`, `/account/identity/email` | 404 |
| Вход кодом `/web` (`/account/link`) | 200 → `/account`, в кабинете нет формы почты и пароля |
