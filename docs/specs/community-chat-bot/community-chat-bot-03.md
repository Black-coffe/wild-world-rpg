---
story: community-chat-bot-03
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Килсвитчи, пороги и лимиты в GameSettings

## Goal
Всё поведение бота в чате управляется из админки без релиза. Главное — ручка отката
`community.autoreply.enabled`, которой владелец обязан уметь воспользоваться в три часа
ночи с телефона.

## Requirements
> Тут нужно спланировать, как он будет отвечать: автоматически, в ручном режиме, полуавтоматически

## Files
- app/Database/Migrations/2026-08-25-100200_Adr176CommunityGameSettings.php
- tests/unit/Services/GameSettings/CommunitySettingsSeedTest.php

## Non-goals
- Не читать эти ключи в коде — читатели появляются в story 05–09.
- Не заводить ключей «на будущее»: каждый ключ обязан иметь читателя в волнах 1–3,
  иначе это мёртвая настройка.
- Не трогать `GameSettingsController` и вьюхи админки — экран уже умеет рисовать категорию.

## Map slice
`memory/map/admin.md` §GameSettings. Образец файла — 
`app/Database/Migrations/2026-07-24-100000_Adr110AchievementRewardsGameSettings.php`
(идемпотентность по `countAllResults()===0`, `down()` через `whereIn()->delete()`).

## Contract (из plan.md)
Категория для всех — `experimental` (подсистема новая, не игровой баланс).

| Ключ | Тип | Стартовое значение | Зачем |
|---|---|---|---|
| `community.enabled` | bool | `false` | Общий рубильник подсистемы |
| `community.chat_id` | string | `''` | Целевая супергруппа; пусто = выключено |
| `community.autoreply.enabled` | bool | `false` | **Ручка отката.** День 1–3 бот нем |
| `community.autoreply.delay_seconds` | int | `75` | Выдержка полосы B (60–90 по плану) |
| `community.autoreply.max_per_hour_per_topic` | int | `5` | Потолок дня 6 |
| `community.autoreply.author_cooldown_seconds` | int | `600` | Кулдаун на автора, не только на топик |
| `community.autoreply.max_answer_chars` | int | `600` | Простыня гайда в чате хуже молчания |
| `community.autoreply.silent_topics` | string | `''` | Топики, где бот молчит всегда |
| `community.match.threshold_addressed` | float | `0.45` | Полоса A — низкий порог |
| `community.match.threshold_overheard` | float | `0.80` | Полоса B — заметно выше |
| `community.answer.max_age_days` | int | `90` | Срок годности записи банка |
| `community.question.max_age_hours` | int | `48` | Старше — публично не отвечаем |
| `community.ingest.max_questions_per_author_per_hour` | int | `5` | Анти-флуд на входе в очередь |
| `community.retention_days` | int | `30` | Окно хранения сырого текста |
| `community.moderation.mode` | string | `shadow` | `off` / `shadow` / `live` |

Каждая запись обязана нести `rationale_text`, `above_effect_text`, `below_effect_text`,
`recommended_min/max`, `hard_min/max` — без них конституция запрещает сохранение.

## Acceptance criteria
- [ ] Миграция идемпотентна: повторный `up()` не создаёт дублей.
- [ ] `down()` удаляет ровно эти ключи и ничего больше.
- [ ] Ни одна запись не имеет пустого `rationale_text` / `above_effect_text` / `below_effect_text`.
- [ ] `community.enabled` и `community.autoreply.enabled` стартуют в `false` — включение
      только руками владельца, по расписанию обкатки §12 плана.
- [ ] `hard_min/max` заданы для всех числовых ключей: `delay_seconds` не может стать
      отрицательным, `max_per_hour_per_topic` — не больше разумного потолка.
- [ ] Тест перечисляет ожидаемые ключи поимённо и падает, если ключ пропал или добавился
      незадокументированный.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/GameSettings/`

## Implementation notes

Миграция `Adr176CommunityGameSettings` сеет 15 ключей категории `experimental`
(таблица контракта в story — 15 строк, не 14, как я по ошибке сначала написал в
докблоке; поправлено). Все killswitch/строковые ключи держат `hard_min/max = null`
(bool и строки без числовых границ, как у `achievement.rewards_enabled` в примере),
у всех 10 числовых ключей заданы `hard_min/hard_max`. `effect_text` тоже заполнен
у каждой записи, хотя в story явно требовались только rationale/above/below —
колонка NOT NULL в реальной схеме `game_settings`, без неё `insert()` упал бы.

Тест инстанцирует класс миграции напрямую (`require_once` файла — он не грузится
PSR-4 автозагрузчиком composer из-за date-префикса в имени файла) с `Forge` на
группу `tests`, реально гоняет продовый `up()`/`down()` против вручную созданной
таблицы `game_settings` (схема — копия `CreateGameSettingsTable`, NOT NULL на
текстовых rationale-колонках) — паттерн `$migrate=false` + ручное `CREATE TABLE`
взят из `AchievementServiceTest`/`RobotReachSingleSourceTest` (в этом наборе
`$migrate=true` не используется нигде для `game_settings`).

## Findings
