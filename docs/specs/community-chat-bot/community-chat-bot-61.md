---
story: community-chat-bot-61
spec: community-chat-bot
status: done
tier: 1
worker: worker-code
tracer: false
wave: 14
blocked_by: []
---

# Ограничитель: канальный пост считается, промах настройки кэшируется

## Goal
Групповое ведро ограничителя перестаёт пропускать апдейты без `from`, а отсутствующая настройка
перестаёт стоить чтения БД на каждый апдейт группы.

## Requirements
> чтобы люди не использовали его для того, чтобы узнать какие-то фишки, лайфхаки и читинг в игре, и потом это не применяли

## Files
- app/Filters/TelegramRateLimitFilter.php
- tests/unit/TelegramRateLimitGroupCeilingTest.php

## Два дефекта, найденные ревью 2026-08-25

**1. `channel_post` не считается ничем.** `before()` выходит по `$userId === null` **раньше**
группового ведра, а канальный пост приходит без `from`. Флуд из канала не попадает ни в
персональное ведро (некого считать), ни в групповое (до него не дошли).

**2. Промах настройки не кэшируется.** `groupMaxPerMinute()`: пока ключа в `game_settings` нет,
`get()` возвращает `null` и результат не запоминается — каждый апдейт группы делает `findByKey()`.
Story 37/41 убрали второе чтение, но не это. Отрицательный результат кэшируется так же, как
положительный (урок `feedback_ci4_cache_increment_refreshes_ttl` — про соседний класс ошибок с TTL,
прочитать перед правкой).

## Non-goals
- Не менять сами лимиты и не заводить новых ключей `GameSettings`.
- Не трогать персональное ведро и его ключи — разделение `tg_rate_group_*` / `tg_rate_*` корректно.
- Не трогать `BotController` и приём — только фильтр.
- Не «заодно» рефакторить кэш-слой.

## Acceptance criteria
- [ ] Апдейт без `from` (канальный пост) учитывается групповым ведром той же группы.
- [ ] Отсутствие ключа настройки читается из БД один раз за окно кэша, а не на каждый апдейт.
- [ ] Появление ключа подхватывается в пределах прежнего окна кэша — отрицательный результат не залипает дольше положительного.
- [ ] Оба пункта поданы тестом, краснеющим на возврате прежнего поведения.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/TelegramRateLimitGroupCeilingTest.php`

## Implementation notes

- Дефект 1: в `before()` блок `isGroupChat()`/`extractChatId()` перенесён ВЫШЕ проверки
  `$userId === null` (`app/Filters/TelegramRateLimitFilter.php`); guard стал
  `$userId === null && $groupChatId === null`. Ниже по коду `$userId` используется только
  в персональной ветке (`$groupChatId === null`), где он гарантированно не null — код там
  не менялся.
- Дефект 2: `groupMaxPerMinute()` завёл собственный кэш-промах (`GROUP_SETTING_MISS_CACHE_KEY
  = 'tg_rate_group_setting_miss'`, TTL `GROUP_SETTING_MISS_TTL_SECONDS = 60`, равный
  `GameSettingsService::CACHE_TTL`) — до чтения `GameSettingsService::get()` фильтр сперва
  смотрит в свой кэш; если промах уже отмечен — сразу отдаёт фолбэк без обращения к БД.
  `GameSettingsService` не тронут (Non-goals: не рефакторить кэш-слой заодно) — это
  отдельный, специфичный для фильтра кэш, а не патч общего сервиса.
- Существующий лог-throttle `notifyGroupLimitFallbackOnce()`/ключ
  `tg_rate_group_setting_missing_notice` (TTL 120с, окно rate-limit'а) оставлен как есть —
  это отдельная по смыслу вещь (дедуп логов), не источник фолбэк-значения.
- Тесты добавлены в `tests/unit/TelegramRateLimitGroupCeilingTest.php`: 4 новых —
  `testChannelPostWithoutFromCountsTowardGroupBucket`,
  `testChannelPostAndGroupMessageShareTheSameBucket` (дефект 1),
  `testGroupCeilingMissCachedIgnoresSettingAppearingWithinSameWindow`,
  `testGroupCeilingMissReadsSettingsTableOnlyOncePerWindow` (дефект 2).
  setUp/tearDown дополнены очисткой нового ключа `tg_rate_group_setting_miss`.
- **Два круга красноты на TTL-тесте, оба — про тестовый инструмент, не про фильтр.**
  Круг 1: `$cache->getMetaData()` сразу после `save()` вернул `null`. Круг 2 (после замены
  на `Time::setTestNow()`-сдвиг на 61с): группа не блокировалась — лимит остался на
  персональном фолбэке, будто промах никогда не истёк. Корень обоих: `CIUnitTestCase`
  подменяет `\Config\Services::cache()` на `CodeIgniter\Test\Mock\MockCache` в каждом
  тесте (стандартный `$setUpMethods` framework'а, не наш выбор) — а у него `get()`
  **вообще не проверяет TTL** (сравнение по `expirations[]` живёт только в
  `getMetaData()`, причём с перевёрнутым условием — framework-баг сам по себе). Значит
  промах в `MockCache` никогда не «протухает» через `get()`, и ни экспайр-тест, ни
  метаданные ничего не докажут в этом окружении.
  Финальная версия считает реальные SQL-запросы к `game_settings` через событие
  `DBQuery` (CI4 шлёт его на каждый исполненный запрос независимо от кэш-слоя,
  безусловно на success/fail-путях в `BaseConnection::query()`) — 5 тапов внутри окна
  обязаны дать 1 запрос, а не 5. Это прямая проверка формулировки дефекта из story
  («каждый апдейт группы делает `findByKey()`»), не зависящая от особенностей
  кэш-хендлера.
- `php -l` по `TelegramRateLimitFilter.php` и тестовому файлу, точечный `phpstan` по
  `TelegramRateLimitFilter.php` — чисто (`phpstan.neon` не покрывает `tests/`). `phpunit`
  не запускал (правило координации story — параллельные воркеры делят `wildworld_tests`).

## Findings
