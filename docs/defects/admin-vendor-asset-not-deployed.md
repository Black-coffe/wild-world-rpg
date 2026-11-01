---
id: admin-vendor-asset-not-deployed
title: Страница ссылается на ассет из public/vendor, который не деплоится
status: text
check:
fixtures: []
keys: [графики пустые, apexcharts, 404 скрипта]
paths: ["app/Views/admin/**", "app/Views/site/**", "public/assets/js/**"]
---

# Страница ссылается на ассет из public/vendor, который не деплоится

`public/vendor/` целиком в `.gitignore`: локально файлы есть, на сервере — 404. Дашборд на ApexCharts
заполнял KPI, а графики молча не рисовались.

## Нельзя
- Подключать на новой странице скрипт или стиль из `public/vendor/**`.

## Как надо
- Внешнюю библиотеку класть в tracked `public/assets/js/admin/` и подключать до своего скрипта; перед ссылкой — `git check-ignore <path>`.
- Tier-2 смоук такой страницы смотрит сеть на 404 и что график реально нарисован.

## Source
- 2026-05-26 · auto memory feedback_admin_theme_vendor_not_deployed · retold, not a quote
