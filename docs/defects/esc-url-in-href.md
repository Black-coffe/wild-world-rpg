---
id: esc-url-in-href
title: Полный URL в href экранирован контекстом 'url' и становится относительной ссылкой
status: text
check:
fixtures: []
keys: [ссылка ведёт в никуда, %3A%2F%2F, esc url]
paths: ["app/Views/**"]
---

# Полный URL в href экранирован контекстом 'url'

`esc($url, 'url')` percent-кодирует строку как компонент URL: `https://t.me/x` превращается в
`https%3A%2F%2Ft.me%2Fx`, браузер читает его как относительный путь, и кнопка ведёт на
`wildworld.fun/https%3A%2F%2F…`. Так жили все CTA бота и группы на сайте с самого запуска.

## Нельзя
- Писать `href`/`src` с полным URL через `esc($url, 'url')`.

## Как надо
- Полный URL в атрибуте — `esc($url, 'attr')` или просто `esc($url)`; `'url'` — только для одного сегмента пути внутри `base_url(...)`.
- После правки ссылок: `curl <page> | grep 'href='` — в значении не должно быть `%3A%2F%2F` (а `&#x3A;&#x2F;` — норма).

## Source
- 2026-05-27 · auto memory feedback_esc_url_vs_attr_href · retold, not a quote
