# Preprod-смоук — duel-first-strike-coin

**Дата:** 2026-10-11 · **Окружение:** testbot (`wildworld-testbot`), релиз `2026-10-11-184015` (`develop` @ `f3fa6b9f`,
GitHub Actions run 38164551893, ✓) · **Тир:** 3 автономно (POST на вебхук, настоящий роутер), без браузера.

## Миграции
- `FixArmorResistancesTipMarkdown` применена (batch 388). Совет `ArmorResistancesPvP` (id 66 на testbot):
  `armor_value` — нет, «базовая защита брони» — есть.

## Дуэли 491 → 522 (оба без оружия, клетки в 457 друг от друга), кулдаун 30 с
| battle_logs | вызвавший (журнал) | первый удар | победитель | раундов |
|---|---|---|---|---|
| 34 | aviad_echo (491) | aviad_echo | 491 | 39 |
| 35 | aviad_echo (491) | Веб92432 | 522 | 41 |
| 36 | aviad_echo (491) | Веб92432 | 522 | 39 |
| 37 | aviad_echo (491) | Веб92432 | 491 | 42 |
| 38 | aviad_echo (491) | Веб92432 | 491 | 52 |
| 39 | aviad_echo (491) | aviad_echo | 522 | 44 |

Первый удар — жребием (2 у вызвавшего, 4 у защитника); роль «вызвавший» в журнале не меняется; каждый бой — нокаут.
Firehose: `arenaDuel` ok ×6, `battleLog_35` → `battleLog` ok. `ERROR|CRITICAL` в логе testbot за сегодня — пусто.

## Уборка
522 `duels_open` → 0, хелпер с сервера удалён. Строки `DUEL` #34–39 оставлены.
