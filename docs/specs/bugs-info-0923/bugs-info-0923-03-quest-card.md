---
story: bugs-info-0923-03
spec: bugs-info-0923
status: done
returned: DONE
tier: 3
worker: worker-code
model: opus
tracer: false
wave: 1
blocked_by: []
---

# Карточка любого квеста из БД

## Goal
Тап по кнопке ЛЮБОГО квеста в списке `Quest/QuestsInfo.php` открывает его карточку, построенную из строки `quests`: название (`title_ru`), описание (`description`), минимальный уровень (`min_level`), награда (`reward_type` + `reward`), предшествующий квест (`prerequisite_quest`, если есть — его название). Кнопки: «назад к списку» и «◀️ Я». Сейчас карточки захардкожены для четырёх квестов (`Explore30Cells`, `ExploreAllBiomes`, `Explore300Cells`, `FirstAidkitBasic`), остальные молча перерисовывают список. Кнопки списка переходят на `questInfo_id<quests.id>`. Кнопки из старых сообщений `questInfo_<title_en>` тоже открывают карточку. Неизвестный квест → честный отказ с кнопкой «назад к списку». Формы — `## Contracts` в plan.md.

## Requirements
> Этот блок как то некорректно работает - тыкоешь на кнопку, сообщение заново приходит, такое же, без описания квеста
> Якщо ці баги в грі є, береш їх всі, плануєш через вулик-план і запускаєш починку, ремонт цих багів

## Files
- app/Controllers/Telegram/Commands/Actions/Quest/QuestsInfo.php
- tests/unit/Telegram/QuestInfoCardTest.php

## Non-goals
- `app/Config/CallbackRoutes.php` — только чтение: новая кнопка идёт под тем же префиксом `questInfo_`. Если маршрут точный, а не префиксный, — стоп и INTERFACES, файл не правится.
- Не разбирать `title_en` через `explode('_')`: остаток берётся после ПЕРВОГО префикса целиком.
- Не выдумывать кнопки действий («Начать квест» и т.п.) для квестов, у которых их не было. Если у четырёх прежних карточек были кнопки действия, они остаются этим квестам.
- «◀️ Я» — существующий callback экрана персонажа, который уже используют другие экраны. Новый маршрут не заводится.
- Не показывать `faction_id` и не менять состав/порядок списка, `QuestChainService`, `quest_steps`.
- Никаких чисел баланса в коде: награда и уровень — из строки БД.

## Map slice
`memory/map/quests-events-npc.md` — Entry points (`QuestModel`), Gotchas (двойной тап `questStart<TitleEn>`). `memory/map/telegram.md` — Gotchas (legacy Markdown, ноль одиночных кнопок в ряду).

## Acceptance criteria
- [ ] Квест вне прежних четырёх, по кнопке `questInfo_id<id>`: карточка содержит `title_ru`, `description`, `min_level`, награду и название предшествующего квеста, кнопки «назад к списку» и «◀️ Я» в одном ряду. Проверяет `QuestInfoCardTest` на схеме `quests`, которую тест строит сам (по миграции).
- [ ] Тот же квест по легаси-кнопке `questInfo_<title_en>` (включая `title_en` с `_`) открывает ту же карточку.
- [ ] Неизвестный id и неизвестный `title_en` → текст отказа и кнопка «назад к списку», а не список.
- [ ] Каждая кнопка списка и карточки: `strlen(callback_data) ≤ 64` — тест.
- [ ] Описание из БД не ломает разметку: `*`, `_`, `` ` `` в `description`/`title_ru` экранируются по текущему `parse_mode` (существующий `MarkdownSafe` или аналог) — тест с такими символами.
- [ ] Квест без `prerequisite_quest` — строки предшественника нет. Четыре прежних квеста открываются общей карточкой без регресса.
- [ ] Одиночный прогон зелёный: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Telegram/QuestInfoCardTest.php`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `QuestsInfo.php`: четыре захардкоженных `sendQuestInfo*` удалены; новые public static `cardTail()` (хвост после первого `questInfo_`), `buildCard()` (карточка/отказ из строки `quests`), `generateQuestKeyboard()` стала static и шлёт `questInfo_id<id>`. Кнопки карточки: «📜 К списку квестов» (`questInfo`) + «◀️ Я» (`character`) одним рядом. У прежних четырёх карточек кнопок действия не было — ничего не потеряно.
- Маршрут: `CallbackRoutes::$exactRoutes` матчит первый сегмент до `_`, так что `questInfo_id17` и `questInfo_<title_en>` идут в тот же класс — файл не тронут.
- Экранирование: `MarkdownSafe::name/text` (вырезают `*_`[]`` — legacy Markdown без backslash-эскейпа). Награда: `reward` + подпись типа (gold/experience/items как в ActiveQuests), число форматируется с пробелом тысяч.
- Вне `## Files`: из `phpstan-baseline.neon` удалены 10 записей про удалённые методы/старую сигнатуру `generateQuestKeyboard` (иначе `ignore.unmatched` роняет phpstan). Больше ничего.
- Тест требует миграции через `require_once` (файлы с датой не автозагружаются); `quests` создаётся/дополняется `prerequisite_quest` только если их нет, и откатывается в tearDown.
- Список (`title_ru` в тексте списка) по-прежнему не экранируется — вне скоупа; тот же класс бага, что и в карточке.

## Findings
