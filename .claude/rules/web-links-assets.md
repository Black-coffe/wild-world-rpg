---
paths: ["app/Views/**", "app/Controllers/Front.php", "app/Controllers/Wiki.php", "public/assets/js/**"]
---
# Сайт и админка — ссылки, ассеты, публичный текст

Перенесено из `docs/defects/` старого VULYK.

- **`esc($url, 'url')` ломает полный URL** (`esc-url-in-href`): `https://t.me/x` → `https%3A%2F%2F…`, ссылка
  становится относительной. Полный URL в `href`/`src` — `esc($url, 'attr')` или `esc($url)`; `'url'` — только для
  сегмента пути. После правки: `curl <page> | grep 'href='` — нет `%3A%2F%2F`.
- **`public/vendor/` не деплоится** (`admin-vendor-asset-not-deployed`, в `.gitignore`): локально есть, на сервере
  404. Библиотеку — в tracked `public/assets/js/admin/`; перед ссылкой `git check-ignore <path>`; Tier-2 смотрит
  сеть на 404 и что график нарисован.
- **Никаких внутренностей в публичном UI** (`admin-internals-in-public-ui`): «скрыто», «admin-only», «будет позже»,
  «следующим релизом», имена таблиц и полей, TODO в HTML, `disabled` «только админу». Чего нет для игрока — того
  нет в разметке. `title`, `aria-label`, футеры и тексты ошибок — тоже публичный UI.
