---
paths: ["app/**"]
---
# Карта кода старого улья — раздел Gotchas

`memory/map/<домен>.md` — срезы старого VULYK (архив, только чтение; сверены по коду 2026-10-25…11-11).
Перед работой в домене прочитать в его срезе раздел `## Gotchas`: там то, обо что уже спотыкались.
Срез мог устареть — утверждение сверить с кодом, прежде чем опираться.

| Где работаешь | Срез |
|---|---|
| `Controllers/Telegram/**`, `Services/Telegram/**`, MediaSender | `telegram.md` |
| `TaskHandlers/**`, `Controllers/Worker.php` | `tasks-worker.md` |
| `Services/Player/**` | `player.md` |
| карта, биомы, Поход (`Services/World`, `Navigation`) | `world.md` |
| крафт, ремонт, износ | `craft.md` |
| лагерь, постройки, налог | `bases.md` |
| `Services/PVE/**` (PvE и PvP, дуэли, противостояние) | `pve-pvp.md` |
| квесты, события, NPC | `quests-events-npc.md` |
| онбординг, советы, `/guide` | `onboarding.md` |
| `Controllers/Admin/**`, `GameSettings`, `WipeManifest` | `admin.md` |
| сайт, `/play`, веб-аккаунты | `website.md` |
| модели, миграции, Entity | `data-layer.md` |

Актуальная карта подсистем — `mmorpg-vault/apps/<подсистема>/index.md`. Эксплойт-аудит: 34 находки и бэклог
F0–F30 — `docs/specs/exploit-audit/REPORT.md`, `FIX-BACKLOG.md`; PoC — `tests/exploit-poc/` (вне дефолтного
набора, гонять по одному файлу).
