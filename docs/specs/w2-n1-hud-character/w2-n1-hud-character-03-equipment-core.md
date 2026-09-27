---
story: w2-n1-hud-character-03
spec: w2-n1-hud-character
status: done
returned: DONE
tier: 2
worker: worker-code
model: opus
wave: 3
blocked_by: [w2-n1-hud-character-01]
---

# Модель снаряжения, атомарное надевание, нативный экран

## Goal
`EquipmentLoadoutService`: `forCharacter(int $id)` — модель снаряжения (оружие и броня, надетое,
характеристики, lock-состояние «нужен Арсенал» с объяснением и путём); `equip(int $charId, string $kind,
int $itemRowId)` и `unequip(...)` — все проверки из `ToggleEquip*Action` плюс атомарная смена в одной
транзакции (в слоте не больше одного надетого). `GearAction`, `GearWeaponsAction`, `GearArmorAction`,
`Gear*DetailAction`, `ToggleEquip*Action` бота становятся рендерерами модели. В вебе `view=gear` с
надеть/снять; мутации идут с `intent_id` и дедупом.

## Requirements
> 4. «Снаряжение» — одна модель снаряжения в сервисе; в вебе видно оружие и броню, надетое отмечено, надеть и снять можно из веба; экраны снаряжения бота рисуются из той же модели.
> 5. Надевание и снятие — один сервис, его зовут и бот, и веб; смена атомарна (двух надетых в одном слоте не бывает); механика и гейт Арсенала для игрока не меняются, handler'ы бота становятся тонкими рендерерами.
> 8. Экипировка недоступна (нет Арсенала) — экран показывает замок с объяснением и путём к нему, а не ошибку после тапа, в обоих клиентах.

## Files
- app/Services/Player/EquipmentLoadoutService.php
- app/Controllers/Telegram/Commands/Profile/GearAction.php
- app/Controllers/Telegram/Commands/Profile/GearWeaponsAction.php
- app/Controllers/Telegram/Commands/Profile/GearArmorAction.php
- app/Controllers/Telegram/Commands/Profile/GearWeaponDetailAction.php
- app/Controllers/Telegram/Commands/Profile/GearArmorDetailAction.php
- app/Controllers/Telegram/Commands/Profile/ToggleEquipWeaponAction.php
- app/Controllers/Telegram/Commands/Profile/ToggleEquipArmorAction.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_gear.php
- app/Views/site/_play/native_me.php
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- tests/database/EquipmentLoadoutServiceTest.php
- phpstan-baseline.neon

## Non-goals
- Продажа экипировки (ADR-165) и страховка остаются как есть (в вебе — мост).
- Не менять бонусы `EquipmentService` и боевые формулы.
- Не вводить новые слоты и типы предметов.

## Map slice
`memory/map/player.md`; `memory/map/pve-pvp.md` (EquipmentService, бонусы).

## Acceptance criteria
- [ ] Тест: два параллельных `equip` в один слот оставляют ровно один надетый предмет.
- [ ] Все отказы прежних handler'ов (нет Арсенала, предмет не твой и т.п.) воспроизводятся сервисом с теми же смыслами.
- [ ] Без Арсенала бот и веб показывают замок «🔒 … (нужно: Арсенал)» с путём, а не ошибку после тапа.
- [ ] Надеть в вебе — бот показывает предмет надетым; и наоборот.
- [ ] Повтор POST с тем же `intent_id` не меняет состояние второй раз.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- Модель: EquipmentLoadoutService - forCharacter() (arsenal, lock с required_level из BuildLockService и путём genericBuildInfo_Arsenal, on_base, sale_enabled, weapons/armor), item(), equip/unequip/toggle; проверки в порядке прежних handler'ов, тексты отказов - статический refusal(kind, code, name), формулировки прежние.
- Атомарность: смена - один UPDATE на слот `equipped = IF(id=?,1,0)`; слот брони эффективный `COALESCE(NULLIF(outfits.slot,''), characters_outfits.slot)` - строка склада с пустым slot в справочнике больше не выпадает из «снять остальных».
- Гейт Арсенала у персонажа теперь и в toggleEquipArmor (раньше только справочник); для игрока не меняется - список брони и так гейтил.
- Бот: семь Gear*/ToggleEquip* - тонкие рендереры модели. Хаб «⚔️ Экип» без Арсенала - lock-кнопки «🔒 … (нужно: Арсенал)» + «🏗 К стройке Арсенала»; переключатель брони - один ряд из двух кнопок.
- Веб: view=gear, op=equip|unequip + kind + item + intent_id; дедуп `intent_id:gear` в web_play_intents; без JS - flash + PRG /play?view=gear. Продажа/стройка Арсенала - через мост (BRIDGE_PATTERNS, genericBuildInfo_Arsenal).
- Сверх Files: phpstan-baseline.neon - убраны 69 записей семи переписанных handler'ов.
- Проверено: phpstan L9 OK; EquipmentLoadoutServiceTest - 14 тестов OK (смена в слоте, лечение двух надетых, все отказы, снятие вне базы, lock, идемпотентность веб-намерения и видимость для бота).

## Findings
