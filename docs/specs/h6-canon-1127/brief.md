# H6.2 — canon and lore match the code (brief)
**Date:** 2026-11-27 · **Owner:** Andrei

## Request (verbatim)
> H6 тремя спеками (Рекомендую)

> Выпуск H6.1, потом H6.2 (Рекомендую)

(Owner's picks on 2026-11-27. The first option text was: «(2) сверка канона и лора с кодом». The second option
text was: «Пока идёт деплой, открываю H6.2 (канон и лор)». This spec is part (2) of H6.)

Source item, `ROADMAP.md:256-259` (roadmap text, not the owner's words):
> (2) `GAME_DESCRIPTION.md` и `mmorpg-vault/lore/` с кодом (сверены последний раз на
> v0.51.148): нет Узлов, поселений, Оракула, Трофейной подати, транспорта, рефералов, рейтинга PvP; «T4» (`:336`,
> в коде T3, ADR-029); «редкая еда первой» (`:471`, код ест обычную первой); фракционные PvP-модификаторы (`:698`, в коде
> 0); «мистические эффекты» Северного сияния (`:490`, в коде прибавка к статам); `IslandFarm` в списке стратобъектов
> (`:746`);

`ROADMAP.md:62`:
> **H6** канон-синк — развилки W11/W12 описать как «готовится» (вердикт D3: включение — D3-ON после H6)

## Answers
- 2026-11-27 Recon + prod SELECT (`game_settings`, 27.11): on — Узлы (`world.nodes.point_mode_enabled`=1,
  `announce_enabled`=0), поселения (`settlements.*`=1), Оракул (`oracle.enabled`=1), транспорт (`world.vehicle.enabled`=1),
  рефералы (`referral.enabled`=1), рейтинг PvP (`pvp.ladder.enabled`=1); off — Трофейная подать (`tribute.enabled`=0),
  ветвление (`quests.branching_enabled`=0); Сердце острова dormant (`world.strategic.islandheart.max_spawns`=0).
- 2026-11-27 Recon: the roadmap's «`IslandFarm` в списке стратобъектов (`:746`)» is inverted — IslandFarm (Старая ферма)
  is live and is *missing* from the `:746` list; IslandHeart gives no faction points. Faction names on prod are Russian
  (Милитари…), so the only faction modifier (`PvPController.php:193`, admin simulator, `== 'Militia'`) never fires.
- 2026-11-27 Recon: a live player tip on prod, `game_tips` id 47 `EndgameCraftT4` «🏆 Эндгейм-крафт (T4)», repeats the
  T4 claim — added as ask 8.

## Asks
1. Each of the five wrong claims named in the source item is corrected in `GAME_DESCRIPTION.md` to what the code
   does today, citing the code (`file:line`) or the ADR: «T4», «редкая еда первой», the faction PvP modifiers, the
   Northern Lights «мистические эффекты», and the strategic objects list at `:746` (add Старая ферма→Фермеры; IslandHeart
   gives no faction points); the discrepancies table row naming only three objects is updated too.
2. `GAME_DESCRIPTION.md` gets a short section for each shipped system it lacks: Узлы, поселения, Оракул,
   Трофейная подать, транспорт, рефералы, рейтинг PvP. Each section says whether the system is live or switched off
   on prod (a prod `game_settings` SELECT, not code defaults), and describes switched-off mechanics as off or
   «готовится», never as live.
3. Branching quests (W11) and their epilogues (W12) are described as «готовится», not as live.
4. `mmorpg-vault/lore/` notes that contradict the corrected canon are fixed, or marked with the correct claim;
   notes for the missing systems are added or linked from `lore/index.md`.
5. No setting words that break the setting (no magic: rule `player-text` «Сеттинг без магии»), and no
   new balance numbers copied into the canon. Where a number is quoted, its source is named (`GameSettings` key or the
   `Config\*` class holding it).
6. The three artifact renames (part 3 of H6) are not done here, and the canon keeps their current names.
7. Verdicts recorded: `/guide` — no change (lore section later, per the roadmap); new tip — no; WipeManifest — no;
   tech-writing — none (no model/service/handler/controller touched); the canon sync stamp names the version.
8. Tip id 47 `EndgameCraftT4` no longer says «T4»: an idempotent seed migration keyed by `title_en` rewrites its title
   and text to the 2-workbench / 3-level canon (Профессиональный верстак + фракция + квест захвата), tone of Роби, no
   balance numbers; the change is checked on prod with a SELECT after deploy.
