# Хотфикс: двойная выдача со склада и двойная выручка продажи (plan)

**Tier:** 1 · **Spec slug:** `hotfix-trade-race` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-181 (условная запись), ADR-190
**Depends on:** v0.51.687; ядро склада — коммит `6f6f4414` (w2-n6-trade-storage-01), переносится cherry-pick'ом

## Goal
Закрыть на проде гонку «прочитал → начислил → записал посчитанное»: склад («Забрать всё») и продажа сырья
(поштучно, своим числом, оптом). Списание — условной записью до начисления, в одной транзакции; второй
одновременный запрос получает отказ, а не вторую выплату.

## Assumptions
- Ядро склада берётся из story 01 W2.N6 без изменений (полный набор и паритет уже зелёные); при слиянии W2.N6 с develop дифф совпадёт.
- Продажа: строку `character_resources`, ушедшую в 0, удаляем в той же транзакции (как раньше `delete`) — экраны считают строки.
- Отказ проигравшего гонку — текст «уже продано / ресурса не хватает», без новой механики.

## Stories

**Wave 1**
- `hotfix-trade-race-01` — cherry-pick ядра склада + условное списание в `sellResource` и `bulkSellResources`, тесты.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Briefed:** via mini-brief, Andrei, 2026-10-08
**Branch:** vulyk/hotfix-trade-race
**Checked:**
**Council:**
**Shipped:**
