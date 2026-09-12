---
story: bugs-thread-triage-12
spec: bugs-thread-triage
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 5
blocked_by: [bugs-thread-triage-09]
---

# Ремонт: доказательства, которые шире того, что реально проверено

## Goal

Три находки MINOR в трёх файлах вердиктов. Сами вердикты устояли — правится формулировка
доказательств, обещающая больше сделанного, и один незаписанный хвост класса багов.

## Requirements

> Если да, пишешь ответ, что данная система устранена, бага нет.

> смотришь: мы эту систему поправили, баг устранили?

## Files
- docs/specs/bugs-thread-triage/verdicts/craft.md
- docs/specs/bugs-thread-triage/verdicts/bases.md
- docs/specs/bugs-thread-triage/verdicts/consumables.md

## Что делать

**1. `craft.md`, mid `4294974610` — у починки есть непереехавшие братья (MINOR-9).**
`UtilityRecipePreviewT3Action.php:251` перешёл на `quantityRows()`, а `MedicalRecipePreviewT3Action.php:235`,
`ArmorRecipePreviewT3Action.php:273` и `WeaponRecipePreviewT3Action.php:268` до сих пор жёстко
печатают «🛠 Скрафтить 1 шт» с колбэком на одну штуку. Медицина T3 — тот же стакающийся класс,
что инструменты из жалобы. Черновик сужен до двух названных предметов, поэтому ложного в чат не
уходит — но хвост нигде не записан. Либо назови эти семейства незакрытым хвостом класса, либо
объясни в блоке, почему они к классу не относятся. Правило проекта прямое: class-of-bug
проверяется грепом по соседним классам.

**2. `bases.md` — заявление о регрессе шире теста (MINOR-10).** Написано «не даёт этому классу
бага повториться». `tests/unit/Camp/BuildingHandlerCoverageTest.php` сканирует исходник хендлера
на метки `case` — он ловит **отсутствующую** ветку, но не ветку, которая есть и ничего не рисует.
Это ровно урок `feedback_source_scan_tests_are_not_coverage`. Сузь формулировку до того, что тест
действительно гарантирует.

**3. `consumables.md`, mid `4294974461` — проверено на шаг уже, чем сказано (MINOR-12).**
Прогон доказал, что **сохранённые** статы складываются верно. Но симптом, который показал игрок, —
это напечатанное значение «до» в сообщении второго препарата (скриншот `4294974462`); отрендеренного
текста никто не смотрел. Либо скажи, что проверено состояние в БД, а не экран, либо посмотри экран.
«Закрывает наблюдаемый исход» — утверждение про экран, а экран не смотрели.

## Non-goals
- Не менять сами вердикты — все три устояли под ревью.
- Не трогать `REPORT.md` (story 14) и чужие файлы вердиктов.
- Не писать новых тестов и не чинить код.

## Map slice
`memory/map/craft.md`, `memory/map/bases.md`, `memory/map/player.md`

## Acceptance criteria
- [ ] Хвост T3-семейств назван либо обоснованно исключён из класса.
- [ ] Заявление о регрессе в `bases.md` сужено до того, что тест проверяет.
- [ ] Доказательство `4294974461` называет, что именно наблюдали — состояние или экран.

## Verification
`bash docs/specs/bugs-thread-triage/verdict-lint.sh docs/specs/bugs-thread-triage/verdicts/craft.md && bash docs/specs/bugs-thread-triage/verdict-lint.sh docs/specs/bugs-thread-triage/verdicts/bases.md && bash docs/specs/bugs-thread-triage/verdict-lint.sh docs/specs/bugs-thread-triage/verdicts/consumables.md`

## Implementation notes

## Findings
