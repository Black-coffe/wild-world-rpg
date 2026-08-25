---
story: community-chat-bot-50
spec: community-chat-bot
status: todo
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
- [ ] Противоречие между двумя нотами устранено.
- [ ] Единица записи маршрута названа верно.
- [ ] Ни одна нота не дублирует числа, живущие в настройках, и не публикует пороги гварда.

## Verification
Ноты PHPUnit не покрывает: проверка — сверка каждого исправленного абзаца с названным файлом кода.
