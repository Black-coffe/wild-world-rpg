---
story: w-reg-banner-01
spec: w-reg-banner
status: done
returned: DONE
tier: 1
worker: worker-code
wave: 1
blocked_by: []
---

# Плашка «Новое сообщение»: сообщения свёрнуты в строки

## Goal
В `#play-banner` каждое сообщение — `<details class="play-banner-item" data-message-id>` с `<summary>` в одну строку
(первая строка текста/подписи без разметки, ~80 символов, иначе «Сообщение»); внутри — прежний полный рендер
(фото, подпись, текст, кнопки `/play/act` с `message_id`). Опрос, заменяя плашку, оставляет раскрытыми те же
`message_id`. На 375 px плашка с тремя сообщениями не выталкивает экран игры за первый экран.

## Files
- app/Views/site/_play/banner.php
- public/assets/css/wildworld-ui.css
- public/assets/js/wildworld-play.js
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- app/Views/site/play.php
- tests/database/PlayInboxBannerTest.php

## Non-goals
- Не трогать `WebInboxService::banner`, `WebPlay::$bannerMax`, «ещё N», «Скрыть» и маршруты `/play/inbox*`.
- Не менять ленту входящих 🔔 и экран игры `.play-msg` вне плашки.
- Не делать раскрытие/сворачивание через JS: `<details>` работает и без него, JS только сохраняет состояние.

## Map slice
`memory/map/website.md` — «Игра на сайте (ADR-189)», плашка `#play-banner`.

## Acceptance criteria
- [ ] HTML `/play` с непрочитанными: у каждого сообщения плашки `<details>` + `<summary>` с первой строкой текста; полный
      текст и кнопка с `name="message_id"` по-прежнему в HTML (без JS).
- [ ] Сообщение только с фото без текста и подписи → summary «Сообщение»; разметка (`*`, `_`, теги) в summary не попадает.
- [ ] После замены плашки опросом `<details>` с тем же `data-message-id` остаётся `open`.
- [ ] CSS — токены, 0 радиусов, 0 теней; свёрнутая плашка в `public/ui-kit.html`; CSS `?v=17`, JS `?v=5`.
- [ ] Тест в `PlayInboxBannerTest`: summary несёт первую строку и fallback «Сообщение».

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayInboxBannerTest.php`

## Implementation notes
- `banner.php`: каждое сообщение в `<details class="play-banner-item" data-message-id>`; summary — первая непустая строка
  `text`, иначе `caption` (через `TelegramMarkupRenderer::toHtml` → strip_tags, `<br>` = перенос), `mb_strimwidth` 80, «Сообщение».
- CSS `.play-banner-item/-summary/-line` (▸/▾, nowrap+ellipsis, 44 px) в `wildworld-ui.css` и инлайн в `ui-kit.html`
  (образец: одно раскрытое, одно свёрнутое); `?v=17` CSS, `?v=5` JS.
- JS: `setBanner` переносит `open` по `data-message-id`; `openNewest` раскрывает самое новое на ≥768 px один раз на id
  (решение владельца в сборке, plan deltas). JS PHPUnit не покрывает — проверка на Tier-2 смоуке preprod.
- Тест `testBannerMessagesAreCollapsedToOneLineSummary`: Markdown-разметка срезана, ведущая пустая строка пропущена, fallback.

## Findings
