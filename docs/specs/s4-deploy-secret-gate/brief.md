# Brief: s4-deploy-secret-gate

**Date:** 2026-10-26 · **Tier:** 1 · **Track:** хвост S4 (`s4-webhook-fail-closed`)

## Request (verbatim)

> Гейт в деплое (Рекомендую)

Вопрос, на который это ответ:

> Как заметить потерю секрета вебхука после S4 (без него игра на проде встанет)?

Описание варианта: post-deploy.sh: если в shared/.env CI_ENVIRONMENT = production, а telegram.WEBHOOK_SECRET пуст — exit 1
ДО переключения симлинка: деплой красный в GitHub Actions, старый релиз продолжает работать.

## Recon (2026-10-26)

- `deploy/post-deploy.sh:30-33` уже проверяет наличие `$SHARED/.env` до `composer install`; переключение симлинка — `:72`.
- Один `shared/.env` на хост, его же читает сайт (`wildworld.fun`, link-mode=public). Прод и preprod: `production`, секрет 64.

## Asks

1. Деплой (оба вызова `post-deploy.sh`: бот и сайт) падает с понятной ошибкой ДО переключения симлинка, если
   `shared/.env` — `CI_ENVIRONMENT = production` и `telegram.WEBHOOK_SECRET` пуст, закомментирован или отсутствует;
   значение секрета в вывод не попадает. Не-production `.env` и заданный секрет — деплой идёт как сейчас.
2. Тест на фикстурах `.env` падает, если пустой секрет в production проходит гейт (исходный случай) и если
   закомментированная строка секрета считается заданной (соседняя форма).
3. Минор ревью S4: докблок `webhook()` снова над `webhook()`, S4-абзац при нём.
4. (вердикты) `/guide` — нет, совет — нет, WipeManifest — не нужен.
