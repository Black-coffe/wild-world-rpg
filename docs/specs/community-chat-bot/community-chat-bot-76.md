---
story: community-chat-bot-76
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 21
blocked_by: []
---

# Очередь владельца видит то же, что обрабатывает тик

## Goal
Эскалированное прямое обращение к боту перестаёт быть невидимым для владельца.

## Requirements
> чтобы была живая группа, потому что я не всегда успеваю, не всегда могу

## Files
- app/Controllers/Admin/CommunityController.php
- app/Models/CommunityMessageModel.php
- app/TaskHandlers/Community/CommunityAutoReplyHandler.php
- tests/unit/Controllers/Admin/CommunityControllerTest.php

## В чём дефект
Нашёл ревьюер. Тик берёт `is_question=1 OR addressed_to_bot=1` (`CommunityAutoReplyHandler.php:178-186`,
story 57), а очередь `/admin/community` — только `is_question=1` (`CommunityController.php:754-758`).

Сценарий: игрок пишет «Роби помоги» — ни вопросительного знака, ни вопросительного слова, значит
`is_question=0`, `addressed_to_bot=1`. Тик такую строку обрабатывает и, если гвард отказал,
переводит в `escalated` — то есть «передал владельцу». Но владельцу она не показывается ни в
очереди, ни в счётчиках зависших. Вопрос молча исчезает между двумя выборками.

Это зеркало уже закрытой находки §17 №8 («очередь показывала весь чат как открытые вопросы»):
фильтр поставили, и он стал прятать часть эскалаций.

Инвариант: **строка, которую тик имеет право обработать, обязана быть видима владельцу, если
она эскалирована.** Проще всего это выразить, взяв в очереди то же условие, что в тике, — тогда
два места перестанут расходиться.

## Non-goals
- Не менять выборку тика — она верна.
- Не показывать в очереди весь чат: закрытая находка §17 №8 должна остаться закрытой, фильтр по статусу сохраняется.
- Не трогать метрики (story 59) и гвард.

## Acceptance criteria
- [ ] Строка `is_question=0, addressed_to_bot=1` в статусе `escalated` видна в очереди `/admin/community` и попадает в счётчик зависших.
- [ ] Обычная болтовня (`is_question=0, addressed_to_bot=0`) в очередь по-прежнему не попадает.
- [ ] Условие «строка адресована боту или похожа на вопрос» живёт **в одном месте** — скоуп-метод `CommunityMessageModel`; и очередь, и тик зовут его, а не повторяют условие. Расхождение становится невозможным, а не маловероятным.
- [ ] В тике трогается только строка выборки — ничего больше.
- [ ] Тест краснеет на возврате прежнего фильтра.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Controllers/Admin/CommunityControllerTest.php`

## Implementation notes

- **Первая версия** (текстовая канарейка вместо общего источника) заменена по замечанию
  лида на сильный вариант — общий скоуп-метод. `## Files` расширен лидом: добавлены
  `app/Models/CommunityMessageModel.php` и `app/TaskHandlers/Community/CommunityAutoReplyHandler.php`.
- `app/Models/CommunityMessageModel.php` — новый публичный метод
  `whereAddressedOrQuestion(): self` (`groupStart()->where('is_question', 1)
  ->orWhere('addressed_to_bot', 1)->groupEnd()`) — единый источник условия «строка
  адресована боту или похожа на вопрос».
- `app/Controllers/Admin/CommunityController.php` — `openQuestionsBuilder()` зовёт
  `->whereIn('status', ['new', 'escalated'])->whereAddressedOrQuestion()` вместо
  повтора условия текстом. Докблоки (класс + метод) обновлены — ссылаются на общий
  метод модели, а не на «зеркалим текст тика».
- `app/TaskHandlers/Community/CommunityAutoReplyHandler.php` — тронута ТОЛЬКО строка
  выборки в `handle()` (`:178`): `->groupStart()->where('is_question', 1)
  ->orWhere('addressed_to_bot', 1)->groupEnd()` заменено на
  `->whereAddressedOrQuestion()`, добавлен однострочный комментарий-ссылка на story 76.
  Больше ничего в файле не менялось (Non-goal «в тике трогается только строка выборки»).
- Канарейка `testQueueQuestionSelectorMirrorsTickSelector()` **удалена** — с общим
  источником правды она избыточна (расхождение структурно невозможно), а source-scan
  тесты в этой спеке уже дважды оставались зелёными при сломанном методе (урок
  `feedback_source_scan_tests_are_not_coverage`). Три поведенческих теста (реальное
  покрытие) остались: `testOpenQuestionsIncludesEscalatedAddressedToBotNonQuestion`,
  `testOpenQuestionsStillExcludesChatterNotAddressedToBot`,
  `testStaleOpenQuestionsIncludesEscalatedAddressedToBotNonQuestion`.
- Прогнал `vendor/bin/phpunit --no-coverage --no-progress
  tests/unit/Controllers/Admin/CommunityControllerTest.php` — 31/31 зелёные (лид
  разрешил). Дополнительно (файл теперь в `## Files` и напрямую затронут правкой
  строки выборки) прогнал в изоляции `vendor/bin/phpunit --no-coverage --no-progress
  tests/unit/TaskHandlers/CommunityAutoReplyHandlerTest.php` — 33/33 зелёные, ничего
  не сломано. Полный `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` —
  0 ошибок.

## Findings
