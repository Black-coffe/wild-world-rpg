---
story: health-warning-backoff-03
spec: health-warning-backoff
status: done
tier: 2
worker: worker-code
tracer: false
wave: 2
blocked_by: [health-warning-backoff-01, health-warning-backoff-02]
---

# Тумблер «предупреждения о здоровье» в Настройках

## Goal
После этой истории игрок может сам выключить предупреждения о низком здоровье в экране
«⚙️ Настройки» — рядом с уже живущими там тумблерами картинок и «Совета дня» — и хендлер
такого игрока молча пропускает.

## Requirements
> тумблер отключения в настройках тоже сделай

> этот спам просто напрягает конкретно

## Files
- app/Database/Migrations/2026-09-14-120000_HealthWarnMuteColumn.php
- app/Models/CharacterModel.php
- app/Controllers/Telegram/Commands/Actions/SettingsAction.php
- app/Config/CallbackRoutes.php
- app/TaskHandlers/LowHealthWarningHandler.php

## Как это должно работать

Копируй уже устоявшийся в этом же файле паттерн тумблера «Совет дня» (`dailyTipsOn` /
`dailyTipsOff`, колонка `characters.daily_tips_enabled`, статический хелпер `dailyTipsFlag()`),
а не выдумывай свой: он уже решает и хранение, и перерисовку экрана, и toast.

- Колонка `characters.health_warnings_enabled` TINYINT(1) NOT NULL DEFAULT 1 (opt-out, как у
  советов: по умолчанию включено). Обязательно в `$allowedFields` `CharacterModel` — без этого
  CI4 молча выбросит поле при `update()`.
- Два callback: `healthWarnOn` / `healthWarnOff` → `SettingsAction`, зарегистрировать в
  `app/Config/CallbackRoutes.php` рядом с `dailyTipsOn/Off`.
- Кнопка и строка состояния в экране настроек — в том же стиле, что соседние тумблеры.
- `LowHealthWarningHandler` пропускает персонажей с флагом 0. Отсекай их **в выборке из БД**
  (условие в запросе), а не циклом после выборки: хендлер крутится раз в минуту.

## 🔴 Текст экрана обязан сказать правду о цене

Тумблер глушит **все** предупреждения, включая критические — те, что приходят, когда до смерти
персонажа остаются минуты. Это осознанный выбор игрока, но он должен быть информированным:
строка в экране настроек прямо говорит, что выключаются и предупреждения о риске смерти.
Формулировку подбери сам, но полуправды («просто меньше сообщений») быть не должно.

## Non-goals
- НЕ менять логику затухания и её настройки — это story-02, готово.
- НЕ трогать текст самого предупреждения, картинку и кнопки.
- НЕ делать отдельный тумблер только для критических предупреждений — один флаг.
- НЕ добавлять ключ в `GameSettings`: это преференция игрока, а не баланс.
- НЕ трогать `Config\WipeManifest`: `characters` уже классифицирована `CHARACTER_RESET`.

## Verification
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
затем `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
(живой экран проверяется Tier-3 смоуком на testbot — не в этой истории)

## Запреты по инструментам
- НЕ `git stash`, НЕ `git checkout`, НЕ `git reset`, НЕ `git commit`. Прежняя версия файла — `git show HEAD:<path>`.
- НЕ запускать полный набор тестов и не гонять его параллельно.
- НЕ выполнять `php spark migrate` и никаких DROP/TRUNCATE на локальной тест-БД — она общая.

## Implementation notes
- Миграция `HealthWarnMuteColumn`: `health_warnings_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `after low_health_warns_day`, идемпотентна (`fieldExists`). `characters` уже `CHARACTER_RESET`
  в манифесте — не трогал.
- `CharacterModel::$allowedFields` — добавил колонку рядом с остальными `low_health_*`.
- `LowHealthWarningHandler` — добавил `->where('health_warnings_enabled', 1)` в SQL-выборку
  `findAll()`. Сейчас (после правки соседней истории по её ревью) это единственный запрос за
  персонажами: второй запрос `findColumn('id')`, который был на момент моей правки, убран —
  id берутся из уже загруженного `$lowHealthCharacters` тем же фильтром, второго похода в БД нет.
  `HealthWarningSchedule` не трогал.
- `SettingsAction`: скопирован паттерн `dailyTipsOn/Off` → `healthWarnOn/Off` +
  `healthWarnFlag()` (дефолт 1) + строка состояния/кнопка в `buildScreen()`. Текст экрана прямо
  говорит, что тумблер гасит и критические предупреждения о риске смерти.
- `CallbackRoutes.php` — оба callback'а зарегистрированы рядом с `dailyTipsOn/Off`.
- Guide/tips-coverage для этого тумблера — story-04 (brief.md уже вынес вердикты «да» на оба).
