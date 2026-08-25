---
story: community-chat-bot-50
spec: community-chat-bot
status: done
tier: 1
worker: drone-docs
tracer: false
wave: 11
blocked_by: [community-chat-bot-45, community-chat-bot-46]
---

# Две ноты перестают описывать взаимоисключающие поведения

## Goal
Вики перестаёт утверждать одновременно, что реплай анонимного админа отменяет выдержку и что он
до таблицы не доходит.

## Requirements
> собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- C:/Projects/mmorpg-vault/tech-writing/services/CommunityAnswerMatcher.md
- C:/Projects/mmorpg-vault/tech-writing/services/CommunityIngestService.md
- C:/Projects/mmorpg-vault/tech-writing/tasks/community/CommunityAutoReplyHandler.md

## Non-goals
- Не трогать код и тесты.
- Не переписывать ноты целиком — только разошедшиеся места.

## В чём дефект
1. `CommunityAnswerMatcher.md` описывает отмену выдержки реплаем `GroupAnonymousBot` как живое
   поведение, а `CommunityIngestService.md` — фильтр, который делает его недостижимым, и вдобавок
   называет story 35 «тем же классом дефекта», хотя story 35 постановила обратное. Обе ноты
   написаны в одной волне и противоречат друг другу.
2. `CommunityAutoReplyHandler.md` пишет «собственная запись `COMMUNITY_ROUTE_LOGGED` на каждый
   отказ гварда» — запись идёт на каждый ОТКАЗ, а не на каждую строку склейки.

## Contract
- Обе ноты описывают одно поведение — то, которое осталось после ремонта story 45.
- Нота хендлера называет единицу, на которую пишется запись, — после ремонта story 46.
- Актуальное состояние берётся из кода на develop, а не из текста story.

## Acceptance criteria
- [x] Противоречие между двумя нотами устранено.
- [x] Единица записи маршрута названа верно.
- [x] Ни одна нота не дублирует числа, живущие в настройках, и не публикует пороги гварда.

## Verification
Ноты PHPUnit не покрывает: проверка — сверка каждого исправленного абзаца с названным файлом кода.

## Implementation notes
- Сверка с кодом на develop (`CommunityIngestService.php:129`, `CommunityAnswerMatcher.php:196-221`,
  `CommunityAutoReplyHandler.php:449-495`) подтвердила: `CommunityAnswerMatcher.md` уже описывал
  актуальное поведение (реплай `GroupAnonymousBot` отменяет выдержку всегда, кроме случая когда
  сам автор вопроса — тот же анонимный id) и правки не потребовал.
- `CommunityIngestService.md` — реальное противоречие было не в статусе фильтра (текст уже верно
  описывал исключение для `GroupAnonymousBot` post-story-45), а в одной фразе, ложно называвшей
  проблему постороннего бота «тем же классом дефекта, что чинила story 35»: story 35 решила
  прямо противоположное («`is_bot=true` не значит не-человек» — для `GroupAnonymousBot`), а
  фильтр story 41/45 нужен ровно для обратного случая («`is_bot=true` значит не-человек» — для
  ЛЮБОГО другого бота). Переформулировано как «обратная сторона того же вопроса», без ложного
  отождествления с решением story 35.
- `CommunityAutoReplyHandler.md` — «собственная запись `COMMUNITY_ROUTE_LOGGED` на каждый отказ
  гварда» заменено на единицу записи после ремонта story 46: запись пишется на КАЖДУЮ строку
  склейки дублей (`escalateGuardDenial()`, `logRoute()` в цикле по `$coveredIds`), одной
  транзакцией со сменой статуса — сбой вставки откатывает и статус. Правка внесена в двух местах
  ноты (`## Поведение` п.5 и `## Side effects`).
- Числа настроек и пороги гварда не публиковались и не тронуты ни в одной из трёх нот.
