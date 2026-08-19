---
story: storage-craft-insurance-01
spec: storage-craft-insurance
status: done
tier: 3
worker: worker-code
tracer: true
wave: 1
blocked_by: []
---

# Единый пул: рюкзак + склад базы

## Goal
Появляется одна точка правды на вопросы «сколько у игрока ресурса X доступно прямо сейчас» и
«списать X штук». Пока игрок на базе, она видит рюкзак и склад как один пул и тратит сначала из
рюкзака, остаток — со склада. Сегодня такой точки нет вообще: `BaseStorageModel` умеет только
принимать (`deliver`), списывать не умеет ни частично, ни вовсе, а каждая механика читает рюкзак
своей копией одного и того же запроса.

## Requirements
> #bug [19.08.2026 05:13] Анжела: 1. Крафт ингредиенты доступны только из рюкзака, даже когда я стою на складе, где их тысячи😳.

## Files
- app/Services/Player/ResourcePoolService.php
- app/Models/BaseStorageModel.php
- app/Database/Migrations/2026-11-25-100000_Adr171SeedResourcePoolGameSettings.php
- tests/unit/Player/ResourcePoolServiceTest.php

## Non-goals
- НЕ трогать вызывающих: крафт, ремонт, стройку, теплицу переводят на сервис отдельные story волны 2.
  Эта story создаёт инструмент и доказывает его тестом, и ничего больше.
- НЕ переписывать `CharacterResourceModel` и не сводить его россыпь методов в один — соблазн большой,
  радиус огромный, к жалобе отношения не имеет.
- НЕ открывать транзакцию внутри `consume()` — вызывающие уже работают в своей (см. `## Contracts`).
- НЕ кэшировать «на базе ли игрок» между запросами.

## Map slice
`memory/map/bases.md` (склад, `BaseCheckService`), `memory/map/data-layer.md` (модели, миграции).

## Acceptance criteria
- [ ] `available()` вне базы равен остатку рюкзака; на базе — сумме рюкзака и склада.
- [ ] `consume()` тратит сначала рюкзак и только остаток со склада; возвращает фактическую разбивку.
- [ ] `consume()` на нехватке бросает `RuntimeException` и НЕ списывает ничего частично.
- [ ] `BaseStorageModel::withdraw()` уменьшает `quantity`, а при уходе в ноль удаляет строку;
      никогда не оставляет строку с отрицательным или нулевым количеством.
- [ ] При `storage.pool_enabled = false` и `available()`, и `consume()` видят только рюкзак.
- [ ] Ключ GameSettings заведён с `rationale_text`, `effect_text`, `above_effect_text`,
      `below_effect_text` — без них запись невалидна (правило ADMIN-TUNABLE BALANCE).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Player/ResourcePoolServiceTest.php`

## Tracer
Тонкий срез через все слои, которых коснётся вся спека: GameSettings (killswitch) → гейт «на базе»
(`BaseCheckService`) → чтение двух таблиц (`character_resources`, `base_storage`) → списание с
приоритетом. Если этот срез покажет, что «на базе» стоит дороже, чем кажется, или что порядок трат
надо перевернуть — волна 2 ещё не написана и переделывать нечего.

## Implementation notes
- `ResourcePoolService`: конструктор берёт все 5 зависимостей опционально (как `CraftShortageService`), внутренние точки касания моделей/сервисов вынесены в `protected` хуки (`isPooled`, `backpackQuantity`, `storageQuantity`, `withdrawBackpack`, `withdrawStorage`, `resolveResourceId`) — тест переопределяет их анонимным классом и не ходит в БД вообще.
- `breakdown()` отдаёт `storage` как фактическое количество на складе ВСЕГДА (даже не на базе/при killswitch=off) — только `pooled` решает, считать ли его в `available()`/`consume()`. Так вызывающий может честно сказать игроку «на складе есть, но ты не на базе» вместо немого нуля.
- `BaseStorageModel::withdraw()` и новый `quantityFor()` не полагаются на уникальность (character_id, resource_id) — суммируют/списывают по всем строкам (`orderBy id ASC`), т.к. в таблице такого индекса нет (проверил миграцию/схему).
- Гейт «на базе» и killswitch читаются ОДИН раз за вызов внутри `isPooled()`, вызывается из `breakdown()`, которую и `available()`, и `consume()` вызывают ровно один раз — соответствует «не кэшировать между запросами» и «гейт вычисляется один раз за вызов».
- Списание backpack идёт через существующий `CharacterResourceModel::decreaseResources()` (не трогал модель) — как и остальной код в проекте, он берёт `first()` без учёта дублей `(id_characters, id_resources)`; это существующее поведение, не в scope этой story (non-goal явно запрещает трогать `CharacterResourceModel`).
- Отклонений от контракта `## Contracts` нет — сигнатуры реализованы буква в букву.
- GameSettings-ключ `storage.pool_enabled` (bool, default true, категория `resources`) — по образцу `V24SeedCraftInsuranceGameSettings`, идемпотентная миграция с проверкой существующей строки.

## Findings
