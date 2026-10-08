# Smoke prod — w2-n7-combat, v0.51.692 (2026-10-08)

Тег `v0.51.692` → GitHub Actions run 37837585710 success. Только чтение: на проде живые игроки, дуэлей и POST на вебхук нет.

- `battle_logs.battle_type` = `varchar(8)`; совет `BattleJournal` (id 151, `бой`) засеян.
- `GET /battles` 200; `/battles/view/74881` (PVP) 200; `/battles/view/74958` (PVE) **404** — запись видна только участникам.
- `GET /play` гостем — 302 на вход.
- Лог ошибок: только фоновые «bot was blocked by the user» (LowHealthWarning, Greenhouse) — не из релиза.
- Флаги прода: `pvp.duel.enabled=1`, `pvp.ladder.enabled=1`, `pvp.duel.baseline_*` = 20 / 50 / 1000 (как на testbot).

Открыто после шипа (поправка владельца): дуэль бойцов без оружия бьёт по 0,01 и решается тай-брейком по стажу —
`docs/defects/duel-outcome-not-decided-by-fight.md`. Механика с W17 (ADR-071/073), W2.N7 её не меняла; фикс — отдельная спека.
