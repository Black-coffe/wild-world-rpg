---
story: community-chat-bot-34
spec: community-chat-bot
status: todo
tier: 1
worker: drone-docs
tracer: false
wave: 9
blocked_by: []
---

# Нота на команду очистки — tech-writing contract

## Goal
У новой CLI-команды появляется нота рядом с сёстрами, как требует конституционное правило
tech-writing.

## Requirements
> собирал информацию и чтобы где-то ее агрегировал, сохранял

## Files
- C:/Projects/mmorpg-vault/tech-writing/commands/CommunityCleanup.md

## Non-goals
- Не трогать код команды и её тесты.
- Не переписывать соседние ноты.

## В чём дефект
`App\Commands\CommunityCleanup` появилась в волне 8, а ноты нет — у соседей `CommunityExport.md`
и `CommunityImport.md` они есть. Story 22 не имела ноты в `## Files`, а Закон 3 запрещал воркеру
писать её самовольно.

## Contract
- Нота по шаблону соседних команд: назначение, триггер (расписание `Config\Tasks`), параметры,
  что удаляет и что НЕ трогает, связанные настройки, обратные ссылки.
- `last_reviewed: 2026-08-25`, ссылка на ADR-176.
- Порог зависших и TTL описаны как ссылка на ключи настроек, без дублирования чисел
  (анти-дрейф: числа живут в GameSettings, а не в вики).

## Acceptance criteria
- [ ] Нота создана, frontmatter заполнен, стоят обратные ссылки на ADR-176 и соседние ноты.
- [ ] Числа порогов не продублированы — названы ключи настроек.

## Verification
Ноты PHPUnit не покрывает: проверка — чтение файла и сверка структуры с `CommunityExport.md`.
