---
story: bugs-info-0923-06
spec: bugs-info-0923
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
tracer: false
wave: 2
blocked_by: [bugs-info-0923-05]
---

# «Одеть» уходит и из миграций совета ArmorScreen

## Goal
Ask 5 в раунде 1 получил RED только на своей буквальной проверке. `git grep -n "Одеть" -- app` находит 4 строки, и все они в миграциях: старый сид `2026-10-24-100000_SeedArmorScreenTip.php:42` и новая `2026-12-08-100000_FixArmorScreenTipNadet.php` (комментарий и строки `REPLACE` в `up()`/`down()`). Кнопки уже подписаны «Надеть». После этой истории слова нет нигде в `app/`. Свежая БД сразу получает совет ArmorScreen с «*Надеть*», а preprod и прод получают тот же текст через fix-миграцию. Сам текст фикса старое слово больше не упоминает.

## Requirements
> "надеть"
> Кнопка экипировки на карточках брони и оружия — «Надеть» (роутинг не меняется); `git grep -n "Одеть" -- app` пуст.

## Files
- app/Database/Migrations/2026-10-24-100000_SeedArmorScreenTip.php
- app/Database/Migrations/2026-12-08-100000_FixArmorScreenTipNadet.php

## Non-goals
- Не маскировать слово: никаких `"\u{041E}деть"`, `'О' . 'деть'`, `CONCAT(...)`, HEX-литералов и других обходов grep. Совет такой обход увидит, и это будет новый RED.
- Не удалять `FixArmorScreenTipNadet`: без неё на preprod и проде, где сид уже применён, совет останется со старым словом.
- Не менять ключ `title_en`, категорию, тон и остальной текст совета. Меняется только слово в кнопке.
- Не трогать gear-/toggle-action'ы и тесты: они уже закрыты историей 05.

## Map slice
`memory/map/` — не нужен. Нужны только два файла миграций выше: схема `game_tips` видна по insert-массиву сида.

## Acceptance criteria
- [ ] Сид `SeedArmorScreenTip.php`: в тексте совета «*Одеть*» заменено на «*Надеть*», остальная строка не меняется байт в байт. Если в файле есть комментарии со старым словом, они тоже переписаны.
- [ ] `FixArmorScreenTipNadet.php::up()` делает `UPDATE game_tips SET content = <полный текст совета из исправленного сида> WHERE title_en = 'ArmorScreen'` через привязку параметра. Операция идемпотентна: повторный прогон ничего не меняет. `down()` — осознанный no-op с комментарием «текстовая правка данных, отката нет», старое слово не упоминается.
- [ ] `git grep -n "Одеть" -- app` пуст.
- [ ] `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null` молчит. Полный набор на свежей пустой тест-БД не даёт новых падений: миграции применяются, сид и fix дают один и тот же текст.

## Verification
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
`vendor/bin/phpunit --no-coverage --no-progress`

## Implementation notes
- `SeedArmorScreenTip.php`: заменено единственное вхождение «Одеть» → «Надеть» в тексте совета (кнопка на карточке брони), остальной текст байт в байт тот же.
- `FixArmorScreenTipNadet.php`: `up()` переписан на `update(['content' => $content])` с полным исправленным текстом совета через builder (bound param), вместо `REPLACE()` со старым словом в теле запроса — идемпотентно (повторный запуск пишет тот же текст). `down()` — no-op с комментарием «текстовая правка данных, отката нет», старое слово нигде не упоминается.
- `git grep -n "Одеть" -- app` пуст; `php -l` по всем миграциям чист; полный `vendor/bin/phpunit` зелёный (OK, 4230 тестов, посторонние deprecation/skip не связаны с этой правкой).
- WipeManifest не тронут: правка не создаёт таблицу/колонку, только текст существующей строки `game_tips`.

## Findings
