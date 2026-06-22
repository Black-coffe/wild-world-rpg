<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use App\Models\BossEncounterModel;
use App\Models\BossPointModel;
use App\Models\CharacterModel;
use App\Models\NpcModel;
use App\Services\Food\FoodBuffService;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Player\DeathService;
use App\Services\World\NodeLevelCurve;
use Config\Database;
use Config\Services;

/**
 * WB6 (ADR-137 «Узлы») — многоходовый бой игрока с узлом-боссом (state-machine).
 *
 * Тап действия = чанк `combat.nodes.rounds_per_tap` раундов через ДЕТЕРМИНИРОВАННЫЙ
 * {@see DamageService::calculateDamage} (stat-block формула, БЕЗ mt_rand → fixture-fence
 * вообще не затрагивается; equipment-driven PvpDamageCalculator для NPC-боссов непригоден).
 * Множители действий (защита/спецприём) применяются ВНЕ формулы (детерминированные флаги,
 * как WoodenWall) → mt_rand нетронут.
 *
 * HP узла персистентен в `boss_points.current_health` (раны остаются при отступлении —
 * фундамент со-опа «Облава» WB8). HP игрока на время боя — `boss_encounters.player_hp`
 * (снапшот characters.health на старте, пишется обратно на терминальном исходе). Один
 * активный бой на персонажа.
 *
 * Гейт — `world.nodes.point_mode_enabled` (мастер-killswitch; OFF → узлы спят). Все числа
 * admin-tunable (ADR-024). Возвращает «экраны» — `['text'=>string,'keyboard'=>array]` (рендерит
 * BossEncounterAction через MediaSender) либо `['alert'=>string]` (всплывашка без смены экрана).
 */
class BossEncounterService
{
    use GameSettingsReaderTrait;

    private BossPointModel $points;
    private BossEncounterModel $encounters;
    private CharacterModel $characters;
    private NpcModel $npcs;
    private DamageService $damage;
    private EquipmentService $equipment;
    private NodeLevelCurve $curve;
    private FoodBuffService $food;
    private DeathService $death;
    private BossLootService $loot;
    private AntiCampService $antiCamp;

    /** @var \CodeIgniter\Database\BaseConnection<object, object> */
    private $db;

    public function __construct(
        ?DamageService $damage = null,
        ?EquipmentService $equipment = null,
        ?DeathService $death = null,
        ?BossLootService $loot = null,
        ?AntiCampService $antiCamp = null
    ) {
        $this->points     = new BossPointModel();
        $this->encounters = new BossEncounterModel();
        $this->characters = new CharacterModel();
        $this->npcs       = new NpcModel();
        $logger           = Services::logger();
        $this->damage     = $damage ?? new DamageService($logger);
        $this->equipment  = $equipment ?? new EquipmentService($logger);
        $this->curve      = new NodeLevelCurve();
        $this->food       = new FoodBuffService();
        $this->death      = $death ?? new DeathService();
        $this->antiCamp   = $antiCamp ?? new AntiCampService();
        $this->loot       = $loot ?? new BossLootService($this->antiCamp);
        $this->db         = Database::connect();
    }

    public function enabled(): bool
    {
        return $this->gsBool('world.nodes.point_mode_enabled', false);
    }

