# W2.N4 хвосты — переезд в ядре, подтверждение с уровнем, эффект апгрейда (plan)

**Tier:** 2 · **Spec slug:** `w2-n4-tails` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-181 (условные записи), ADR-020 (media-off), ADR-024 (баланс в GameSettings — новых чисел нет)
**Depends on:** w2-n4-base (v0.51.681): `BuildOrderService`, `BuildingUpgradeService`, `WebNativeScreenService::{buildStart,upgrade}`, `BaseCallbackSuffix`

## Goal
Закрыть три дыры, найденные советом w2-n4-base, и один флаки-тест. (1) Проверка «идёт переезд базы»
переезжает из Telegram-handler'ов в ядро стройки и апгрейда, поэтому веб больше не строит и не
улучшает во время переезда. (2) Подтверждение апгрейда несёт уровень, с которого оно сделано. Ядро
не применяет подтверждение, если постройка уже ушла с этого уровня, поэтому повторный тап больше не
оплачивает следующий уровень. (3) Эффект уровня «сейчас → после» считается в ядре, а не внутри
`BaseDevelopmentAction`, и показывается в обоих клиентах перед оплатой. (4) Тест троттлера больше
не зависит от скорости CI.

## Assumptions
- Отказ ядра при переезде использует текст бота из `ActiveTasksService::checkRelocationAndBlock`
  без Markdown-разметки в вебе (как остальные отказы — через `plain()`). Бот-handler'ы свою проверку
  сохраняют: двойная проверка в боте безвредна, а снимать её из ~60 handler'ов — вне объёма.
- Проверка переезда в ядре закрывает только базу (каталог, карточка, старт, превью и применение
  апгрейда). Поход, крафт и прочие экраны веба не трогаем.
- Формат кнопки: `confirm_upgrade_building_<buildingId>_l<fromLevel>[_b<baseId>]` (≤64 байт).
  Кнопка без `_l` (старые сообщения) ничего не списывает и заново показывает экран подтверждения.
- Веб-форма апгрейда несёт `from` (уровень из превью); запрос без `from` отказывается как
  устаревший (веб-формы живут одну загрузку страницы, старых нет).
- Строка эффекта для зданий без множителя (оборона, производство) берётся из той же логики
  `nonMultiplierLine`; здания без эффекта строку не получают.
- Новых чисел баланса нет; тексты — не баланс.
- Ask 5 (вердикты guide/tips — «нет») и ask 6 (живой проход на preprod) story не несут: вердикты
  записаны в бриф, живой проход делает Queen после слияния, до тега.
- `verify-gap` от `wave-check.sh` на всех трёх story — известное ложное срабатывание (путь
  `vendor/bin/phpunit` в команде полного набора, как на w2-n4-base); полный набор покрывает `## Files`.

## Stories

**Wave 1**
- `w2-n4-tails-01` — Ядро: переезд блокирует стройку и апгрейд; подтверждение апгрейда с уровнем в обоих клиентах

**Wave 2**
- `w2-n4-tails-02` — Эффект уровня в ядре: веб-карточка и запрос апгрейда бота, «Развитие базы» на том же расчёте

**Wave 3**
- `w2-n4-tails-03` — Часы троттлера заморожены в `testThrottleBucketsArePerAccount`

## Contracts
- `ActiveTasksService::hasActiveRelocation(int $characterId): bool` — нейтральная проверка;
  `checkRelocationAndBlock()` вызывает её. Константа текста отказа — в `ActiveTasksService`.
- `BuildingUpgradeService::apply(int $characterId, ?int $baseId, int $buildingId, ?int $fromLevel)`:
  `$fromLevel === null` или `!== current_level` → код `stale` без записи. Превью отдаёт
  `current_level` как сейчас; в story 02 добавляет `effect_now`/`effect_next` (`?string`).

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei Andrievskii, 2026-09-28 — «да» (стадия 02)
**Briefed:** <written by scripts/cycle.sh briefed>
**Branch:** vulyk/w2-n4-tails
**Checked:** <written by scripts/human-check.sh>
**Council:** GREEN round 1, 2026-09-29, at 9be5ad4c, pack dbb1f617c04e
**Shipped:** <written by scripts/ship-check.sh --record>
