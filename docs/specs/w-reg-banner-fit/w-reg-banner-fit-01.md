---
story: w-reg-banner-fit-01
spec: w-reg-banner-fit
status: done
returned: DONE
tier: 1
worker: worker-code
wave: 1
blocked_by: []
---

# Авто-раскрытие новейшего сообщения плашки — только если экран игры остаётся в ¾ окна

## Goal
`openNewest` раскрывает новейшее `<details>` в живом DOM, меряет верх `#play-state` и сворачивает обратно, если он ниже
¾ `innerHeight`. В `setBanner` вызов — после `replaceWith`. JS `?v=6`.

## Files
- public/assets/js/wildworld-play.js
- app/Views/site/play.php
- tests/database/PlayInboxBannerTest.php

## Non-goals
- Не трогать `banner.php`, CSS, отбор сообщений, перенос раскрытого игроком.
- Не ограничивать высоту раскрытого сообщения (scroll внутри scroll).

## Map slice
`memory/map/website.md` — плашка `#play-banner`.

## Acceptance criteria
- [ ] 1440×900 preprod, новейшее длинное: свёрнуто, `#play-state` в верхних ¾ окна; короткое — раскрыто.
- [ ] 375: ничего не раскрыто; раскрытое игроком переживает опрос.
- [ ] `/play` подключает `wildworld-play.js?v=6` (тест страницы).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayInboxBannerTest.php`

## Implementation notes
- `openNewest`: раскрыть → замер `stateBox.getBoundingClientRect().top + scrollY` против `innerHeight*0.75` → свернуть,
  если ниже; `autoOpened` помечается до замера, повторно не пробует. `#play-state` меняет только `innerHTML` — ссылка живая.
- `setBanner`: `replaceWith` перед `openNewest`, чтобы замер шёл по живой раскладке. JS `?v=6`, тест страницы на `?v=6`.

## Findings
