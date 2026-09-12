---
story: bugs-thread-triage-10
spec: bugs-thread-triage
status: todo
tier: 2
worker: worker-code
tracer: false
wave: 5
blocked_by: [bugs-thread-triage-09]
---

# 🔴 Ремонт критического: черновик про транспорт обещает то, чего нет

## Goal

Единственная находка ревью уровня CRITICAL. Черновик ответа Max Syskov'у (`4294974544`) обещает
игрокам предмет без пути крафта, с уровнями из источника, который экран не читает, и умалчивает
о фракционном гейте. Это сообщение стояло в очереди на отправку в живой чат.

## Requirements

> Если да, пишешь ответ, что данная система устранена, бага нет.

> смотришь: мы эту систему поправили, баг устранили?

## Files
- docs/specs/bugs-thread-triage/verdicts/world.md

## Что делать

Ревью установило три независимых дефекта в одном абзаце:

1. **«Плот с 5 уровня» — Плота нет в витрине.** Выкаченная витрина (`CraftedResourcesAction`,
   константа `TRANSPORT_VEHICLES`) предлагает ровно пять машин: LightCart, MountainBike,
   Snowmobile, DraftCart, AutonomousDrone. Плот среди них отсутствует, а миграция
   `2026-11-29-100000_TransportCatalogCleanup.php:30` говорит, что Плот (id 42) заморожен намеренно.
2. **Уровни взяты не оттуда.** Вердикт прочитал колонку `crafted_items.required_level`, которой
   экран не касается. Гейт считает `RecipeGateResolver::requiredLevel()` по `Config\CraftRecipes`:
   LightCart 6, MountainBike 12, Snowmobile 14, DraftCart 14, AutonomousDrone 16 — и каждый
   живо переопределяется ключом `world.vehicle.*.required_level` в GameSettings.
3. **Фракционный гейт не упомянут.** Три машины из пяти заперты за фракцию (Партизаны / Милитари /
   Фермеры / Инженеры). Игрок не той фракции на 20-м уровне никогда не скрафтит обещанный снегоход.

Перепиши черновик так, чтобы он называл **только те машины, которые витрина действительно
предлагает**, и либо честно назвал гейт, который считает сам экран (уровень из `RecipeGateResolver`
плюс фракция), **либо не нёс чисел вообще**. Второе безопаснее: числа переопределяются из админки
и протухнут (`feedback_guide_coverage_redkollegiya`).

Проверь свои утверждения **на проде**, а не в локальном дереве: прочитай выкаченный
`CraftedResourcesAction.php` и значения `world.vehicle.*` в `game_settings` на проде.

Сам вердикт `устранено` под сомнение не ставится: транспорт на проде включён с 2026-08-20
(`world.vehicle.enabled=1`), и это перепроверено дважды. Чинится **текст обещания**, не вердикт.

## Non-goals
- Не менять вердикт `4294974544` — он верен.
- Не трогать `REPORT.md` (его пересобирает story 12) и чужие файлы вердиктов.
- Не чинить код игры и не заводить story на заморозку Плота.

## Map slice
`memory/map/craft.md`, `memory/map/world.md`, `docs/specs/bugs-thread-triage/RECON.md`

## Acceptance criteria
- [ ] В черновике не упомянут ни один транспорт вне пяти позиций витрины.
- [ ] Либо назван настоящий гейт (уровень + фракция), либо чисел нет вовсе.
- [ ] Источник уровней прочитан на проде, а не из `crafted_items.required_level`.
- [ ] Заодно исправлена MINOR-8: поле «На проде» для `430775f8` цитирует то, что команда реально
      печатает (первым идёт `backup-website-2026-05-25`, а не `v0.1.0`), либо использует проверку,
      которую не сбивают неверсионные теги — `git merge-base --is-ancestor <sha> v0.51.666`.

## Verification
`bash docs/specs/bugs-thread-triage/verdict-lint.sh docs/specs/bugs-thread-triage/verdicts/world.md`

## Implementation notes

## Findings
