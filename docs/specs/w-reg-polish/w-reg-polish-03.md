---
story: w-reg-polish-03
spec: w-reg-polish
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
---

# 375 px: экран игры в первом экране `/play`, h1 главной в контейнере

## Goal
На узком экране (≤ 375–480 px) статус-карточки HUD и док `/play` ужаты (компактнее ряды/кнопки, без пустой полосы
над именем), и экран игры начинается в первом экране. h1 hero главной («Мир рухнул. Твоя история — только начинается.»)
и lead помещаются в контейнер без обрезки и горизонтальной прокрутки. На 768 и 1440 вид не меняется.

## Requirements
> 4. /play на 375 px: экран игры виден сразу, пустой полосы над именем нет.
> 5. Главная на 375 px: заголовок и подзаголовок не режутся.
> **F14. 375 px:** статус-карточки и док (8 кнопок) занимают почти весь первый экран, на сам экран игры остаётся
> 9. (ворота проекта) Сайт и `/play` — только токены `wildworld-ui.css` (0 радиусов, 0 теней), без новых компонентов вне `public/ui-kit.html`; Tier-2 смоук главной и `/play` на 1440/768/375: нет горизонтальной прокрутки, h1 и lead главной целиком в контейнере, на 375 в `/play` экран игры начинается в первом экране, консоль чистая; на 1440/768 вид прежний.

## Files
- public/assets/css/wildworld-ui.css
- app/Views/site/_play/hud.php
- app/Views/site/_play/dock.php
- app/Views/site/home.php
- app/Views/site/_layout/meta.php
- public/ui-kit.html

## Non-goals
- Не менять состав и порядок кнопок дока и карточек HUD, не прятать их за «ещё» — только плотность на узком экране.
- Не менять тексты hero (W-REG.4 правит meta главной отдельно).
- Не трогать вьюхи `native_*.php` и вёрстку ≥768 px.

## Map slice
`memory/map/website.md` (`/play`, сайт), `.claude/rules/web-public.md`.

## Acceptance criteria
- [ ] 375×812: в `/play` верх экрана игры (`.play-screen`) виден без прокрутки; полосы пустоты над именем в HUD нет.
- [ ] 375 px: `document.documentElement.scrollWidth <= 375` на главной и в `/play`; h1 и lead главной не выходят за контейнер.
- [ ] 1440 и 768: скриншоты главной и `/play` совпадают с прежними по раскладке.
- [ ] CSS подключён с обновлённым `?v=`; новые правила — только токены, 0 радиусов, 0 теней; изменённый компонент отражён в `public/ui-kit.html`.

## Verification
`curl -sS -o /dev/null -w '%{http_code}' http://mmorpg.test/`

## Implementation notes

## Findings
