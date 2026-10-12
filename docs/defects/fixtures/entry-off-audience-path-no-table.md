# Блок «почему так вышло» (plan без таблицы аудитории — поправка владельца 2026-10-12)

Соседняя форма: план трогает бота и публичную страницу боя, но не записывает, где бывает аудитория и где вход.
Прежняя проверка такой план пропускала молча.

## Stories
- `example-01` — блок в боте

## Files
- app/Controllers/Telegram/Commands/Actions/PVP/BattleJournalAction.php
- app/Services/PVE/PvpOutcomeExplainer.php

**Approved:**
**Shipped:**
