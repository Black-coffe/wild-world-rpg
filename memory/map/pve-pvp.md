<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-11

# Scout report: Бой (Services/PVE — и PvE, и PvP)

## Purpose
Весь бой: расчёт урона, эффекты, экипировка, лут, боссы, дуэли, лестница PvP, трибуты,
оборонительные сооружения, лог боя.

## Entry points
- PvE: `BattleService.php`, `DamageService.php`, `EffectService.php`, `EquipmentService.php`,
  `RewardService.php`, `LootTableService.php`, `BossEncounterService.php`, `BossLootService.php`.
- PvP: `DuelService.php`, `PvpRoundOrchestrator.php`, `PvpDamageCalculator.php`,
  `PvpFormulaService.php`, `PvpLadderService.php`, `PvpRewardOrchestrator.php`,
  `PvpEquipmentRepository.php`, `PvpActivityContextService.php`, `PvpBattleLogBuilder.php`.
- Лог: `BattleLogger.php`, `PveBattleLogService.php`, `PveBattleLogWriter.php`.
- Периметр: `AntiCampService.php`, `DefenseStructureService.php`, `TowerAlertService.php`,
  `BountyService.php`, `TributeService.php`, `PveCombatValidator.php`.
- Контроллеры: `app/Controllers/BattlesController.php`, `PvPController.php`.

## Key types / contracts
Награда выдаётся **только победившему игроку** (`winner == player`), иначе получается FK-шторм.

## Dependencies
inbound: PvE-actions, PvP-actions, TaskHandlers `PVP/`.
outbound: `Services/Player` (статы, смерть, дебаффы), модели боя и логов.

## Окно противостояния (ADR-186, spec `pvp-detection-clarity`, killswitch `pvp.standoff.enabled`)

Попытка атаковать игрока, стоящего на своей базе с живой обороной, открывает окно вместо
мгновенного боя: защитник получает тревогу с тремя ходами (🛡 укрыться / 🏃 убежать /
⚔️ ударить первым), атакующий — экран ожидания с обратным отсчётом. Гейт в `AttackPlayerAction`
двухфазный: `resolveStandoffPreGate()` (только чтение — контратака, своя заморозка) идёт первым,
затем чтение анти-спам-кулдауна → `isCellsCloseEnough()` → `checkPvPAllowed()` → только после
этого `resolveStandoffOpen()` (пишет строку `pvp_standoffs` и шлёт тревогу защитнику). Точки
входа: `AttackPlayerAction`, три `Actions/PVP/Standoff{Check,Leave,Hold}Action`, `RunAwayAction`
(закрывает окно как `fled`), task-handler `App\TaskHandlers\PVP\StandoffExpiryHandler` (крон,
ленивое истечение). Сердце — `App\Services\PVE\PvpStandoffService` + `StandoffNotifier`
(`StandoffNotifier` без зависимости от `GameSettingsService` — снята как мёртвая), модель
`PvpStandoffModel` (`pvp_standoffs`).

Ловушки:
- **Открытие окна стоит после всех запретов, не до них.** Оно пишет в БД и шлёт Telegram-сообщение,
  поэтому обязано идти последним в цепочке гейтов — раньше оно стояло до смежности/ограничений, и
  тревогу можно было поднять с другого конца карты альтом первого уровня (закрыто волной фиксов
  `-13`/`-19`).
- **Ленивое истечение** — открытость окна решает `expires_at > NOW()` в момент чтения, крон только
  уведомляет постфактум; зависший крон не держит атакующего в заморозке.
- **Признак базы — непустой `structure_ids`**, не `getDefenseProfile() !== null` (тот возвращается
  и на одном боевом дроне без построек, ADR-064).
- **Профиль обороны при контратаке** резолвится для владельца базы, кто бы ни нажал «⚔️ Ударить
  первым» — иначе защита базы терялась бы в момент, когда ею воспользовались.
- **Кулдаун защитника армируют не все закрытия** — `COOLDOWN_ARMING_STATUSES = ['held','fled',
  'countered','expired']`; `cancelled` (нападавший ушёл сам через «🚶 Уйти») кулдаун НЕ армирует.
  Без исключения цикл «атаковать → уйти → атаковать» снимал защиту базы в три тапа.
- **Анти-спам-кулдаун платят не все тапы**: далёкая цель, запрещённая пара без кнопки-замка и тап,
  САМ открывший окно — платят; свой экран ожидания, тап по кнопке-замку, чужое окно и контратака —
  нет. Инвариант «замок бывает только у `level`/`safe_zone`/`account_age`» связывает
  `PlayerDetectionService::lockLabel()` и `AttackPlayerAction::restrictionReasonHasLockButton()`,
  закреплён тестом-мостом (`StandoffAttackGateTest::testLockButtonReasonsMatchCooldownExemptReasons`).
- **Смешанные часы**: строки пишутся PHP-временем, часть чтений сравнивает с `NOW()` MySQL. Сегодня
  совпадает (обе стороны `Europe/Kiev`), но не гарантировано конструкцией.
- **Надбавка «укрыться» ограничена `pvp.standoff.cooldown_sec`** от момента закрытия `held` —
  без этой границы утекала бы бессрочно в ветке «база исчезла после укрытия».
- **Экран обнаружения игроков был мёртв**: `PlayerDetectionService::detectNearbyPlayers()` роняла
  `TypeError` (`CharacterEntity` под `array`-typehint в `renderDetectionMessage()`) на каждом
  реальном детекте, и все тесты были зелёными — они звали `renderDetectionMessage()` напрямую в
  обход `detectNearbyPlayers()`. Ловушка класса (source-scan/юнит на чистой функции ≠ покрытие
  реального пути), не разовый баг.

Подробности — `mmorpg-vault/tech-writing/services/PvpStandoffService.md`.

## Gotchas
- PvP «не работал никогда» (ADR-164): 0 боёв из 7641 — гейты доступа это не тумблер дуэлей.
  Прежде чем считать ветку живой — SELECT по таблице боёв, а не grep по коду.
- `effect_log` фиксирует и лечение тоже: наличие записи ≠ был вред.
- Умножающий бонус может быть невидимым: `round(1 × 1.55) = 1`.
- Ловушка (exploit-audit, `docs/specs/exploit-audit/REPORT.md` #6, `EA-duplication-01`): двойной
  вызов `NpcInteractionService::fight()` за один `spawnId` честно отвергается свежим `find()` на
  `NpcInteractionService.php:355-357` — понижена до 🟠, красным PoC не доказана (последовательный
  двойной тап), но read-then-write без блокировки строки `npc_spawns` в коде остался.

## Vault
`mmorpg-vault/apps/pve/index.md`
