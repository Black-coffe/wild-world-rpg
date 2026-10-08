# Хотфикс F6: банк не выкупает сырьё дороже, чем продаёт (plan)

**Tier:** 1 · **Spec slug:** `hotfix-bank-pump-f6` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-175 (затухание рынка), ADR-157 (спред-инвариант крафта — та же идея для сырья), exploit-audit `EA-economy-04` / F6
**Depends on:** v0.51.690 (`origin/develop` `a355f16d`)

## Goal
Закрыть на проде печать золота кругом «купил сырьё → тик крона → сдал банку». Формула цены в
`ResourceBankUpdateHandler` разводит две цены по разные стороны базовой: покупка не дешевле `base×1.05`,
выкуп не дороже `base×0.95`. Живость рынка сохраняется в свою сторону, экраны и сделки не меняются.

## Assumptions
- Сделки читают только `resources.buy_price`/`sell_price` (`ResourceTradeService::unitPrice`), персональных
  множителей у сырья нет (`CraftShortfallBuyService.php:38`) — значит, инвариант в одной формуле крона закрывает все
  пути: поштучно, своё число, опт, докупка, веб `/play`.
- Накачанные строки `resources_bank` на проде не чистим: следующий тик сам пересчитает цены по новой формуле.
- Судьба золота и запасов персонажей 798/1107/904 — отдельное решение владельца, не код этой спеки.

## Stories

**Wave 1**
- `hotfix-bank-pump-f6-01` — разделение цен в формуле крона, тест-инвариант по сетке, правка ожиданий старых тестов, PoC зелёный.

## Contracts
none

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:**
**Branch:** vulyk/hotfix-bank-pump-f6
**Checked:**
**Council:**
**Council:** GREEN round 1, 2026-10-08, at 3881f455, pack eea95b1024d3
**Shipped:**
**Briefed:** via mini-brief, Andrei, 2026-10-08
