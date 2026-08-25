---
story: community-chat-bot-43
spec: community-chat-bot
status: done
tier: 1
worker: drone-docs
tracer: false
wave: 10
blocked_by: [community-chat-bot-38, community-chat-bot-39, community-chat-bot-40, community-chat-bot-41]
---

# Ноты догоняют волны 9 и 10

## Goal
Tech-writing перестаёт описывать поведение, которого в коде уже нет.

## Requirements
> собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- C:/Projects/mmorpg-vault/tech-writing/controllers/CommunityController.md
- C:/Projects/mmorpg-vault/tech-writing/services/CommunityGuard.md
- C:/Projects/mmorpg-vault/tech-writing/services/CommunityAnswerMatcher.md
- C:/Projects/mmorpg-vault/tech-writing/commands/CommunityCleanup.md
- C:/Projects/mmorpg-vault/tech-writing/tasks/community/CommunityAutoReplyHandler.md
- C:/Projects/mmorpg-vault/tech-writing/services/CommunityIngestService.md

## Non-goals
- Не трогать код и тесты.
- Не переписывать ноты целиком — только разошедшиеся места.

## В чём дефект
Ревью волны 9 нашло четыре расхождения нот с кодом:

- `controllers/CommunityController.md:43` — «старше 72 часов (инцидент)». Константы больше нет:
  порог — доля от `community.question.max_age_hours`.
- `services/CommunityGuard.md` — рубеж 1 описан как «≥60% значимых слов», без окна
  анти-рекомбинации и без нового порога.
- `commands/CommunityCleanup.md` — ничего не знает про `COMMUNITY_QUESTION_AUTO_CLOSED`.
- `services/CommunityAnswerMatcher.md` — ничего не знает про `GroupAnonymousBot`.

Плюс изменения волны 10 (эта же волна): маршрут отказа в очереди, поведение заглушённого топика,
`is_bot`-фильтр на входе.

## Contract
- Порог называется КЛЮЧОМ настройки и отношением к нему, без дублирования числа
  (анти-дрейф: числа живут в GameSettings).
- Описание рубежа 1 соответствует поставленному коду, включая окно анти-рекомбинации, и НЕ
  публикует значения порогов как обещание — это антиабьюз-механика.
- `last_reviewed` обновлён, обратные ссылки на ADR-176 на месте.

## Acceptance criteria
- [ ] Четыре названных расхождения устранены.
- [ ] Изменения волны 10 отражены.
- [ ] Ни одна нота не дублирует число, живущее в настройках.

## Verification
Ноты PHPUnit не покрывает: проверка — сверка каждого исправленного абзаца с названным файлом кода.
