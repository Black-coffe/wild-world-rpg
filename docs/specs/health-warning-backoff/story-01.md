---
story: health-warning-backoff-01
spec: health-warning-backoff
status: done
tier: 2
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Схема и настройки для затухания предупреждений

## Goal
После этой истории в БД есть состояние, на которое сможет опереться логика затухания, а все
числа частоты предупреждений живут в админке с полным обоснованием — включая те 33 и 5 минут,
что сегодня захардкожены в коде.

## Requirements
> если человек на одно, два, три сообщения не реагирует, то просто забить нужно, на это не напрягать спамом

## Files
- app/Database/Migrations/2026-09-14-101500_HealthWarnBackoffColumns.php
- app/Database/Migrations/2026-09-14-110000_HealthWarnBackoffSettings.php
- app/Models/CharacterModel.php

## Что сделать

### A. Колонки `characters` (первая миграция)
РОВНО эти имена — вторая история пишет код под них:
- `low_health_warn_streak` INT NOT NULL DEFAULT 0
- `low_health_last_band` DECIMAL(5,2) NULL
- `low_health_warns_today` INT NOT NULL DEFAULT 0
- `low_health_warns_day` DATE NULL

Ставь их рядом с существующей `low_health_notified_at`. `down()` их снимает.

🔴 **Каждая новая колонка ОБЯЗАНА попасть в `$allowedFields` модели `CharacterModel`** — без
этого CI4 молча выбрасывает поле при `update()`, и затухание не будет сохраняться (в проекте
на этом уже горели). Это единственная причина, по которой `CharacterModel.php` в списке файлов;
больше в ней ничего не меняй.

### B. Ключи `GameSettings` (вторая миграция)
Категория `world`. Список и дефолты — в `brief.md`, раздел «Договорённости по именам».
Плюс границы полос здоровья (5 / 3 / 1 / 0.1) — форму (отдельные ключи или один текстовый)
выбери по ФАКТИЧЕСКОЙ схеме `game_settings`, посмотрев `SHOW COLUMNS` или миграцию, а не по памяти.

🔴 У КАЖДОГО ключа обязаны быть заполнены `rationale_text`, `effect_text`, `above_effect_text`,
`below_effect_text`, `default_value_text`, `recommended_min/max`, `hard_min/max` — запись без
них нарушает конституционное правило admin-tunable balance. Пиши их содержательно: «что
произойдёт, если выше» — это конкретный сценарий для игрока, а не «будет больше».
Образец полей и тона возьми у существующей миграции, которая сеет ключи `GameSettings`
(например `2026-09-12-110000_S5FirstShelterGameSettings.php`), и повтори её паттерн, включая
идемпотентность.

## Non-goals
- НЕ трогать `LowHealthWarningHandler` — это story-02.
- НЕ менять `Config\WipeManifest`: новых таблиц нет, `characters` уже классифицирована.
- НЕ менять порог `health <= 5.00` и текст предупреждения.
- НЕ добавлять игроку никаких тумблеров.

## Verification
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
затем `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Config/WipeManifestCoverageTest.php`
затем `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Запреты по инструментам
- НЕ `git stash`, НЕ `git checkout`, НЕ `git reset`, НЕ `git commit`. Прежняя версия файла — `git show HEAD:<path>`.
- НЕ запускать полный набор тестов и не гонять его параллельно.
- НЕ выполнять `php spark migrate` и никаких DROP/TRUNCATE на локальной тест-БД — она общая.
