---
id: preprod-settings-drift
title: Preprod-смоук идёт на другой игре, чем прод
status: block
check: php scripts/defects-preprod-parity-check.php <arg>
fixtures: [docs/defects/fixtures/preprod-settings-drift-original.tsv, docs/defects/fixtures/preprod-settings-drift-neighbour.tsv]
keys: [preprod, препрод, testbot, не ту игру, выключены, зелёные смоуки]
paths: ["app/Database/Migrations/**", "deploy/**", "cmd:wildworld-testbot@", "cmd:game_settings"]
---

# Preprod-смоук идёт на другой игре, чем прод

Зелёный смоук на preprod-testbot — постоянное добро на прод-тег. Но фичи на проде включаются руками
(админка, SQL), а миграции сеют их выключенными; на preprod те же флаги никто не включал. К 2026-10-11
расходились 75 ключей `game_settings`, из них 57 — живые механики прода (онбординг fast_start/first_build/
lucky_find/win_beat/build_locks, march-события, дебаффы, лестница прогрессии, руины, телепорты…), выключенные
на preprod. Смоук проверял изменённый экран, но не его встречу с половиной живой игры.

## Цитаты владельца
- 2026-10-11 · сессия W-REG, холодный проход по вебу · preprod-testbot `game_settings` — «На preprod были выключены около 60 фич, которые на проде включены. Поэтому прежние «зелёные» смоуки на preprod, после которых ставился прод-тег, проверяли не ту игру.»

## Cause
Активация фичи на проде — ручной UPDATE через админку (май 9, июнь 36, июль 4, август 8 ключей); миграция
сеет `false`, и на preprod ручной шаг не повторялся. Сверки окружений перед смоуком не было.

## Нельзя
- Начинать preprod-смоук, по итогу которого ставится прод-тег, без зелёного `bash scripts/preprod-parity.sh`.
- Включать фичу на проде руками и не включить её на preprod тем же заходом.
- Добавлять ключ в `ALLOWED` проверки без причины в комментарии; число баланса туда не попадает никогда.

## Можно
- Намеренные расхождения из `ALLOWED`: штампы кронов, массовые рассылки тест-аккаунтам, фичи, которые
  preprod обкатывает раньше прода (`community.*`, `tribute.*`, `web.open_registration`).

## Revoked
