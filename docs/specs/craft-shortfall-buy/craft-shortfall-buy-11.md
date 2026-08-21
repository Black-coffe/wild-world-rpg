---
story: craft-shortfall-buy-11
spec: craft-shortfall-buy
status: done
tier: 1
worker: worker-code
tracer: false
wave: 4
blocked_by: [craft-shortfall-buy-08, craft-shortfall-buy-09]
---

# Совет дня про докупку недостающего

## Goal
В `game_tips` появляется совет категории «крафт», который рассказывает живым игрокам, что тупик «не хватает одного материала» больше не тупик, и называет путь до него.

## Requirements
> и процент налога за опт указываем в тексте, игрок должен видеть и понимать чего не хватает и сколько за это придлется заплатить!

## Files
- app/Database/Migrations/2026-08-21-110000_SeedCraftShortfallBuyTip.php

## Non-goals
- Не заводить дубль существующего совета про торговца — новый ракурс, а не пересказ.
- Не писать числа наценки: они меняются из админки.
- Не использовать слово «опт».
- Не трогать `Config\WipeManifest`.

## Map slice
memory/map/onboarding.md — «Совет дня»; TIPS-COVERAGE.

## Acceptance criteria
- [ ] Категория — «крафт», одна из четырнадцати допустимых; иначе валидатор отклонит запись.
- [ ] Миграция идемпотентна по `title_en`.
- [ ] Текст называет путь: крафт → карточка предмета → когда не хватает материалов.
- [ ] Тон Роби, разметка парная, эмодзи безопасны для колонки.
- [ ] Смысл полностью в тексте — совет не опирается на картинку.

## Verification
`php -l app/Database/Migrations/2026-08-21-110000_SeedCraftShortfallBuyTip.php`

## Implementation notes

## Findings
