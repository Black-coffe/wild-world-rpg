# Preprod-смоук — duel-baseline-weapon

**Дата:** 2026-10-09 · **Окружение:** testbot (`wildworld-testbot`), выкатка `develop` @ `d4207aac` (GitHub Actions run
37850328905, ✓) · **Тир:** 3 автономно (POST на вебхук + `curl` карточки `/play`), без браузера.

## Стенд — исходный случай дефекта
- 491 `aviad_echo` (tg 6995661239) и 522 `Веб92432`: **оба без оружия** (`characters_weapons.equipped=1` — 0),
  клетки `328454` и `312911` — **457 клеток** друг от друга (как в жалобе владельца).
- `pvp.duel.enabled=1`; 522 `duels_open` 0 → 1 на время смоука.

## Настройки после миграции (testbot)
| ключ | значение | умолчание | мягкий min |
|---|---|---|---|
| `pvp.duel.baseline_health` | 200 (было 1000, `updated_by` NULL) | 200 | 100 |
| `pvp.duel.baseline_weapon_damage` | 10 | 10 | 5 |
| `pvp.duel.weapon_advantage_weight` | 0.5 | 0.5 | 0.3 |
| `pvp.duel.dodge_percent` | 40 | 40 | 25 |

Совет `ArenaBaselineWeapon` в `game_tips`, категория «бой».

## Бот (вебхук testbot, настоящий роутер)
- `arenaDuel_522` от 491 → http 200, firehose #850 `arenaDuel` **ok** → `battle_logs` #33 `DUEL` 491 vs 522:
  `outcome = {type: normal, reason: knockout, winnerId: 522}`, **38 раундов** (было 150 и «по стажу»).
  Раунды: `D_equip` 10 (базовое оружие, дистанция не режет), уворот 40, HP 200 → 0, удары 12.6 / ⚡16.
- `battleLog_33` → http 200, firehose #851 `battleLog` **ok** (карточка разбора в боте).
- `ERROR|CRITICAL` в логе testbot за сутки — пусто.

## /play (remember-токен account 3, `curl`)
- `GET /play?view=battle&id=33` → 200: «Итог ❌ Поражение · Бой 🤺 Дуэль · Раундов 38», «🤺 Дуэль — без потерь…»,
  раунды подряд от «1. aviad_echo → Веб92432 промах · осталось 200 HP» до «38. Веб92432 → aviad_echo −16 ·
  осталось 0 HP ⚡». Ни одного «−0». Токен удалён.

## Не делали
- Tier-2 визуальный проход 1440/768/375 по `native_battle.php`: правка — только цифры форматтера (`$num`), разметка
  и CSS не тронуты; ширина строки раунда та же («−0» → «−0.01» не встречается в новых боях).
- Дуэль из веба (`op=duel`): ядро то же (`ArenaScreenService::challenge`), путь веба проверен смоуком w2-n7-combat.

## Уборка
522 `duels_open` → 0, `account_tokens` account 3 remember → 0, хелпер с сервера удалён. Строка `DUEL` #33 оставлена.
