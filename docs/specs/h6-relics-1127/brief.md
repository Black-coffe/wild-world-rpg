# H6.3: post-apocalyptic names for three artifacts + Pipe Gun name (brief)
**Date:** 2026-11-27 · **Owner:** Andrei

## Request (verbatim)
> H6.3 + Pipe Gun (Рекомендую)

(The owner picked this option on 2026-11-27. Its description read: «Последняя часть H6, без неё канон к D3-ON не
честный. Переименовываем артефакты 69/70/71 в постапок, у 10–12 держателей ничего не забираем. collection_slots
правим той же миграцией. «Трубчатый пистолет (Pipe Gun)» идёт отдельным story того же класса: английский в UI.
Сначала только SELECT на проде. Минус: трогаем данные живых игроков, нужен Tier-3 на preprod.»)

Source item, `ROADMAP.md:257-259` (roadmap text, not the owner's words):
> (3) 🔴 §4.3 — переименовать «Сердце Дракона» (69), «Древняя Книга» (70), «Корона подземного короля» (71) в
> постапок без изъятия у 10–12 держателей, `collection_slots.resource_name` — той же миграцией. Раздел лора в `/guide` —
> позже.

Tail from `ROADMAP.md:256`:
> хвост: имя оружия «Трубчатый пистолет (Pipe Gun)» с английским в скобках — правка данных отдельно

## Answers
- 2026-11-27, names: «Имена из роадмепа (Рекомендую)» — «Изотопное ядро „Дракон-4“», «Архивный носитель Предтеч», «Релейный венец Глубины» (`docs/roadmaps/LORE_AND_GAMEPLAY_ROADMAP.md:122-124`). Descriptions are rewritten too; «Дракон-4» reads as a product index, not a creature.
- 2026-11-27, scope: «Весь класс (Рекомендую)» — both weapons «Трубчатый пистолет (Pipe Gun)» and «Ракетница (Firebomb)», the hardcoded «(Pipe Gun)» on the craft screen, and task 56 «Создание самодельного Pipe Gun».

## Asks
1. Artifacts 69, 70, 71 are named «Изотопное ядро „Дракон-4“», «Архивный носитель Предтеч», «Релейный венец Глубины» everywhere a player sees them: inventory, collection screen, find messages, `/play`, `GAME_DESCRIPTION.md`, the site page about cave resources (live after deploy without a manual `php spark` run).
1a. Their descriptions are post-apocalyptic, promise no effect the code does not have (no «+10 ко всем характеристикам раз в 10 дней»), and «Дракон-4» reads as a product index.
2. No holder loses an artifact: the count of holders and the quantities held of 69/70/71 on prod are the same before and after the migration.
3. `collection_slots.resource_name` rows that name these artifacts are updated by the same migration, so collections stay complete for players who had them.
4. No English in brackets on player screens for this class: weapons 3 «Трубчатый пистолет» and 10 «Ракетница», the craft screen text (`WeaponsCraft2Select`), task 56 description — in the bot and in `/play`.
5. The migration is idempotent and safe to re-run; `php -l` passes.
5a. No `game_settings` text column (rationale, effect, above, below) names the old relics after the migration (admin text).
6. Verdicts recorded: guide «нет — раздел лора позже (ROADMAP)», tips — yes/no with reason; WipeManifest untouched unless a new table/column appears.
7. Tier-3 on preprod-testbot shows the new names in the bot and in `/play`.

- 2026-11-27, review round 1 minor 2: «Починить minor 2, потом мердж (Рекомендую)» — the same migration also replaces the old relic names in every `game_settings` text column (rationale/effect/above/below), with a test.
