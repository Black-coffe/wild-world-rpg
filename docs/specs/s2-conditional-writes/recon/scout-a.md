# Scout A — F4, F8, F9, F10, F14, F22 (2026-10-28, HEAD 17435721)

Partial: Grep/Glob в субагенте возвращали пусто (известное, `env_subagent_glob_and_dir_grep_empty`), всё — прямым Read.

## Примитив (`app/Services/Db/`)
- `decrementIfAtLeast(table,rowId,column,amount,deleteWhenEmpty)`, `transitionIfCurrent(table,rowId,column,from,to)` — ключ только `id`.
- `increment(table,where[],column,amount)` — Applied|Missing. `insertUnique(table,row)` — Refused на дубле.
- Нет условного DELETE и CAS по произвольному предикату (`last_looted_at < now-cd`) — инлайн SQL.
- Транзакций не открывает; `Refused` не валит транзакцию вызывающего — откат явно.
- `NamedLock::withLock(name,fn)` — `GET_LOCK(?,0)`, null при занятом, один лок на запрос.

## Статусы — все STILL PRESENT
| F | Где | Суть | Файлы правки | PoC (все GREEN, последовательно) | Текст отказа |
|---|---|---|---|---|---|
| F4 | `Camp/Buildings/Greenhouse/PlantCropActionStart.php:97-118` | `quantity - 1` без `>=1` и без affectedRows; гейт — чтение `:77-81`; transStart есть | этот файл | `GapAuditTest::testSeedDeductionCannotDriveQuantityNegative` | есть `:80` |
| F8 | `Services/NPC/NpcInteractionService.php` fight `:348-383` (attack `:369` → consumeSpawn `:376`), rob `:312-339` (increaseGold `:329` → consumeSpawn `:330`), consumeSpawn `:386-391` | спавн «мёртв» после наград; транзакции нет | сервис; возможно `Player/PvEService::attack` (общий с кроном `AutoPveHandler` — не проверено) | `DuplicationTest::testOneRewardPerSpawn` | «NPC уже исчез.» |
| F9 | `Robots/RobotRepairConfirmAction.php:50-81`; `Drone/RepairDroneRunAction.php:59-71` (durability до gold); `Services/Player/FactionProjectService.php:133-165` (progress тоже со снимка) | `decrement('gold')` после проверки-чтения | 3 файла; `decrementIfAtLeast('characters',id,'gold')` ложится прямо | `DuplicationTest::testGoldNeverNegativeAfterDoubleRepairConfirm`, `…RepairThenDroneBatch`, `testFactionDepositNeverOverdrawsGold` | есть во всех трёх |
| F10 | `Services/Settlement/RuinLootService.php:128-160` — `touch()` после лута `:157`; аномалии через тот же сервис (`AnomalyLootAction.php:49` — не проверено) | кулдаун не резервируется | сервис + модель лога (`character_ruin_loot`, UNIQUE(character_id,settlement_id)) — примитив не подходит, инлайн UPDATE+affectedRows / insertUnique | `GapAuditTest::testRuinLootAwardsOncePerCooldownWindow` | `reason=cooldown` уже обрабатывается |
| F14 | `Actions/UsePharmacyAction.php` — лечение `:249`, cureByItem `:247`, maybeApplyWellFed `:90` до списания `decrementItemUsage :259-315` (двухуровневый счётчик durability_count→quantity→delete) | доза после эффектов; транзакции нет | этот файл | `DuplicationTest::testOneHealPerMedicineCharge` | есть `:73`, `:260` |
| F22 | `Services/Player/VehicleActivationService.php:100-110` | `activate()` проверяет только владение | этот файл: `VehicleEffectsService::keyForItemNameEng(name_eng)` не пуст | нет | не проверено, что показывает вызывающий на false |

## Коллизии
Продовые файлы не пересекаются. Общие тест-файлы: `tests/exploit-poc/DuplicationTest.php` (F8, F9, F14), `GapAuditTest.php` (F4, F10).

## Не проверено
Аномалии (`AnomalyLootAction`), модель лога руин, вызывающие `activate()` / `PvEService::attack` / AutoPveHandler, обычные тесты в `tests/`, текущие пользователи `ConditionalWriteService`.
Киллсвитчи прода по аудиту (не перепроверены): `npc.interaction_enabled`, `settlements.ruins.enabled`, `world.anomalies.enabled` — ON.
