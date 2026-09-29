# web-accounts-hardening — текущий пароль при смене, токен сброса не уходит третьим сторонам (plan)

**Tier:** 1 · **Spec slug:** `web-accounts-hardening` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-188 (веб-аккаунты; инвариант 7 — пароли только `password_hash`)

## Goal
Закрыть два открытых пункта ревью web-accounts-p0: #14 — смена пароля в кабинете без текущего пароля
(захваченная сессия навсегда забирает вход по почте); #6 — токен сброса пароля в пути страницы, которая
грузит Statable и Google Fonts без `Referrer-Policy`.

## Assumptions
- Требование текущего пароля — только когда у аккаунта уже есть эта почта с хэшем. Строка почты без хэша
  (такой формы код не создаёт) пропускается без проверки: сверять не с чем.
- Statable убирается только со страниц сброса (флаг вьюхи `noThirdPartyAnalytics`); Google Fonts остаются —
  от Referer их закрывает заголовок, а URL страницы скрипт шрифтов не читает.
- `verify-gap` на полном наборе — известный ложный сигнал (исправление на ветке `vulyk/evolve-2026-09-29`).

## Stories

**Wave 1**
- `web-accounts-hardening-01` — Текущий пароль при смене; страницы сброса без Statable и с `Referrer-Policy: no-referrer`

## Contracts
- `AccountAuthService::setEmailPassword(int $accountId, string $email, string $password, ?string $currentPassword = null)`:
  смена у своей почты с хэшем без верного `$currentPassword` → `ERR_CURRENT_PASSWORD`.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas
**Briefed:** via mini-brief, Andrei, 2026-09-29
