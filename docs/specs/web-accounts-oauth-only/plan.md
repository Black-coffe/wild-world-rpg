# web-accounts-oauth-only — вход на сайт только через Google, Яндекс и Telegram (plan)

**Tier:** 2 · **Spec slug:** `web-accounts-oauth-only` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-188 (веб-аккаунты; поправка этой спеки), ADR-062 (сайт)

## Goal
Сайт перестаёт хранить пароли и слать письма: вход и регистрация — только внешние экосистемы (Google, Яндекс) и
Telegram. Уходит вся поверхность почты с паролем — форма входа, регистрация по почте, сброс пароля, форма в
кабинете — вместе с кодом и тестами. Этим закрываются незакрытые пункты ревью ADR-188 #6/#7/#14 и блокер
«на проде нет SMTP».

## Assumptions
- Данных на удаление нет: на проде 0 строк `account_identities.provider='email'` (замер 2026-09-30). На testbot
  строки могут быть — story удаляет их не миграцией, а ничем: код их просто не читает; при желании — ручной
  `DELETE` на testbot в смоуке.
- `/account/register` остаётся как страница «как попасть на сайт» (закрытая бета → код `/web`), без email-формы;
  `/account/character` (создание персонажа после OAuth-регистрации) не трогаем.
- Удалённые POST-маршруты отвечают 404; GET `/account/reset*` — редирект на `/account/login`, чтобы старые
  ссылки из писем/закладок не вели в тупик.
- Колонка `account_identities.secret_hash` и таблица `account_tokens` остаются (схема не меняется, WipeManifest тоже).
- `verify-gap` wave-check — известный ложный сигнал (правка на `vulyk/evolve-2026-09-29`).
- Ask 5 (ADR), 6 (вердикты) и 7 (живой проход) закрывает Queen.

## Stories

**Wave 1**
- `web-accounts-oauth-only-01` — убрать вход/регистрацию/сброс по почте: маршруты, контроллеры, вьюхи, сервисы, тесты.

**Wave 2**
- `web-accounts-oauth-only-02` — тексты игроку без почты (сайт, кабинет, `/web`, `/guide`, советы) и страница входа/регистрации с Google/Яндекс/Telegram.

## Contracts
- `AccountAuthService`: остаются методы, нужные OAuth/Telegram (если есть); `registerWithEmail`, `setEmailPassword`,
  `verifyPassword` удалены. `PasswordResetService`, `AccountPassword` удалены.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas
**Briefed:** via grill, Andrei, 2026-09-29
