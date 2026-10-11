# Прод-смоук — web-native-firehose (v0.51.695)

**Дата:** 2026-10-11 22:50 · релиз `2026-10-11-194924` (run 38169292557 ✓) · только чтение.

- `player_action_log` за 30 мин: бот пишет как прежде (`callback` ok 434, `text` ok 9, `task` 1); веб-строк пока нет —
  в `/play` за эти минуты никто не заходил. Проверить завтра: `SELECT COUNT(*) FROM player_action_log WHERE source='web'`
  и WAU по ROADMAP §0.
- Лог прода после выкатки: ERROR/CRITICAL, кроме «bot was blocked», — нет.
