---
story: w2-n4-tails-03
spec: w2-n4-tails
status: done
returned: DONE
tier: 2
worker: worker-test
model: opus
wave: 3
blocked_by: []
---

# Часы троттлера заморожены в testThrottleBucketsArePerAccount

## Goal
Тест выбирает лимит ведра и проверяет 429 при замороженных часах троттлера, так же как соседний тест
429 после `b04e3c98`. Результат не зависит от скорости раннера.

## Requirements
> 4. testThrottleBucketsArePerAccount не зависит от скорости раннера: часы троттлера заморожены.

## Files
- tests/database/PlayControllerTest.php

## Non-goals
- Не трогать `AccountThrottleFilter` и `Config\WebPlay`.

## Map slice
none

## Acceptance criteria
- [ ] В тесте `Services::throttler(false)` с `setTestTime(time())`, внедрённый через `Services::injectMock('throttler', …)` до первого запроса.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes
- `testThrottleBucketsArePerAccount`: `Services::throttler(false)` + `setTestTime(time())` внедряется через `Services::injectMock()` до первого `before()` — тот же приём, что в тесте 429 (`b04e3c98`). Фильтр и конфиг не тронуты.
- Проверка: тест прогнан 20 раз подряд локально — зелёный; полный набор — в close-story.

## Findings
