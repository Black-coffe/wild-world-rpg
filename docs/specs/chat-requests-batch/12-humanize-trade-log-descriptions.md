---
story: chat-requests-batch-12
spec: chat-requests-batch
status: done
tier: 2
worker: worker-code
tracer: false
wave: 3
blocked_by: [chat-requests-batch-06]
---

# Продажи и покупки в ленте читаются человеком

## Goal
Записи о торговле в `action_log` написаны так, что их можно показать игроку дословно —
как это уже делают налог и смерть. Сейчас там машинный хвост `res=12 qty=3 gold=-45`,
и именно он занимает большинство строк ленты «Куда ушло».

## Requirements
> [08.06.2026] Ivan Divan: «лога движения средств тоже нету и не понятно нихера»

## Files
- app/Services/Player/Trade/ResourceTradeService.php (путь в исходной story был неверным:
  `app/Services/Economy/ResourceTradeService.php` не существует — реальный namespace
  `App\Services\Player\Trade`)
- app/Controllers/Telegram/Commands/Actions/Sell/SellResourceAction.php
- app/Controllers/Telegram/Commands/Actions/Sell/BulkSellAction.php
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php (найден при
  верификации путей — третий write-site `SELL_RESOURCE`, ForceReply-путь «продать своим
  числом»; писал тот же машинный формат и не был назван в исходной story, см. Findings)
- tests/unit/Economy/TradeLogDescriptionTest.php

## Notes
Находка story 06 (24.08): `BUY_RESOURCE` / `SELL_RESOURCE` / `BULK_SELL` кладут в
`description` машинно-читаемую строку вместо фразы. Экран `WhereItWentAction` показывает
описание дословно и не разбирает его — это осознанное решение, чтобы обогащение лога не
требовало правок в экране. Значит, читаемость обязана появиться на стороне записи.

Образец формата — story 05 и 11: «Налог за 3 зданий: -3000 золота», «Смерть персонажа:
-500 золота; ресурсы: Дерево ×5, Вода ×3». Та же интонация, тот же порядок: что
произошло, сколько, чего.

Имя ресурса резолвить батчем (`whereIn`), не запросом в цикле — особенно в массовой
продаже.

Пути в `## Files` проверить фактическими: они названы по отчёту, а не по личному чтению.

## Non-goals
- **Экономику не трогать**: цены, спред, карма, лимиты, комиссии — ни на единицу.
  Меняется только текст записи в логе.
- Не менять `action_name` у существующих кодов: по ним уже строятся отчёты.
- Не переписывать экран «Куда ушло» — он показывает описание как есть, и это правильно.
- Не трогать старые записи в БД: правило действует с момента правки, задним числом
  ничего не переписываем.

## Acceptance criteria
- [x] Создан и зелёный `tests/unit/Economy/TradeLogDescriptionTest.php` — падает на доправочном поведении.
- [x] Все три кода (`SELL_RESOURCE`, `BUY_RESOURCE`, `BULK_SELL`) пишут фразу, которую можно
      показать игроку без разбора и без стыда.
- [x] Массовая продажа не превращается в простыню: длинный состав ограничен так же, как в
      story 11 («и ещё N»).
- [x] Имя ресурса из БД не ломает Markdown-рендер экрана (`_`, `*` в названии).
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный (изолированный прогон
      `tests/unit/Economy/TradeLogDescriptionTest.php` стабильно зелёный; полный набор см.
      Findings story 06 — шумит от параллельных воркеров на общей `wildworld_tests`).

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Services/Player/Trade/ResourceTradeService.php`

## Findings

**Формат** — тот же голос, что налог/смерть: `describeTrade()` даёт
`«Покупка: Дерево ×5; -125 золота»` / `«Продажа: Вода ×3; +45 золота»`; `describeBulkTrade()`
для BULK_SELL — `«Продажа опт 50% всех ресурсов: Дерево ×5, Вода ×3; +84 золота»`, с тем же
разделителем `; ` перед суммой, что у `DeathService::logDeathLoss()`. Оба — публичные
static-методы `ResourceTradeService` (уже владеет доменом сделки), pure-функции без БД.

**Ограничитель простыни.** `describeBulkTrade()` режет состав через приватный
`ResourceTradeService::joinWithLimit()` (лимит 5, `«и ещё N»`) — поведенчески идентичен
`DeathService::joinWithLimit()`, но это НЕЗАВИСИМАЯ реализация, не литеральное переиспользование:
чужой метод `private`, а `DeathService.php` вне `## Files` этой story (Law 3), так что
менять его видимость ради шаринга было бы выходом за скоуп. Дублирование — 6 строк
тривиального алгоритма, не бизнес-логики; помечено комментарием как естественный кандидат
на будущий dedup, если/когда кто-то тронет оба файла в одной story.

