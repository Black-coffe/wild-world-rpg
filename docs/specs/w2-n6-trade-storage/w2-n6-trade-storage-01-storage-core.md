---
story: w2-n6-trade-storage-01
spec: w2-n6-trade-storage
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Ядро склада базы: модель списка, выдача и сдача в сервисе

## Goal
`App\Services\Bases\BaseStorageService` отдаёт нейтральную модель склада (строки ресурсов с количеством,
сортировка, флаг «на базе» и текст пути, если нет) и выполняет выдачу (всё / один вид) и сдачу (всё / один вид)
с гейтом «на базе» внутри. Транзакции, которые сейчас живут в `BaseStorageListAction.php:187,310` и
`BaseStorageDepositAction.php:178,236`, переезжают в сервис без смены правил. Оба handler'а бота становятся
рендерерами модели и результата: тексты и кнопки те же.

## Requirements
> 3. Сделки и проверка «на базе» — в ядре для обоих клиентов. Повтор формы или двойной тап не продаёт и не перекладывает дважды.
> 4. Бот рисует магазин сырья и склад из тех же моделей. Тексты и кнопки не меняются (паритет тестом), своё число через ответ на сообщение работает как раньше.

## Files
- app/Services/Bases/BaseStorageService.php
- app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php
- app/Controllers/Telegram/Commands/Actions/Storage/BaseStorageDepositAction.php
- tests/database/BaseStorageServiceTest.php
- tests/database/BaseStorageBotParityTest.php
- tests/unit/Storage/BaseStorageDepositTest.php
- tests/unit/Storage/BaseStorageRetrieveTest.php
- tests/unit/Storage/BaseStorageWithdrawTest.php
- tests/unit/Controllers/Telegram/BaseStorageDepositPluralizeTest.php
- phpstan-baseline.neon

## Non-goals
- Не трогать карго-дрон (`CargoDrone*`) и `ResourceBankUpdateHandler`: они пишут в склад своими путями.
- Не менять вместимость, вес, правила сортировки и тексты бота.
- Не добавлять скоуп базы, если handler его сейчас не делает: записать находкой.

## Map slice
`memory/map/bases.md` (склад, мульти-база), `memory/map/player.md` (вес, инвентарь)

## Acceptance criteria
- [ ] Выдача и сдача вне базы: отказ из сервиса с кодом, ничего не списано (тест).
- [ ] Двойной вызов выдачи одного вида не выдаёт больше, чем лежало (условная запись или `FOR UPDATE`, тест).
- [ ] Бот: список склада, «забрать», «положить» на базе и вне базы дают те же текст и кнопки, что до изменения (снимки).
- [ ] Существующие тесты `tests/unit/Storage/*` зелёные (правка только под новый шов, не ослабление).

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
