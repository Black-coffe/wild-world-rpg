---
story: storage-craft-insurance-07
spec: storage-craft-insurance
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 3
blocked_by: [storage-craft-insurance-03, storage-craft-insurance-05]
---

# Ворота контента: игрок должен узнать, что склад ожил

## Goal
Механика, о которой никто не сказал игроку, для него не существует. Единый пул и выдача по одному
виду получают совет от Роби, раздел в «📖 Путь новичка» и контекстную подсказку в момент первого
контакта со складом. Без этого правка чинит жалобу Анжелы и остаётся невидимой для остальных.

## Requirements
> 2. Кнопка «забрать со склада» должна работать так же, как и кнопка «положить на склад»: «выбор ресурса» или «забрать все», иначе для запуска крафта приходится забирать в рюкзак весь склад и потом выкладывать его обратно по частям ⬇️ [Image #3]

## Files
- app/Database/Migrations/2026-11-25-110000_Adr171SeedStoragePoolTip.php
- app/Services/Onboarding/GuideCatalog.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php

## Non-goals
- НЕ писать в совет числа баланса (сколько ресурсов, какая вместимость) — они дрейфуют, и совет
  начинает врать. Совет о навигации и понятии: что такое склад, когда он считается вместе с рюкзаком.
- НЕ заводить новый раздел `/guide`, если тема ложится в существующий про базу/склад — дубль-раздел
  хуже расширенного.
- НЕ трогать `TipService` и механику рассылки.
- НЕ выдавать за просмотр никаких наград — `/guide` read-only, это гейт `GuideCatalogTest`.

## Map slice
`memory/map/onboarding.md` (`/guide`, «Совет дня», JIT-подсказки).

## Acceptance criteria
- [ ] Совет заведён идемпотентной seed-миграцией, идемпотентность по `title_en`, категория — одна из
      14 значений ENUM `tip_type`, эмодзи безопасны для utf8mb4.
- [ ] Раздел `/guide` объясняет: склад базы, как положить и забрать по одному виду, и что на базе
      сырьё для крафта считается вместе с рюкзаком. Ключ раздела — только `[a-z]`, без `_`.
- [ ] Текст read-only, самодостаточен (MEDIA-OFF), парные `*`.
- [ ] Совет не дублирует существующий про склад/дрон — проверено по содержимому `game_tips`.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding/GuideCatalogTest.php`

## Implementation notes

## Findings
