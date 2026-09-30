---
story: w2-n5-deeds-01
spec: w2-n5-deeds
status: done
returned: DONE
tier: 2
worker: worker-code
model: opus  # конкурентность трёх путей записи без смены обычного пути
wave: 1
blocked_by: []
---

# Старт квеста, выбор ветки и выдача ежедневок — в ядре под блокировкой строки персонажа

## Goal
Появляется `QuestStartService::start()` — тело `GenericQuestStartAction` без `chat_id`; им стартуют и generic-, и четыре легаси-кнопки. Старт, `QuestChainService::chooseBranch()` и `DailyTaskService::ensureAssigned()` проверяют и вставляют в транзакции под `SELECT … FOR UPDATE` строки персонажа: двойной тап не создаёт второй записи. Тексты бота прежние.

## Requirements
> 2. Из веба можно начать доступный квест и выбрать ветку цепочки. Повтор формы или двойной тап — в вебе и в боте — не создаёт второй записи `quest_steps`: проверка и вставка идут в ядре под блокировкой строки персонажа.
> 5. Выдача ежедневок (`ensureAssigned`) не создаёт дублей дня при одновременном заходе из бота и веба.

## Files
- app/Services/Quest/QuestStartService.php
- app/Services/Quest/QuestChainService.php
- app/Services/Quest/DailyTaskService.php
- app/Controllers/Telegram/Commands/Actions/Quest/GenericQuestStartAction.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestBranchChooseAction.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartExplore30Cells.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartExplore300Cells.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartExploreAllBiomes.php
- app/Controllers/Telegram/Commands/Actions/Quest/QuestStartFirstAidkitBasic.php
- tests/database/QuestStartServiceTest.php
- tests/database/QuestChainServiceTest.php
- tests/database/DailyTaskServiceTest.php
- phpstan-baseline.neon

## Non-goals
- Не вводить `UNIQUE` на `quest_steps` и не трогать схему.
- Не менять тексты и кнопки ответов бота.
- Не переносить экраны списков — это story 02.

## Map slice
memory/map/quests-events-npc.md · memory/map/website.md (нативные экраны /play)

## Acceptance criteria
- [ ] Два последовательных `start()` одного квеста: одна строка `quest_steps`, второй — код `already`; то же для `chooseBranch` (вторая ветка той же развилки не создаётся).
- [ ] Параллельный старт с двух соединений (приём `DBQuery`-слушателя из `BuildingUpgradeServiceTest`) — одна строка.
- [ ] `ensureAssigned` дважды подряд и параллельно — ровно `quests.daily.count` строк дня.
- [ ] Четыре легаси-кнопки и generic стартуют через `QuestStartService`; ответ бота байт в байт прежний (снимок до/после).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- `QuestStartService::start()` — тело бывшего `GenericQuestStartAction` (тексты отказов прежние), плюс поля
  `description`/`reward` сверх контракта `plan.md` — их рисует бот и нарисует веб. `claimFirstStep()` —
  единственный писатель первой строки `quest_steps` для generic и четырёх легаси-стартов: проверка и вставка
  в транзакции под `SELECT id FROM characters WHERE id = ? FOR UPDATE`.
- Легаси-старты сохранили свои быстрые проверки и тексты; «уже был запущен» теперь приходит и из ядра. Побочный
  эффект: Explore-кнопки раньше пропускали проверку для квеста выше уровня персонажа (он не попадал в
  «доступные») и вставляли вторую строку — теперь ядро отказывает прежним текстом. Персонаж в легаси-стартах —
  через `BaseAction::getUserAndCharacter()`, не `getCharacterIdByTelegramId($chatId)`.
- `chooseBranch`: проверка «сиблинг выбран» перенесена под блокировку (прежняя проверка до неё убрана, чтобы
  не читать дважды); тексты отказов — `QuestChainService::branchRefusalText()`, бот берёт их оттуда.
- `ensureAssigned`: быстрый путь без блокировки (зовётся из вебхука на каждый апдейт), затем блокировка и
  повторная проверка. `hasSetFor()` помечен `@phpstan-impure` — читает БД.
- Тест гонки пробует блокировку в момент **проверки**, не вставки: у `quest_steps` внешний ключ на
  `characters`, вставка сама берёт S-блокировку родителя, и проба на вставке зеленела без нашего кода
  (мутант выжил). Проба на проверке: мутант без `FOR UPDATE` краснеет — проверено для старта и ежедневок.
- `phpstan-baseline.neon` добавлен в `## Files`: четыре записи `offset 'level'` легаси-стартов стали
  несовпадающими (персонаж теперь из `getUserAndCharacter`) и удалены.
- Вердикты (Tier 0 бумаги нет, ask 6 брифа): /guide — нет, совет — нет; видимое поведение бота не меняется.

## Findings
