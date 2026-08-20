---
story: transport-08
spec: transport-system
status: done
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 1
blocked_by: [transport-02, transport-03]
---

# Груз: доля добытого уезжает на склад базы

## Goal

Грузовая машина начинает возить: доля добытого сырья кладётся сразу на склад базы (путь
карго-дрона, ADR-171), остальное — в рюкзак. Ничего не создаётся, только перекладывается:
ценность при спящем весе не в ёмкости, а в риске — смерть забирает из рюкзака, склад уцелевает.
Реализация — в **единственной точке записи** `GatherResultPersister`, второго write-path нет.

## Requirements

> И они должны быть разные по типам: и груз перевозить, и персонажа перевозить

## Files
- app/Services/Player/Gather/GatherResultPersister.php
- tests/unit/Transport/CargoSplitTest.php

## Non-goals
- 🔴 Никакого «+% к добыче»: это новый кран — ровно то, что закрывал ADR-173 позавчера.
- Не будить `inventory.weight_cap` (killswitch выключен): вторая система на 687 активных игроков поверх этой задачи.
- Не делить крафт-предметы — только сырьё.
- Не заводить второй путь записи добычи: если найдётся ещё один write-path, воркер **останавливается** и пишет в `## Findings`, а не правит оба.
- Не менять состав и объём добычи ни на единицу.

## Map slice
`memory/map/craft.md` (добыча и её запись), `memory/map/bases.md` (склад базы, путь карго-дрона), `memory/map/player.md`

## Acceptance criteria
- [ ] 🔴 Сохранение массы: `дельта рюкзака + дельта склада == добытому` для каждого ресурса, при любой доле, включая 0.0 и 0.33.
- [ ] Нет базы → 100% в рюкзак, доля не применяется, игрок видит честный текст «груз некуда везти».
- [ ] Килсвитч off / нет транспорта / машина без груза (`mtb`, `drone_auto`) → 100% в рюкзак, числа байт-идентичны сегодняшним.
- [ ] Доля считается `floor(qty × share)` по каждому ресурсу; на стеке в одну единицу это честный ноль — тест фиксирует порог срабатывания (минимальный `qty`, при котором на склад уезжает хотя бы 1) для каждой из трёх грузовых машин.
- [ ] `cargo_share` берётся из профиля и зажат сверху `world.vehicle.cargo.max_share`; при `max_share=0` (состояние шага 1 выката) поведение полностью сегодняшнее.
- [ ] Текст результата добычи называет, сколько и чего уехало на склад — самодостаточно, без картинки.
- [ ] Тест поведенческий: проверяются итоговые остатки в рюкзаке и на складе, а не факт вызова сплиттера.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Transport/CargoSplitTest.php`

## Implementation notes

- `GatherResultPersister::writeCharacterResources()` now resolves the active vehicle's cargo
  profile (`VehicleActivationService::resolveActive()` → `VehicleEffectsService::profileFor()`,
  terrain param fixed to `TERRAIN_EXPLORED` since `cargo_share` doesn't vary by terrain) and
  splits via a new pure static `splitForCargo()`: `toStorage = floor(amount * share)`,
  `toBackpack = amount - toStorage` — mass conservation is structural (subtraction, not two
  independent formulas), not something that can drift.
- No base (`ClaimedCellModel::countActiveBases()===0`) → 100% backpack + `composeNoBaseNote()`
  text. Killswitch off / no vehicle / `cargo_share===0.0` (mtb, drone_auto) → 100% backpack,
  no storage write, no message — byte-identical to pre-story behavior (verified: same
  `creditBackpack()` path as the original `writeCharacterResources()` body).
- **Floor threshold per vehicle** (computed from `plan.md → Contracts` cargo shares): `cart`
  (0.20) needs qty≥**5** before 1 unit reaches storage; `snowmobile` (0.25) and `draft_cart`
  (0.33) both need qty≥**4**. Below that, `floor(qty×share)` is an honest zero — asserted in
  `CargoSplitTest::testCargoThresholdPerVehicle` (dataProvider) and
  `testFloorGivesHonestZeroOnSingleUnitStack`. `mtb`/`drone_auto` have `cargo_share=0.00` — no
  threshold, cargo never engages.
- Class de-`final`-ed to allow a test-double subclass (`FakeGatherResultPersister`) overriding
  4 seams: `resolveVehicleProfile()`, `hasBase()`, `chatIdFor()`/`send()`, `resourceNames()` —
  mirrors the existing `LevelUpNotifier`/`FakeLevelUpNotifier` pattern. The real write-path
  (`character_resources`/`base_storage` via `CharacterResourceModel`/`BaseStorageModel`) is
  NOT mocked — tests assert actual persisted row quantities, not that the splitter was called.
- **Scope tradeoff (flag for review):** the acceptance bullet "результат добычи называет,
  сколько уехало на склад" is delivered as a *separate* text-only Telegram message sent
  directly from `GatherResultPersister` (lazy `Telegram`/`Request::sendMessage`, same pattern
  as `LevelUpNotifier`), not merged into the existing photo+caption gather-result message
  built by `GatherTaskHandler`/`BaseServiceMessageFormatter`. Those files are outside this
  story's `## Files` list, so wiring the note into the single existing message wasn't
  possible without widening scope. Rejected alternative: silently mutate `$foundResources`
  and hope a later story reads it — rejected because `GatherTaskHandler` builds its reply
  from the array snapshot *before* `persist()` runs (same pre-existing gap `TributeService`
  already has for its own silent deduction), so nothing would actually surface without also
  touching `GatherTaskHandler`.
- Tribute (`TributeService::collectOnGather`) still runs first — cargo split operates on the
  post-tribute quantities the vassal actually keeps; `bumpCharacterStats` still sums over the
  original (pre-split, post-tribute) `$foundResources` entries — split only changes *where*
  a resource lands, not whether it was found, so stat/XP gain math is untouched.

## Findings
