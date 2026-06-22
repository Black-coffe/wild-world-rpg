<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Attributes\HandlerKey;
use App\Models\BossPointModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\World\NodeLevelCurve;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * WB5 (ADR-137 «Узлы») — респавн и гео-эскалация точек-узлов + материализация боссов.
 *
 * Крон (everyMinute, singleInstance). ЕДИНСТВЕННЫЙ killswitch — `world.nodes.point_mode_enabled`
 * (default OFF → no-op): пока OFF, узлы спят, боссы живут по старой кочующей модели ADR-106
 * (RaiderGradientHandler). При ON этот хэндлер становится «хозяином» боссов:
 *
 *  1) ПЕРЕВОД БОССОВ В PASSIVE (одноразово, идемпотентно). Шаблоны ADR-106 (Шрам/Цербер/Патриарх)
 *     → `ai_behavior='passive'`. AutoPveHandler пропускает passive (ADR-089) → авто-PvE больше не
 *     молотит их как aggressive-рейдеров; бой инициирует игрок (encounter — WB6).
 *     ⚠ Аудит-коррекция WB4: делать это РАНЬШЕ активации нельзя — боссы ЖИВЫ на проде, перевод в
 *     passive без encounter-UI сделал бы их inert/неубиваемыми (живой регресс). Поэтому гейт = ON.
 *
 *  2) РЕСПАВН/ЭСКАЛАЦИЯ. Точки в status='cooldown', готовые подняться (respawn_at истёк ИЛИ NULL —
 *     свежезасеянные WB4 / сброшенные вайпом SEED_RESET), воскрешаются: уровень = гео-эскалация
 *     ОТ kill_count (NodeLevelCurve::escalatedLevel, идемпотентно), HP пересчитан по кривой,
 *     current_health = max_health, status='alive', respawn_at=NULL.
 *
 *  3) СИНХРОНИЗАЦИЯ СПАВНОВ С ТОЧКАМИ. npc_spawns боссов приводятся в точное соответствие
 *     alive-точкам: лишние boss-спавны (старые кочующие случайные Y<150 + остатки на клетках точек
 *     в кулдауне) снимаются; на каждой alive-точке без спавна материализуется passive-босс. Так
 *     карта показывает узел ровно там и тогда, где он есть.
 *
 * Идемпотентно: уровень считается ОТ kill_count (не инкрементом), материализация — upsert по
 * клетке. Двойной прогон за тик исключён singleInstance; повторные тики — no-op на стабильном
 * состоянии. Все числа — admin-tunable (ADR-024), источник правды формулы — NodeLevelCurve.
 *
 * Класс НЕ final, seam'ы (`bossNpcIds`) overridable для тестов.
 */