    /**
     * Живой узел на клетке (для входа-встречи). null если узлы выключены/нет узла/он в кулдауне.
     *
     * @return array<array-key, mixed>|null
     */
    public function nodeAt(int $cell): ?array
    {
        if (! $this->enabled() || $cell <= 0) {
            return null;
        }
        $row = $this->points->where('cell_number', $cell)->where('status', 'alive')->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Интро-экран узла (имя/уровень/HP + «Напасть»/«Уйти»). Если бой уже идёт — сразу боевой экран.
     *
     * @param array<array-key, mixed> $character
     * @return array<string,mixed>
     */
    public function open(array $character): array
    {
        if (! $this->enabled()) {
            return ['alert' => 'Узлы сейчас неактивны.'];
        }
        $charId = $this->ival($character['id'] ?? null);

        $active = $this->activeEncounter($charId);
        if ($active !== null) {
            $point = $this->points->find($this->ival($active['boss_point_id'] ?? null));
            if (is_array($point) && ($point['status'] ?? '') === 'alive') {
                return $this->renderFight($active, $point, $this->playerHpInt($active));
            }
        }

        $cell  = $this->ival($character['cell_number'] ?? null);
        $point = $this->nodeAt($cell);
        if ($point === null) {
            return ['alert' => 'Здесь нет узла.'];
        }

        return $this->renderIntro($point, $character);
    }

    /**
     * Начать бой (создать active-encounter) и показать боевой экран.
     *
     * @param array<array-key, mixed> $character
     * @return array<string,mixed>
     */
    public function start(array $character): array
    {
        if (! $this->enabled()) {
            return ['alert' => 'Узлы сейчас неактивны.'];
        }
        $charId = $this->ival($character['id'] ?? null);

        $active = $this->activeEncounter($charId);
        if ($active !== null) {
            $point = $this->points->find($this->ival($active['boss_point_id'] ?? null));
            if (is_array($point) && ($point['status'] ?? '') === 'alive') {
                return $this->renderFight($active, $point, $this->playerHpInt($active));
            }
            $this->encounters->update($this->ival($active['id'] ?? null), ['status' => 'fled', 'last_action_at' => date('Y-m-d H:i:s')]);
        }

        $cell  = $this->ival($character['cell_number'] ?? null);
        $point = $this->nodeAt($cell);
        if ($point === null) {
            return ['alert' => 'Здесь нет узла.'];
        }

        // WB7: per-(игрок,узел) откат повторного захода после недавнего отступления/гибели.
        $cdLeft = $this->retreatCooldownRemaining($charId, $this->ival($point['id'] ?? null));
        if ($cdLeft > 0) {
            return ['alert' => "Ты недавно отступил от этого узла. Переведи дух (~{$cdLeft} мин)."];
        }

        $playerHp = max(1, (int) round($this->fval($character['health'] ?? null)));
        $now      = date('Y-m-d H:i:s');
        $this->encounters->insert([
            'boss_point_id'         => $this->ival($point['id'] ?? null),
            'character_id'          => $charId,
            'round_no'              => 0,
            'player_hp'             => $playerHp,
            'status'                => 'active',
            'damage_dealt'          => 0,
            'last_special_round_no' => 0,
            'last_action_at'        => $now,
            'created_at'            => $now,
        ]);
        $enc = $this->activeEncounter($charId);
        if ($enc === null) {
            return ['alert' => 'Не удалось начать бой.'];
        }

        return $this->renderFight($enc, $point, $playerHp);
    }

    /**
     * Обработать боевое действие игрока (чанк раундов) и вернуть экран.
     *
     * @param array<array-key, mixed> $character
     * @param string                  $verb atk|def|spec|item|flee
     * @return array<string,mixed>
     */
    public function act(array $character, string $verb): array
    {
        if (! $this->enabled()) {
            return ['alert' => 'Узлы сейчас неактивны.'];
        }
        $charId = $this->ival($character['id'] ?? null);
        $enc    = $this->activeEncounter($charId);
        if ($enc === null) {
            return ['alert' => 'Активного боя нет.'];
        }
        $point = $this->points->find($this->ival($enc['boss_point_id'] ?? null));
        if (! is_array($point) || ($point['status'] ?? '') !== 'alive' || $this->ival($point['current_health'] ?? null) <= 0) {
            $this->encounters->update($this->ival($enc['id'] ?? null), ['status' => 'fled', 'last_action_at' => date('Y-m-d H:i:s')]);

            return ['alert' => 'Узел уже повержен.'];
        }

        $playerHp = $this->playerHpInt($enc);

        if ($verb === 'flee') {
            return $this->resolveFlee($enc, $point, $character, $playerHp);
        }

        $player = $this->buildPlayer($character, $playerHp);
        $boss   = $this->buildBoss($point);

        // Спецприём: гейт по выносливости и откату.
        $specialOut = 1.0;
        $tiredCost  = 0;
        if ($verb === 'spec') {
            $cost     = max(0, $this->gsInt('combat.nodes.special_tired_cost', 20));
            $cooldown = max(0, $this->gsInt('combat.nodes.special_cooldown_rounds', 3));
            $tired    = $this->fval($character['tired'] ?? null);
            $lastSpec = $this->ival($enc['last_special_round_no'] ?? null);
            $round    = $this->ival($enc['round_no'] ?? null);
            if ($tired < $cost) {
                return ['alert' => "Мало выносливости для спецприёма (нужно {$cost})."];
            }
            if ($lastSpec > 0 && ($round - $lastSpec) < $cooldown) {
                return ['alert' => 'Спецприём ещё не восстановился.'];
            }
            $specialOut = $this->gsFloat('combat.nodes.special_damage_mult', 2.0);
            $tiredCost  = $cost;
        }

        // Предмет: хил перед чанком, тратит медикамент из инвентаря.
        if ($verb === 'item') {
            $heal = $this->consumeHealItem($charId);
            if ($heal === null) {
                return ['alert' => 'Нет лечебного предмета в инвентаре.'];
            }
            $player->health = min($player->maxHealth, $player->health + $heal);
        }

        // Множители ВНЕ формулы (детерминированные флаги): защита/спецприём.
        $playerOutMult = 1.0;
        $bossOutMult   = 1.0;
        if ($verb === 'def') {
            $playerOutMult = $this->gsFloat('combat.nodes.defense_out_mult', 0.6);
            $bossOutMult   = $this->gsFloat('combat.nodes.defense_in_mult', 0.6);
        } elseif ($verb === 'spec') {
            $playerOutMult = $specialOut;
        }

        // Food-баф «Сытость» — ВНУТРИ формулы (через свойства BattleCharacter, как PvEService).
        // WB9 RAID-ONLY: бонус soulbound-трофеев усиливает урон ТОЛЬКО здесь (бой с узлом) —
        // в PvP/обычном PvE НЕ применяется (изолированный слой; портрет П4 «без PvP-power-creep»).
        $wellFed = $character['well_fed_until'] ?? null;
        $player->outgoingDamageMultiplier = $this->food->combatDamageMultiplierFor($wellFed) * $this->raidBonusMultiplier($charId);
        $player->incomingDamageMultiplier = $this->food->incomingDamageMultiplierFor($wellFed);

        $chunk = $this->runChunk($player, $boss, $playerOutMult, $bossOutMult, $this->ival($enc['round_no'] ?? null));

        return $this->resolveChunk($enc, $point, $character, $boss, $chunk, $tiredCost);
    }

    // ───────────────────────── чанк раундов (детерминированный) ─────────────────────────

    /**
     * Проиграть до rounds_per_tap раундов (игрок бьёт первым). Чистая боевая логика (DamageService
     * детерминирован). Возвращает итог: исход, новый round_no, нанесённый/полученный урон.
     *
     * @return array{outcome:string,round:int,playerDmg:float,bossDmg:float,playerHp:float,bossHp:float}
     */
    private function runChunk(BattleCharacter $player, BattleCharacter $boss, float $playerOutMult, float $bossOutMult, int $round): array
    {
        $perTap    = max(1, $this->gsInt('combat.nodes.rounds_per_tap', 3));
        $maxRounds = max(1, $this->gsInt('combat.nodes.max_rounds', 300));
        $playerDmg = 0.0;
        $bossDmg   = 0.0;
        $outcome   = 'active';

        for ($i = 0; $i < $perTap; $i++) {
            $round++;
            // игрок → узел (множитель ВНЕ формулы; max(0) — defense-in-depth: HP узла не растёт)
            $d            = max(0.0, round($this->damage->calculateDamage($player, $boss, '') * $playerOutMult, 2));
            $boss->health = max(0.0, $boss->health - $d);
            $playerDmg   += $d;
            if ($boss->health <= 0) {
                $outcome = 'won';
                break;
            }
            // узел → игрок (множитель ВНЕ формулы)
            $b              = max(0.0, round($this->damage->calculateDamage($boss, $player, '') * $bossOutMult, 2));
            $player->health = max(0.0, $player->health - $b);
            $bossDmg       += $b;
            if ($player->health <= 0) {
                $outcome = 'lost';
                break;
            }
            if ($round >= $maxRounds) {
                $outcome = 'timeout';
                break;
            }
        }

        return [
            'outcome'   => $outcome,
            'round'     => $round,
            'playerDmg' => $playerDmg,
            'bossDmg'   => $bossDmg,
            'playerHp'  => $player->health,
            'bossHp'    => $boss->health,
        ];
    }

    /**
     * Применить итог чанка к БД (узел/encounter/персонаж) и вернуть экран.
     *
     * @param array<array-key, mixed> $enc
     * @param array<array-key, mixed> $point
     * @param array<array-key, mixed> $character
     * @param array{outcome:string,round:int,playerDmg:float,bossDmg:float,playerHp:float,bossHp:float} $chunk
     * @return array<string,mixed>
     */
    private function resolveChunk(array $enc, array $point, array $character, BattleCharacter $boss, array $chunk, int $tiredCost): array
    {
        $now      = date('Y-m-d H:i:s');
        $encId    = $this->ival($enc['id'] ?? null);
        $charId   = $this->ival($enc['character_id'] ?? null);
        $playerHp = (int) round($chunk['playerHp']);
        $bossHp   = (int) round($chunk['bossHp']);
        $dealt    = $this->ival($enc['damage_dealt'] ?? null) + (int) round($chunk['playerDmg']);

        // HP узла персистентен.
        $this->db->table('boss_points')->where('id', $this->ival($point['id'] ?? null))->update([
            'current_health' => max(0, $bossHp),
            'updated_at'     => $now,
        ]);

        // WB8: вклад игрока в «Облаву» по этой жизни узла (атомарный апсёрт; ОБЩИЙ HP делает
        // co-op: A ослабил → B добил → оба в дележе). boss_level = уровень узла (постоянен в
        // пределах жизни). Пишем урон ИМЕННО этого чанка (дельта), не накопленный $dealt.
        $this->loot->recordContribution(
            $this->ival($point['id'] ?? null),
            $this->ival($point['current_level'] ?? null),
            $charId,
            (int) round($chunk['playerDmg'])
        );

        // Списание выносливости за спецприём.
        if ($tiredCost > 0) {
            $newTired = max(0, (int) round($this->fval($character['tired'] ?? null)) - $tiredCost);
            $this->characters->update($charId, ['tired' => $newTired]);
        }

        $encUpdate = [
            'round_no'       => $chunk['round'],
            'player_hp'      => $playerHp,
            'damage_dealt'   => $dealt,
            'last_action_at' => $now,
        ];
        if ($tiredCost > 0) {
            $encUpdate['last_special_round_no'] = $chunk['round'];
        }

        if ($chunk['outcome'] === 'won') {
            $encUpdate['status'] = 'won';
            $this->encounters->update($encId, $encUpdate);
            $this->markNodeKilled($point, $charId);
            $this->characters->update($charId, ['health' => max(1, $playerHp)]);
            // WB8: дележ лута «Облавы» по вкладу (золото). WB9: лотерея soulbound-трофея среди
            // вкладчиков (взвешенная по вкладу). Затем потребление ledger этой жизни.
            $lootResult = $this->loot->distributeLoot($point, $this->ival($point['current_level'] ?? null), $charId, $this->bossName($point));

            return $this->renderWon($point, $lootResult, $charId);
        }

        if ($chunk['outcome'] === 'lost') {
            $encUpdate['status'] = 'lost';
            $encUpdate['player_hp'] = 0;
            $this->encounters->update($encId, $encUpdate);
            // WB7: летальный исход → существующий DeathService (страховка → штраф → списание
            // имущества → respawn). winnerId=null — узел не игрок, лут не передаётся «боссу».
            $this->death->handlePlayerDeathAndReward($charId, null);

            return $this->renderLost($point, $boss);
        }

        if ($chunk['outcome'] === 'timeout') {
            $encUpdate['status'] = 'fled';
            $this->encounters->update($encId, $encUpdate);
            $this->characters->update($charId, ['health' => max(1, $playerHp)]);

            return $this->renderTimeout($point, $boss, $playerHp);
        }

        $this->encounters->update($encId, $encUpdate);
        $enc = array_merge($enc, $encUpdate);
        $point['current_health'] = max(0, $bossHp);

        return $this->renderFight($enc, $point, $playerHp, $chunk);
    }

    /**
     * Отступление (WB7): бой закрыт БЕЗ потери опыта/статов (уважает П2-Казуала), но с ценой —
     * парт-инг-удар узла (HP floor 1, отход не смертелен), штраф выносливости, engage-cooldown
     * (через last_action_at fled-encounter'а). HP узла ОСТАЁТСЯ (раны персистентны — фундамент
     * со-опа; реген затягивает их со временем через NodeHealthRegenHandler).
     *
     * @param array<array-key, mixed> $enc
     * @param array<array-key, mixed> $point
     * @param array<array-key, mixed> $character
     * @return array<string,mixed>
     */
    private function resolveFlee(array $enc, array $point, array $character, int $playerHp): array
    {
        $now    = date('Y-m-d H:i:s');
        $charId = $this->ival($enc['character_id'] ?? null);

        // Парт-инг-удар узла вдогонку (детерминированный, floor 1 — отход не убивает).
        $player      = $this->buildPlayer($character, $playerHp);
        $boss        = $this->buildBoss($point);
        $partingBlow = max(0.0, round($this->damage->calculateDamage($boss, $player, ''), 2));
        $newHp       = max(1, (int) round($playerHp - $partingBlow));

        $tiredCost = max(0, $this->gsInt('combat.nodes.flee_tired_cost', 10));
        $newTired  = max(0, (int) round($this->fval($character['tired'] ?? null)) - $tiredCost);

        $this->encounters->update($this->ival($enc['id'] ?? null), [
            'status'         => 'fled',
            'player_hp'      => $newHp,
            'last_action_at' => $now, // маркер engage-cooldown
        ]);
        $this->characters->update($charId, ['health' => $newHp, 'tired' => $newTired]);

        return $this->renderFlee($point, $boss, $newHp, (int) round($partingBlow));
    }

    /**
     * Узел повержен: точка → кулдаун + респавн через respawn_hours; счётчик расчисток++.
     * Эскалацию/подъём ведёт NodeRespawnHandler (WB5). Лут/трофеи — WB8/WB9 (здесь нет).
     *
     * @param array<array-key, mixed> $point
     */
    private function markNodeKilled(array $point, int $charId): void
    {
        $now     = date('Y-m-d H:i:s');
        $hours   = max(1, $this->gsInt('world.nodes.respawn_hours', 12));
        $respawn = date('Y-m-d H:i:s', time() + $hours * 3600);
        $pointId = $this->ival($point['id'] ?? null);

        // WB10: залоченный кемпер НЕ засчитывается killer'ом для анонса (WB11). Записываем null →
        //       его «расчистка» не даёт ему публичного кредита/титула. Золото/реген-логика не задеты.
        $killerForAnnounce = $this->antiCamp->isLootLocked($charId, $pointId) ? null : $charId;

        $this->db->table('boss_points')->where('id', $pointId)->update([
            'status'                   => 'cooldown',
            'current_health'           => 0,
            'respawn_at'               => $respawn,
            'kill_count'               => $this->ival($point['kill_count'] ?? null) + 1,
            'last_killer_character_id' => $killerForAnnounce,
            'updated_at'               => $now,
        ]);

        // Снять материализованный спавн узла с клетки (исчезает на время кулдауна).
        $npcId = $this->ival($point['current_npc_id'] ?? null);
        $cell  = $this->ival($point['cell_number'] ?? null);
        if ($npcId > 0 && $cell > 0) {
            $this->db->table('npc_spawns')
                ->where('cell_number', $cell)
                ->where('npc_id', $npcId)
                ->where('status', 'alive')
                ->delete();
        }
    }

    // ───────────────────────── боевые профили ─────────────────────────

    /**
     * Боевой профиль игрока: характеристики + бонусы экипировки; health = HP боя.
     *
     * @param array<array-key, mixed> $character
     */
    private function buildPlayer(array $character, int $playerHp): BattleCharacter
    {
        $player = new BattleCharacter($character);
        $this->equipment->applyEquipmentBonuses($player);
        $player->health    = (float) $playerHp;
        $maxRaw            = $character['max_health'] ?? ($character['health'] ?? $playerHp);
        $player->maxHealth = max((float) $playerHp, $this->fval($maxRaw));

        return $player;
    }

    /**
     * Боевой профиль узла (stat-block): уровень/HP из boss_points, dmg/armor из кривой, isBoss=true.
     *
     * @param array<array-key, mixed> $point
     */
    private function buildBoss(array $point): BattleCharacter
    {
        $level    = max(1, $this->ival($point['current_level'] ?? null));
        $template = $this->npcs->find($this->ival($point['current_npc_id'] ?? null));
        $nameRu   = $this->templateName($template);
        $str      = is_array($template) && is_numeric($template['strength'] ?? null) ? (float) $template['strength'] : 1.0;
        $agi      = is_array($template) && is_numeric($template['agility'] ?? null) ? (float) $template['agility'] : 1.0;

        return new BattleCharacter([
            'id'           => 0,
            'name'         => $nameRu,
            'level'        => $level,
            'health'       => (float) $this->ival($point['current_health'] ?? null),
            'maxHealth'    => (float) $this->ival($point['max_health'] ?? null),
            'strength'     => $str,
            'agility'      => $agi,
            'intellect'    => 1.0,
            'armor'        => (float) $this->curve->armorForLevel($level),
            'damage_type'  => 'Physical',
            'damage_value' => (float) $this->curve->damageForLevel($level),
            'is_boss'      => true,
        ]);
    }

    // ───────────────────────── рендер экранов ─────────────────────────

    /**
     * @param array<array-key, mixed> $point
     * @param array<array-key, mixed> $character
     * @return array{text:string,keyboard:array<int,mixed>}
     */
    private function renderIntro(array $point, array $character): array
    {
        $pid   = $this->ival($point['id'] ?? null);
        $name  = $this->bossName($point);
        $level = $this->ival($point['current_level'] ?? null);
        $hp    = $this->ival($point['current_health'] ?? null);
        $maxHp = $this->ival($point['max_health'] ?? null);
        $pHp   = (int) round($this->fval($character['health'] ?? null));

        $text  = "☠️ *Узел* — держит его *{$name}*\n\n";
        $text .= "🔱 Уровень узла: *{$level}*\n";
        $text .= "❤️ Узел: *{$hp}/{$maxHp}*\n";
        $text .= "🩸 Ты: *{$pHp}*\n\n";
        $text .= "_Природа не терпит пустоты — кого-то сломишь, место займёт следующий, злее._\n\n";
        $text .= 'Сразишься за узел?';

        return [
            'text'     => $text,
            'keyboard' => [
                [['text' => '⚔️ Напасть на узел', 'callback_data' => "nodeAct_start_{$pid}"]],
                [['text' => '🚶 Уйти', 'callback_data' => 'move']],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $enc
     * @param array<array-key, mixed> $point
     * @param array{outcome:string,round:int,playerDmg:float,bossDmg:float}|null $chunk
     * @return array{text:string,keyboard:array<int,mixed>}
     */
    private function renderFight(array $enc, array $point, int $playerHp, ?array $chunk = null): array
    {
        $pid   = $this->ival($point['id'] ?? null);
        $name  = $this->bossName($point);
        $level = $this->ival($point['current_level'] ?? null);
        $hp    = $this->ival($point['current_health'] ?? null);
        $maxHp = $this->ival($point['max_health'] ?? null);
        $round = $this->ival($enc['round_no'] ?? null);

        $text  = "⚔️ *Бой с узлом* — *{$name}* L{$level}\n\n";
        $text .= '❤️ Узел: *' . max(0, $hp) . "/{$maxHp}*\n";
        $text .= "🩸 Ты: *{$playerHp}*\n";
        $text .= "🔄 Раунд: *{$round}*\n";
        if ($chunk !== null) {
            $pd    = (int) round($chunk['playerDmg']);
            $bd    = (int) round($chunk['bossDmg']);
            $text .= "\n— Ты нанёс *{$pd}*, получил *{$bd}*\n";
        }
        $text .= "\nВыбери действие:";

        return [
            'text'     => $text,
            'keyboard' => [
                [
                    ['text' => '⚔️ Удар', 'callback_data' => "nodeAct_atk_{$pid}"],
                    ['text' => '🛡 Защита', 'callback_data' => "nodeAct_def_{$pid}"],
                ],
                [
                    ['text' => '🎯 Спецприём', 'callback_data' => "nodeAct_spec_{$pid}"],
                    ['text' => '🍖 Предмет', 'callback_data' => "nodeAct_item_{$pid}"],
                ],
                [['text' => '🏃 Отступить', 'callback_data' => "nodeAct_flee_{$pid}"]],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed>                                                                    $point
     * @param array{participants:int,totalDamage:int,goldPool:int,goldByChar:array<int,int>,killerGold:int,eligible:array<int,int>,soulbound:array{winnerId:int,kind:string,itemName:string,rarity:string}|null}|null $loot
     * @return array{text:string,keyboard:array<int,mixed>}
     */
    private function renderWon(array $point, ?array $loot = null, int $viewerId = 0): array
    {
        $name  = $this->bossName($point);
        $level = $this->ival($point['current_level'] ?? null);
        $hours = max(1, $this->gsInt('world.nodes.respawn_hours', 12));

        $text  = "🏆 *Узел повержен!*\n\n";
        $text .= "Ты сломил *{$name}* (L{$level}). Точка опустеет примерно на *{$hours}ч* — затем её займёт следующий носитель, злее прежнего.\n\n";

        // WB8: добыча «Облавы» — доля золота по вкладу. Media-off самодостаточен (всё в тексте).
        $killerGold   = $loot !== null ? max(0, $loot['killerGold']) : 0;
        $participants = $loot !== null ? max(0, $loot['participants']) : 0;
        if ($participants > 1) {
            $text .= "🤝 *Облава*: добычу делили *{$participants}* — по вкладу в общий урон.\n";
            $text .= $killerGold > 0
                ? "💰 Твоя доля: *{$killerGold}* золота.\n"
                : "_Большая часть добычи ушла тем, кто вложил больше урона._\n";
        } elseif ($killerGold > 0) {
            $text .= "💰 Добыча: *{$killerGold}* золота.\n";
        }

        // WB9: soulbound-трофей «Метка пустоши» — взвешенная лотерея среди вкладчиков.
        $sb = $loot['soulbound'] ?? null;
        if (is_array($sb)) {
            $itemName = (string) $sb['itemName'];
            $kindRu   = $sb['kind'] === 'weapon' ? 'оружие' : 'броню';
            if ($sb['winnerId'] === $viewerId) {
                $text .= "\n🔒 *Метка пустоши!* С узла сорвал {$kindRu}: *{$itemName}*. Это трофей — он усиливает тебя ТОЛЬКО против узлов, его нельзя надеть или продать.\n";
            } else {
                $text .= "\n🔒 *Метка пустоши* досталась другому бойцу Облавы — по жребию, взвешенному вкладом.\n";
            }
        }

        // WB10: залоченному кемперу объясняем, почему трофей прошёл мимо (media-off самодостаточен).
        if ($viewerId > 0 && $this->antiCamp->isLootLocked($viewerId, $this->ival($point['id'] ?? null))) {
            $text .= "\n☢️ *Гарь узла.* Ты слишком долго держал эту точку — трофей обошёл тебя стороной (золото за вклад осталось). Отойди и вернись позже.\n";
        }

        $text .= "\n_Свято место пусто не бывает._";

        return ['text' => $text, 'keyboard' => [[['text' => '🚶 Уйти', 'callback_data' => 'move']]]];
    }

    /**
     * @param array<array-key, mixed> $point
     * @return array{text:string,keyboard:array<int,mixed>}
     */
    private function renderLost(array $point, BattleCharacter $boss): array
    {
        $name  = $this->bossName($point);
        $level = $this->ival($point['current_level'] ?? null);
        $hp    = (int) round($boss->health);
        $maxHp = $this->ival($point['max_health'] ?? null);

        $text  = "💀 *Ты пал в бою с узлом.*\n\n";
        $text .= "*{$name}* (L{$level}) оказался сильнее. Узел держит точку (❤️ {$hp}/{$maxHp}).\n\n";
        $text .= '_Вернись сильнее — или с теми, кто прикроет спину._';

        return ['text' => $text, 'keyboard' => [[['text' => '🚶 Уйти', 'callback_data' => 'move']]]];
    }

    /**
     * @param array<array-key, mixed> $point
     * @return array{text:string,keyboard:array<int,mixed>}
     */
    private function renderTimeout(array $point, BattleCharacter $boss, int $playerHp, bool $byChoice = false): array
    {
        $name  = $this->bossName($point);
        $level = $this->ival($point['current_level'] ?? null);
        $hp    = (int) round($boss->health);
        $maxHp = $this->ival($point['max_health'] ?? null);

        $head  = $byChoice ? '🏃 *Ты отступил от узла.*' : '🏃 *Ты выдохся и отступил.*';
        $text  = "{$head}\n\n";
        $text .= "*{$name}* (L{$level}) держит точку (❤️ {$hp}/{$maxHp}). Раны на узле остаются — добей позже или с группой.\n";
        $text .= "🩸 Ты: *{$playerHp}*";

        return ['text' => $text, 'keyboard' => [[['text' => '🚶 Уйти', 'callback_data' => 'move']]]];
    }

    /**
     * Экран отступления (WB7) — с парт-инг-ударом и предупреждением об откате.
     *
     * @param array<array-key, mixed> $point
     * @return array{text:string,keyboard:array<int,mixed>}
     */
    private function renderFlee(array $point, BattleCharacter $boss, int $playerHp, int $partingBlow): array
    {
        $name  = $this->bossName($point);
        $level = $this->ival($point['current_level'] ?? null);
        $hp    = (int) round($boss->health);
        $maxHp = $this->ival($point['max_health'] ?? null);
        $cd    = max(0, $this->gsInt('combat.nodes.engage_cooldown_minutes', 5));

        $text  = "🏃 *Ты отступил от узла.*\n\n";
        if ($partingBlow > 0) {
            $text .= "Уходя, поймал удар вдогонку: −*{$partingBlow}* HP.\n";
        }
        $text .= "*{$name}* (L{$level}) держит точку (❤️ {$hp}/{$maxHp}). Раны на узле остаются, но со временем затягиваются — добей быстрее или с группой.\n";
        $text .= "🩸 Ты: *{$playerHp}*\n";
        $text .= "_Перевести дух перед новым заходом: ~{$cd} мин._";

        return ['text' => $text, 'keyboard' => [[['text' => '🚶 Уйти', 'callback_data' => 'move']]]];
    }

    // ───────────────────────── вспомогательное ─────────────────────────

    /**
     * Остаток отката повторного захода на узел после недавнего отступления/гибели (минуты),
     * 0 если можно заходить. По последнему fled/lost-encounter'у (char, point).
     */
    private function retreatCooldownRemaining(int $charId, int $pointId): int
    {
        $cdMin = max(0, $this->gsInt('combat.nodes.engage_cooldown_minutes', 5));
        if ($cdMin <= 0 || $charId <= 0 || $pointId <= 0) {
            return 0;
        }
        $row = $this->encounters
            ->where('character_id', $charId)
            ->where('boss_point_id', $pointId)
            ->whereIn('status', ['fled', 'lost'])
            ->orderBy('id', 'DESC')
            ->first();
        if (! is_array($row) || ! is_string($row['last_action_at'] ?? null)) {
            return 0;
        }
        $last = strtotime($row['last_action_at']);
        if ($last === false) {
            return 0;
        }
        $elapsed = (time() - $last) / 60.0;
        $left    = (int) ceil($cdMin - $elapsed);

        return $left > 0 ? $left : 0;
    }

    /**
     * @param array<array-key, mixed> $point
     */
    private function bossName(array $point): string
    {
        return $this->templateName($this->npcs->find($this->ival($point['current_npc_id'] ?? null)));
    }

    private function templateName(mixed $template): string
    {
        if (is_array($template) && is_string($template['npc_name_ru'] ?? null) && $template['npc_name_ru'] !== '') {
            return $template['npc_name_ru'];
        }

        return 'Хозяин узла';
    }

    /**
     * Найти и потратить 1 лечебный медикамент (crafted_items.type='drug', quantity>0).
     * Возвращает величину хила (medical.<snake>.heal_health или флэт combat.nodes.item_heal),
     * либо null если предмета нет. Первый resource-SINK узлов.
     */
    private function consumeHealItem(int $charId): ?int
    {
        $res = $this->db->table('crafted_items_log cil')
            ->select('cil.id AS log_id, cil.quantity, ci.name_eng')
            ->join('crafted_items ci', 'ci.id = cil.crafted_item_id', 'inner')
            ->where('cil.character_id', $charId)
            ->where('cil.quantity >', 0)
            ->where('ci.type', 'drug')
            ->orderBy('cil.quantity', 'DESC')
            ->get(1);
        if ($res === false) {
            return null;
        }
        $row = $res->getRowArray();
        if (! is_array($row)) {
            return null;
        }
        $logId = $this->ival($row['log_id'] ?? null);
        $qty   = $this->ival($row['quantity'] ?? null);
        if ($logId <= 0 || $qty <= 0) {
            return null;
        }

        $this->db->table('crafted_items_log')->where('id', $logId)->update(['quantity' => $qty - 1]);

        $nameEng = is_string($row['name_eng'] ?? null) ? $row['name_eng'] : '';
        $snake   = strtolower(str_replace([' ', '-'], '_', $nameEng));
        $heal    = $this->gsInt("medical.{$snake}.heal_health", 0);
        if ($heal <= 0) {
            $heal = $this->gsInt('combat.nodes.item_heal', 40);
        }

        return max(1, $heal);
    }

    /**
     * WB9 RAID-ONLY бонус: множитель урона ПО УЗЛАМ от числа soulbound-трофеев игрока.
     * = 1 + min(`soulbound_raid_bonus_cap`, count × `soulbound_raid_bonus_pct`). Считается ТОЛЬКО
     * здесь (бой с узлом) → в PvP/обычном PvE не влияет (трофеи не надеваются, equipped=0). 1.0 если
     * трофеев нет / бонус выключен.
     */
    private function raidBonusMultiplier(int $charId): float
    {
        if ($charId <= 0) {
            return 1.0;
        }
        $per = max(0.0, $this->gsFloat('combat.nodes.soulbound_raid_bonus_pct', 0.05));
        $cap = max(0.0, $this->gsFloat('combat.nodes.soulbound_raid_bonus_cap', 0.5));
        if ($per <= 0.0 || $cap <= 0.0) {
            return 1.0;
        }
        $count = (int) $this->db->table('characters_weapons')->where('character_id', $charId)->where('is_soulbound', 1)->countAllResults()
            + (int) $this->db->table('characters_outfits')->where('character_id', $charId)->where('is_soulbound', 1)->countAllResults();
        if ($count <= 0) {
            return 1.0;
        }

        return 1.0 + min($cap, $count * $per);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function activeEncounter(int $charId): ?array
    {
        if ($charId <= 0) {
            return null;
        }
        $row = $this->encounters->where('character_id', $charId)->where('status', 'active')->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<array-key, mixed> $enc
     */
    private function playerHpInt(array $enc): int
    {
        return max(0, $this->ival($enc['player_hp'] ?? null));
    }

    private function ival(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private function fval(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
