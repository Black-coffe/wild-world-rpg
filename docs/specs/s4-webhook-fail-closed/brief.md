# Brief: s4-webhook-fail-closed

**Date:** 2026-10-26 · **Tier:** 1 · **Track:** ROADMAP S4

## Request (verbatim)

> Дальше рекомендую S4

ROADMAP (строка, на которую ссылается запрос):

> - **S4** · Tier 1 · Вебхук fail-open: при пустом `telegram.WEBHOOK_SECRET` проверка пропускается (`BotController.php:40`).

## Recon (2026-10-26)

- `app/Controllers/Telegram/BotController.php:40-47`: `if (!empty($expected)) { … hash_equals … 403 }` — пустой или
  отсутствующий секрет пропускает любой POST на `/telegram/webhook` (поддельные апдейты от имени любого игрока).
- Прод (`bot.wildworld.fun`) и preprod (`testbot.wildworld.fun`): `.env` несёт секрет длиной 64, `CI_ENVIRONMENT = production`
  (сверено чтением 26.10). Локальный `.env` секрета не имеет; тесты идут в `testing`, ни один не постит на вебхук по HTTP.
- `.env.example:77-79` описывает пустое значение как «skip verification».

## Asks

1. В `production` вебхук без настроенного `telegram.WEBHOOK_SECRET` отклоняет каждый апдейт (403) и пишет CRITICAL в лог;
   с секретом — как сейчас (`hash_equals`, 403 при несовпадении). В `development`/`testing` пустой секрет по-прежнему
   пропускает проверку (локальная разработка и тесты).
2. Тест падает, если в `production` пустой секрет снова пропускает апдейт (исходный случай), и если несовпадающий
   секрет проходит (соседняя форма).
3. Прод и preprod не теряют апдейты: секрет там задан (сверено), живые установки не меняют поведение.
4. (вердикты) `/guide` — нет, совет — нет: защита бэкенда, игроку не видна. WipeManifest — не нужен. Tech-writing нота
   `controllers/BotController.md` обновлена.