#[HandlerKey(
    key: 'node_respawn',
    displayName: 'Узлы: респавн/эскалация точек-боссов (WB5)',
    description: 'everyMinute + killswitch world.nodes.point_mode_enabled: поднимает точки-узлы из кулдауна с гео-эскалацией (NodeLevelCurve), переводит боссов ADR-106 в passive и синхронизирует npc_spawns с alive-точками. ADR-137.',
)]
class NodeRespawnHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    /** ADR-106 — npc_name_en боссов-архетипов (id auto_increment → резолв в рантайме). */
    private const BOSS_KEYS = ['boss_scar_butcher', 'boss_cerberus_prototype', 'boss_pack_patriarch'];

    protected BossPointModel $points;
    protected NpcModel $npcs;
    protected NpcSpawnModel $spawns;
    protected NodeLevelCurve $curve;

    /** @var BaseConnection<object, object> */
    protected BaseConnection $db;

    /**
     * Memo на один run() — резолв id боссов одним запросом.
     *
     * @var list<int>|null
     */
    protected ?array $bossIdsCache = null;

    public function __construct()
    {
        $this->points = new BossPointModel();
        $this->npcs   = new NpcModel();
        $this->spawns = new NpcSpawnModel();
        $this->curve  = new NodeLevelCurve();
        $this->db     = Database::connect();
    }

    public function handle(array $task = []): void
    {
        $this->run();
    }

    public function run(): void
    {
        if (! $this->gsBool('world.nodes.point_mode_enabled', false)) {
            return; // dormant — боссы по старой кочующей модели ADR-106 (RaiderGradientHandler)
        }

        $bossIds = $this->bossNpcIds();

        $this->retireRoamingBossTemplates($bossIds); // 1) боссы → passive
        $this->respawnReadyPoints();                 // 2) подъём + гео-эскалация
        $this->syncSpawnsToPoints($bossIds);         // 3) спавны ↔ alive-точки
    }

    /**
     * 1) Шаблоны боссов ADR-106 → ai_behavior='passive' (идемпотентно: UPDATE только не-passive).
     * AutoPveHandler пропускает passive → авто-PvE больше не бьёт их; бой инициирует игрок (WB6).
     *
     * @param list<int> $bossIds
     */
    protected function retireRoamingBossTemplates(array $bossIds): void
    {
        if ($bossIds === []) {
            return;
        }
        $this->db->table('npcs')
            ->whereIn('id', $bossIds)
            ->where('ai_behavior !=', 'passive')
            ->update(['ai_behavior' => 'passive']);
    }

    /**
     * 2) Поднять точки из кулдауна, готовые к респавну, с гео-эскалацией.
     *
     * Готовы: status='cooldown' И (respawn_at истёк ИЛИ respawn_at IS NULL). NULL = свежезасеянная
     * точка (WB4) либо сброшенная полным вайпом (SEED_RESET) — поднимается к base_level (kill_count=0).
     * Уровень считается ОТ kill_count → идемпотентно. HP пересчитывается по кривой целиком.
     */
    protected function respawnReadyPoints(): void
    {
        $now = date('Y-m-d H:i:s');

        $ready = $this->points
            ->where('status', 'cooldown')
            ->groupStart()
                ->where('respawn_at', null)
                ->orWhere('respawn_at <=', $now)
            ->groupEnd()
            ->findAll();

        foreach ($ready as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = is_numeric($p['id'] ?? null) ? (int) $p['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $baseLevel = is_numeric($p['base_level'] ?? null) ? (int) $p['base_level'] : 1;
            $killCount = is_numeric($p['kill_count'] ?? null) ? (int) $p['kill_count'] : 0;

            $level     = $this->curve->escalatedLevel($baseLevel, $killCount);
            $maxHealth = $this->curve->healthForLevel($level);

            $this->db->table('boss_points')->where('id', $id)->update([
                'current_level'  => $level,
                'max_health'     => $maxHealth,
                'current_health' => $maxHealth,
                'status'         => 'alive',
                'respawn_at'     => null,
                'updated_at'     => $now,
            ]);
        }
    }

    /**
     * 3) Привести boss-спавны в точное соответствие alive-точкам.
     *
     * Снять лишние boss-спавны (кочующие старые + остатки на клетках кулдаун-точек), затем
     * материализовать passive-босса на каждой alive-точке, где его ещё нет. Так на карте узел
     * виден ровно на alive-точках и исчезает на время кулдауна.
     *
     * @param list<int> $bossIds
     */
    protected function syncSpawnsToPoints(array $bossIds): void
    {
        if ($bossIds === []) {
            return; // без шаблонов боссов нечего материализовать/чистить
        }

        $alive      = $this->points->where('status', 'alive')->findAll();
        $aliveCells = [];
        foreach ($alive as $p) {
            if (is_array($p) && is_numeric($p['cell_number'] ?? null)) {
                $aliveCells[(int) $p['cell_number']] = true;
            }
        }

        // Снять boss-спавны, НЕ стоящие на клетке alive-точки (кочующие Y<150 + кулдаун-остатки).
        $purge = $this->db->table('npc_spawns')->whereIn('npc_id', $bossIds)->where('status', 'alive');
        if ($aliveCells !== []) {
            $purge->whereNotIn('cell_number', array_keys($aliveCells));
        }
        $purge->delete();

        // Материализовать passive-босса на каждой alive-точке без живого спавна на её клетке.
        $spawnedAt = date('Y-m-d H:i:s');
        foreach ($alive as $p) {
            if (! is_array($p)) {
                continue;
            }
            $cell  = is_numeric($p['cell_number'] ?? null) ? (int) $p['cell_number'] : 0;
            $npcId = is_numeric($p['current_npc_id'] ?? null) ? (int) $p['current_npc_id'] : 0;
            if ($cell <= 0 || $npcId <= 0) {
                continue;
            }
            // Любой живой NPC уже на клетке → не плодим второго (идемпотентность + защита от чужого).
            $occupied = $this->spawns->where('cell_number', $cell)->where('status', 'alive')->countAllResults();
            if ($occupied > 0) {
                continue;
            }
            $hpRaw  = $p['current_health'] ?? ($p['max_health'] ?? null);
            $health = is_numeric($hpRaw) ? (float) $hpRaw : 1.0;

            $this->spawns->insert([
                'npc_id'         => $npcId,
                'cell_number'    => $cell,
                'coordinate_x'   => is_numeric($p['coordinate_x'] ?? null) ? (int) $p['coordinate_x'] : 0,
                'coordinate_y'   => is_numeric($p['coordinate_y'] ?? null) ? (int) $p['coordinate_y'] : 0,
                'current_health' => $health,
                'spawned_at'     => $spawnedAt,
                'status'         => 'alive',
            ]);
        }
    }

    /**
     * ADR-106 — npc_id боссов по npc_name_en (id auto_increment, в коде не фиксируемы).
     * Overridable seam (тесты). Memo на один run().
     *
     * @return list<int>
     */
    protected function bossNpcIds(): array
    {
        if ($this->bossIdsCache !== null) {
            return $this->bossIdsCache;
        }
        $ids = [];
        foreach ($this->npcs->whereIn('npc_name_en', self::BOSS_KEYS)->findAll() as $n) {
            $idRaw = is_array($n) ? ($n['id'] ?? null) : null;
            if (is_numeric($idRaw)) {
                $ids[] = (int) $idRaw;
            }
        }

        return $this->bossIdsCache = $ids;
    }
}
