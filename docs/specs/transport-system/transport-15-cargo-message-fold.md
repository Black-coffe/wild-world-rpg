---
story: transport-15
spec: transport-system
status: done
tier: 2
worker: worker-code
model: sonnet
tracer: false
wave: 2
blocked_by: [transport-08]
---

# Строка про увезённый груз живёт в сообщении о добыче, а не рядом с ним

## Goal
Story 08 сообщает игроку об увезённом на склад грузе **отдельным** сообщением: файлы,
где собирается существующее сообщение о добыче, не входили в её `## Files`. В итоге
каждая добыча с грузовой машиной шлёт в чат две штуки подряд вместо одной. Story
сворачивает это в одно сообщение.

## Requirements
> и груз перевозить

## Context
Второе сообщение на каждую добычу — заметный шум: добыча случается 267 раз за две
недели, и у активного игрока это удвоение потока. В проекте уже есть правило против
кнопок-одиночек и лишних экранов по той же причине — интерфейс не должен множить
сущности там, где хватает строки.

Строка обязана быть самодостаточной в media-off (весь смысл в тексте, картинка —
только украшение) и markdown-safe: непарная `*` роняет отправку в Telegram молча,
это уже случалось в проекте.

## Files
- app/Services/Player/Gather/GatherResultPersister.php
- app/Services/Player/Gather/GatherMessageFormatter.php
- app/TaskHandlers/GatherTaskHandler.php
- tests/unit/Transport/CargoMessageFoldTest.php

<!-- Поправка: первоначально здесь стоял `Services/Bases/BaseServiceMessageFormatter.php`
     — ошибка планирования. Тот класс форматирует экран Базы и к потоку добычи отношения
     не имеет. Сообщение о добыче собирает `Gather/GatherMessageFormatter`, вызываемый из
     `GatherTaskHandler::sendResourcesFoundReply`. Воркер проверил и вернул NEEDS_CONTEXT
     вместо того, чтобы править не тот файл. -->

## Acceptance
- [x] Добыча с активной грузовой машиной шлёт игроку **ровно одно** сообщение;
      строка об увезённом на склад — внутри него.
- [x] Добыча без грузовой машины даёт сообщение, байт-идентичное сегодняшнему.
- [x] Строка называет, что именно и сколько уехало на склад, а не «часть добычи».
- [x] Итоговый текст ≤ 1024 знаков (иначе Telegram молча не отправит фото с подписью)
      и markdown-safe — тест на чистой render-функции, без Telegram.
- [x] Существующие тесты добычи зелёные.

## Non-goals
- Не менять сам расчёт доли и инвариант сохранения массы (story 08 — его владелец).
- Не трогать `GatherTaskHandler` шире, чем нужно для одной строки.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/CargoMessageFoldTest.php`

## Map slice
`memory/map/player.md` (добыча), `memory/map/telegram.md` (MediaSender, caption)

## Implementation notes
- `GatherResultPersister::persist()` получил 4-й параметр `bool $foldCargoNote = false` и
  вернул `?string` (было `void`). Дефолт `false` — старое поведение байт-в-байт (отдельный
  `sendMessage` через `notify()`); `writeCharacterResources()` тоже стал `?string`-returning
  и принимает флаг. Выбрано осознанно вместо «чистого» return-only рефактора из Goal: story
  08's `tests/unit/Transport/CargoSplitTest.php` не в `## Files` этой стори и явно вне
  Non-goals («не трогать» шире расчёта доли), а он напрямую тестирует факт вызова
  `notify()`/`send()` для склад-ноты (`testCargoNoteNamesResourceAndAmount`) — безусловное
  удаление notify() сломало бы его без права его чинить. Дефолт-параметр держит контракт
  этого теста целым (проверено: 9/9 зелёных, включая именно этот кейс в изоляции), а
  единственный реальный вызывающий (`GatherTaskHandler`) передаёт `true` и реально
  сворачивает сообщения.
- Кейс «машина есть, базы нет» (`composeNoBaseNote`) **не сворачивается** — остаётся
  отдельным `sendMessage`, как раньше. Acceptance стори говорит только про строку «что
  уехало на склад» (успешная доставка); non-base — редкий honest-error кейс вне
  формулировки Goal, трогать шире не стал (Non-goals: «не трогать `GatherTaskHandler`
  шире, чем нужно для одной строки»).
- `GatherMessageFormatter::buildResourcesFoundReply()` — новый опциональный 9-й параметр
  `?string $cargoNote = null`, вклеивается HTML-escaped строкой перед финальной фразой.
  `null`/`''` → поведение байт-идентично сегодняшнему (тест
  `testNoCargoNoteIsByteIdenticalToOmittingParameter`).
- `GatherTaskHandler::saveFoundResources()` сменил `void` → `?string`, `handle()` пробрасывает
  результат в `sendResourcesFoundReply()` (новый опциональный параметр `?string $cargoNote`),
  который передаёт его формарттеру.
- Tips-ревью: вердикт «не нужен» — это внутренняя консолидация двух сообщений в одно, а не
  новая механика; игроку нечему учиться, поведение (что и сколько уехало на склад) не
  изменилось, только доставка текста.
- Guide-ревью: вердикт «не нужен» — нет новой навигации/понятия, `/guide` уже не описывал
  отдельное «сообщение о грузе» как самостоятельный шаг.
- Замечено: `tests/unit/Transport/CargoSplitTest.php` при запуске ВСЕЙ директории
  `tests/unit/Transport/` иногда падает с `Table 'characters' already exists`/`doesn't exist`
  — воспроизводится и на чистом `develop` (до этой стори), не связано с изменениями этой
  стори (проверено `git stash` + повтор прогона). Каждый файл в изоляции зелёный.
