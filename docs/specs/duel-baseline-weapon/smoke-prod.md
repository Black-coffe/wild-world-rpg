# Прод-смоук — duel-baseline-weapon (v0.51.693)

**Дата:** 2026-10-09 · **Окружение:** прод `wildworld.fun` (`wildworld-bot`), только чтение — живой проход на
проде не делаем. Деплой тега: GitHub Actions run 37888821799 — success.

- Миграции `2026-12-18-100000` и `2026-12-18-100010` записаны в `migrations`.
- `pvp.duel.baseline_health` 1000 → **200** (`updated_by` NULL — не правили руками), умолчание 200;
  `baseline_weapon_damage` 10, `weapon_advantage_weight` 0.5, `dodge_percent` 40; `pvp.duel.enabled` = 1.
- Совет `ArenaBaselineWeapon` в `game_tips`, категория «бой».
- `app/Services/PVE/DuelEquipmentRepository.php` в релизе.
- `ERROR|CRITICAL` за сегодня с упоминанием duel/arena/battle/PvpEquip — 0. Строк `DUEL` на проде до релиза — 0.
