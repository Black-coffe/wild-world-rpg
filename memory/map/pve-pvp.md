<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

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

## Gotchas
- PvP «не работал никогда» (ADR-164): 0 боёв из 7641 — гейты доступа это не тумблер дуэлей.
  Прежде чем считать ветку живой — SELECT по таблице боёв, а не grep по коду.
- `effect_log` фиксирует и лечение тоже: наличие записи ≠ был вред.
- Умножающий бонус может быть невидимым: `round(1 × 1.55) = 1`.

## Vault
`mmorpg-vault/apps/pve/index.md`
