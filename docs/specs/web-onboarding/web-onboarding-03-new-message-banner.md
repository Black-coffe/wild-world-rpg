---
story: web-onboarding-03
spec: web-onboarding
status: todo
returned:
tier: 3
worker: worker-code
model: sonnet
wave: 2
blocked_by: [web-onboarding-02]
---

# Плашка нового сообщения над экраном /play и подхват новой ревизии экрана

## Goal
Непрочитанные сообщения входящих (те, что пришли вне запроса: крон → `WebDelivery::route`) видны плашкой над экраном
`/play` целиком: текст или caption, фото по желанию, все кнопки, которые работают как из входящих. Показываются
новейшие `Config\WebPlay::bannerMax` (= 3); сверх лимита — строка «ещё N — во входящих 🔔». Плашка рендерится сервером
при загрузке `/play` (работает без JS) и приходит в ответе опроса `/play/inbox` (30 с). Опрос получает от клиента
`rev` экрана. Если на сервере `WebScreenStore::revision()` другая, он отдаёт и новый экран: так итог задачи из story 02
появляется без перезагрузки. Сообщения, чей `message_id` уже стоит на экране (`screenMessageIds()`), в плашку не
попадают. «✕ Скрыть» и открытие 🔔 помечают всё прочитанным через существующий `/play/inbox/read`.

## Requirements
> 3. Шаги обучения (закрытый шаг, награда, следующий шаг) — не только во входящих: новое сообщение появляется плашкой над экраном, целиком и с кнопками.
> 6. Экран читается в media-off: приветствие, итог задачи и плашка нового сообщения несут весь смысл текстом; в веб-текстах нового первого экрана нет бот-лексики («ниже», «нижнее меню», «команда /…»).

## Files
- app/Services/Web/WebInboxService.php
- app/Controllers/Play.php
- app/Config/WebPlay.php
- app/Views/site/_play/banner.php
- app/Views/site/play.php
- public/assets/wildworld-play.js
- public/assets/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- tests/database/PlayInboxBannerTest.php

## Non-goals
- Не трогать `WebScreenStore`, `WebDelivery`, `WebActService`, `Worker`: состояние и замену экрана пишет story 02. Отсюда
  — только чтение через `revision()` / `screenMessageIds()` из контракта.
- Не менять интервал опроса и не делать push или SSE. Не менять вьюху и разметку входящих 🔔, кроме общего партиала
  сообщения, если он уже есть.
- Не заводить колонку «показано плашкой»: плашка живёт на флаге прочтения.
- Не трогать нативные экраны и док.
- Если главная вьюха `/play` называется не `app/Views/site/play.php`, стоп и INTERFACES, а не правка чужого файла.

## Map slice
`memory/map/website.md` → «Игра на сайте (ADR-189)», Key types / contracts (flat-stencil), Gotchas (CSRF
`regenerate`, `?v=` в `meta.php`, callback только по `message_id`); `.claude/rules/web-public.md`;
`docs/specs/web-onboarding/recon.md` → «Веб-доставка» (опрос `Play::inbox()` :637, `WebPlay::inboxPollSeconds`).

## Acceptance criteria
- [ ] `GET /play` с непрочитанными фоновыми сообщениями рендерит плашку над экраном без JS: каждое сообщение целиком,
      с кнопками; при фото caption несёт весь смысл (media-off).
- [ ] Ответ `/play/inbox` несёт `banner_html` и `rev`. При `rev` клиента ≠ серверному — ещё `screen_html` текущего экрана.
      JS заменяет экран и плашку без перезагрузки и берёт свежий `csrf`, как сейчас.
- [ ] Тап по кнопке в плашке идёт тем же путём, что тап во входящих (`/play/act` с `message_id` этого сообщения), и
      отрабатывает.
- [ ] Сообщение, чей `message_id` уже на экране, в плашке не показано. Правка `upsertEdit` обновляет ту же карточку
      плашки, новой не добавляет.
- [ ] Больше `bannerMax` непрочитанных — показаны новейшие, плюс строка «ещё N — во входящих 🔔». `bannerMax` — в
      `Config\WebPlay` с комментарием «UI-лимит, не баланс».
- [ ] «✕ Скрыть» и открытие 🔔 помечают прочитанным, плашка исчезает и после перезагрузки не возвращается.
- [ ] Стиль плашки — только токены `wildworld-ui.css`: 0 радиусов, 0 теней, палитра-переменные, шрифты дизайн-системы.
      Компонент сначала добавлен в `public/ui-kit.html`, `?v=` поднят в `meta.php` (и у `wildworld-play.js`, если
      версия там). В новых строках интерфейса нет бот-лексики («ниже», «меню бота», «команда /…»).
- [ ] Тест на БД: выборка плашки (лимит, исключение экранных `message_id`, прочитанные не идут) и JSON опроса
      (`banner_html`, `rev`, `screen_html` только при расхождении).
- Media-off: тест рендера показывает, что плашка несёт весь смысл текстом/caption без фото (ask 6).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
