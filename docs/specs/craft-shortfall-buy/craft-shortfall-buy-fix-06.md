---
story: craft-shortfall-buy-fix-06
spec: craft-shortfall-buy
status: todo
tier: 3
worker: worker-code
tracer: false
wave: 2
blocked_by: [craft-shortfall-buy-fix-01, craft-shortfall-buy-fix-02]
---

# Справочник: привести абзац в соответствие с тем, что действительно работает

## Goal
Абзац `/guide` обещает кнопку «Докупить и собрать» и наценку за срочность. После ремонта обе вещи
станут правдой; проверить и, если что-то из них изменилось по ходу починки, привести текст в
соответствие. Обещание, которое расходится с игрой, хуже отсутствия абзаца.

## Requirements
> и процент налога за опт указываем в тексте, игрок должен видеть и понимать чего не хватает и сколько за это придлется заплатить!

## Files
- app/Services/Onboarding/GuideCatalog.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php

## Non-goals
- Не заводить новый раздел справочника.
- Не писать числа наценки и лимитов: они live-tunable.
- Не переписывать раздел целиком — только сверка и точечная правка.

## Map slice
memory/map/onboarding.md; ADR-127 GUIDE-COVERAGE.

## Acceptance criteria
- [ ] Каждое утверждение абзаца сверено с текущим поведением кода: названная кнопка существует, названная наценка списывается.
- [ ] Правка ложного обещания про перепродажу не пострадала.
- [ ] Инварианты справочника целы: только текст, самодостаточно без картинки, парные звёздочки, ключ раздела прежний.
- [ ] Слова «опт» в тексте нет.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/`

## Notes
Полный разбор находок — `docs/specs/craft-shortfall-buy/review-findings.md`. Читай его, прежде чем править. Мелкая 16. Делать ПОСЛЕ fix-01 и fix-02, иначе сверять не с чем.

## Implementation notes

## Findings
