---
story: transport-13
spec: transport-system
status: todo
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 2
blocked_by: [transport-04, transport-06]
---

# Советы и канон: новый совет, правка живого MarchSpeed, абзацы в GAME_DESCRIPTION

## Goal

Система советов и канон догоняют механику. Живой совет `MarchSpeed` обещает игрокам, что темп
Похода **постоянный** — в день релиза это станет ложью; он обновляется идемпотентной миграцией
по `title_en` с сохранением смысла «❤️/💤 — топливо, а не двигатель». Добавляется новый совет
про транспорт (категория `общие`). В `GAME_DESCRIPTION.md` — абзац про тягловый скот Фермеров
(решение владельца «узаконить») и строка в §Исследование о том, что ADR-019 снял **гейт**,
а транспорт вводит **модификатор**.

## Requirements

> продумай, как это должно работать

> и даже чтобы фракция влияла на транспорт

## Files
- app/Database/Migrations/*SeedTransportTip.php
- app/Database/Migrations/*UpdateMarchSpeedTip.php
- GAME_DESCRIPTION.md
- tests/unit/Transport/TransportTipsTest.php

## Non-goals
- Не трогать `TipService`, `GameTipsModel` и расписание рассылки — только данные.
- Не писать анонс: его пишет Queen при выкате (там же строка про 21 грандфазерную позицию).
- Не класть в советы числа баланса, которые тюнятся в `GameSettings`.
- Не переписывать лор Фракций целиком — один абзац про тягловый скот у Фермеров и одна строка в §Исследование.
- Не трогать `/guide` — это story 12.

## Map slice
`memory/map/data-layer.md` (seed-миграции, идемпотентность по `title_en`), `memory/map/onboarding.md` (советы)

## Acceptance criteria
- [ ] Новый совет: категория `общие` (валидная из 14 ENUM), идемпотентен по `title_en`, тон Роби, media-off самодостаточен, парные `*`, эмодзи безопасны на utf8mb4.
- [ ] Совет про транспорт не дублирует существующие: тест проверяет отсутствие второй записи с тем же `title_en` после двойного прогона миграции.
- [ ] `MarchSpeed` обновлён по `title_en` (не создана вторая строка), обещание «темп постоянный» заменено на верное, смысл «❤️/💤 — топливо, а не двигатель» сохранён.
- [ ] Тест краснеет, если текст совета снова утверждает, что темп Похода одинаков у всех.
- [ ] `GAME_DESCRIPTION.md`: абзац §Фракции про тягловых животных Фермеров (единственные, кто приручил их после Коллапса) и строка §Исследование про «гейт снят — модификатор введён».
- [ ] Канон и механика не расходятся: названия машин и фракций в тексте совпадают с каталогом после story 06 («Тягловая повозка», не «Конная»).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/TransportTipsTest.php`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes

## Findings