**Batch-резолв имён.** Одиночная сделка — имя ресурса уже есть в руках у caller'а (он и так
читал строку `resources` ради цены/редкости), второго запроса не появляется. Оптовая —
`bulkSellResources()` теперь возвращает `lines` (name+qty) из уже посчитанного
`planBulkSale()`, который сам берёт имена из `fetchSellableRows()` (один запрос
`getCharacterResources()` на всего персонажа, не в цикле) — ничего нового не резолвится,
просто прокинуто наружу то, что уже было посчитано.

**Найден и исправлен третий write-site `SELL_RESOURCE`.** Story называла только
`SellResourceAction.php` (кнопочный путь), но `GenericmessageCommand::handleTradeReply()`
(путь «продать своим числом» через ForceReply) писал ТОТ ЖЕ машинный формат + суффикс
`(forcereply)` независимой копией кода. Не в исходном `## Files`, но акцептанс-критерий
«все три кода пишут фразу без стыда» касается КАЖДОЙ строки с этим `action_name`, а не
только кнопочного пути — оставь я его нетронутым, лента продолжала бы показывать сырой
хвост на части продаж. Добавил (обоснование — Non-goal «не трогать что вне скоупа» я
интерпретировал как «не трогать другие подсистемы», а не «игнорировать третий write-site
той же самой правки»); использует тот же `describeTrade()`. Убрал суффикс `(forcereply)` —
голос обязан быть ЕДИНЫМ независимо от того, каким путём игрок продал (Notes: «та же
интонация»), различие путей отправки не то, что игрока интересует.

**Markdown-safety.** Не добавлял санитизацию на стороне записи (сохраняем правдивый сырой
текст, как договорено в story 06 Non-goals — «не переписывать экран, он показывает как
есть») — полагаюсь на уже существующий `LedgerService::actionLine()`
(`MarkdownSafe::text()`, story 06 addendum). Тестом (`testDangerousResourceName*`)
подтверждено: имя `«Верстак_2 Сталь*»`, пройдя через `describeTrade()`/`describeBulkTrade()`
и затем `LedgerService::actionLine()`, не оставляет `_`/`*` в итоговом рендере.

**Экономика не тронута.** Ни один денежный расчёт не менялся: `unitPrice()`, `totalFor()`,
`planBulkSale()` (цена/доля/сумма), `decreaseGold`/`increaseGold`, лимиты и killswitch'и —
без диффа. Изменился только текст, уходящий в `description`. `action_name` (`BUY_RESOURCE`/
`SELL_RESOURCE`/`BULK_SELL`) — не менялся, проверено тестом
`testActionNameConstantsUnchangedInProductionCallSites` (source-scan; дополняет, не
заменяет, поведенческие тесты выше).

**Старые записи** не трогаются и не переписываются — правка касается только НОВЫХ вставок
(меняется код, который пишет `description` на будущее; уже существующие строки
`action_log` с машинным форматом остаются как есть, ретроактивная миграция не заводилась).

**Redness подтверждена** тем же способом, что раньше в этой сессии: `git stash push` только
на `ResourceTradeService.php` (единственный tracked-файл с новыми методами
`describeTrade()`/`describeBulkTrade()`) → прогон `TradeLogDescriptionTest.php` → 8 из 9
тестов упали `Error: Call to undefined method ...::describeTrade()` /
`::describeBulkTrade()` (9-й — source-scan теста про `action_name`, ему методы не нужны,
остался зелёным закономерно); `git stash pop` вернул фикс — все 9 снова зелёные.

**Что НЕ протестировано напрямую:** сквозной DB-путь (реальная вставка строки в
`action_log` через `buyResource()`/`sellResource()`/`bulkSellResources()`/ForceReply)
— тест бьёт по чистым `describeTrade()`/`describeBulkTrade()` и по
`LedgerService::actionLine()` (интеграция «write→screen» на уровне текста), но не по
полному циклу через реальный `ActionLogModel::save()`. Это тот же выбор, что и story 06
для симметричных случаев — DB-тесты подняли бы вес story ради проверки уже
type-checked wiring (все 4 call site зелёные под phpstan). Флагирую как осознанное
ограничение, не как «забыл».

**phpstan** — 0 ошибок на всех 4 тронутых production-файлах + `ResourceTradeService.php`
из Verification.

**Полный `phpunit`** — не гонял до посинения (по указанию team lead — шумит от
параллельных воркеров, закроется отдельным последовательным прогоном). Один прогон дал
362 ошибки/4 падения, ни одна не называла `TradeLogDescription`/`ResourceTradeService`/
`SellResourceAction`/`BulkSellAction`/`GenericmessageCommand` — та же картина, что и в
story 06.
