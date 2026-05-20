<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\PVE\DefenseStructureService;
use App\Services\PVE\PvpRoundOrchestrator;
use App\Services\PVE\TowerAlertService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * THROWAWAY e2e smoke для S26b (WatchTower). Удалить перед финальным коммитом.
 *   php spark smoke:s26b [charId]
 */
final class SmokeS26b extends BaseCommand
{
    protected $group       = 'Smoke';
    protected $name        = 'smoke:s26b';
    protected $description = 'S26b WatchTower e2e: migration artifacts + initiative profile + determineInitiative + alert detection.';

    public function run(array $params): void
    {
        $db = Database::connect();
        if (function_exists('cache') && is_object(cache()) && method_exists(cache(), 'clean')) {
            cache()->clean();
        }

        // 1. Migration artifacts.
        CLI::write('=== 1. Migration artifacts ===', 'yellow');
        $b = $db->table('buildings')->where('name_en', 'WatchTower')->get()->getRowArray();
        $t = $db->table('tasks')->where('name', 'buildWatchTower')->get()->getRowArray();
        $s = $db->table('game_settings')->whereIn('setting_key', [
            'defense.tower.alert_range_cells',
            'defense.tower.defender_initiative_bonus_percent',
            'defense.tower.alert_cooldown_sec',
        ])->get()->getResultArray();
        CLI::write('WatchTower building: ' . ($b ? "id={$b['id']} hp={$b['hp']} tax={$b['tax']} type={$b['building_type']}" : 'MISSING'), $b ? 'green' : 'red');
        CLI::write('buildWatchTower task: ' . ($t ? "id={$t['id']} handler_key={$t['handler_key']}" : 'MISSING'), $t ? 'green' : 'red');
        CLI::write('tower settings: ' . count($s) . '/3', count($s) === 3 ? 'green' : 'red');
        foreach ($s as $row) {
            CLI::write("  {$row['setting_key']} = {$row['value_int']}");
        }
        if (! $b) {
            CLI::error('WatchTower building отсутствует — миграция не применена.');
            return;
        }
        $towerBuildingId = (int) $b['id'];

        // 2. Initiative profile + determineInitiative.
        CLI::write('=== 2. Initiative (defender at base with tower) ===', 'yellow');
        $charId = (int) ($params[0] ?? 491);
        $char   = $db->table('characters')->where('id', $charId)->get()->getRowArray();
        if (! $char) {
            CLI::error("char #{$charId} не найден");
            return;
        }
        $cell   = (int) $char['cell_number'];
        $mapRow = $db->table('map')->where('cell_number', $cell)->get()->getRowArray();
        $x      = (int) ($mapRow['coordinate_x'] ?? 0);
        $y      = (int) ($mapRow['coordinate_y'] ?? 0);

        // Временная вышка на клетке защитника.
        $db->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $towerBuildingId,
            'map_cell_id'  => $cell, 'building_type' => 'defensive', 'hp' => 300,
        ]);
        $towerRowId = (int) $db->insertID();

        try {
            $profile = (new DefenseStructureService())->getDefenseProfile($charId, $cell);
            CLI::write('getDefenseProfile: ' . json_encode($profile, JSON_UNESCAPED_UNICODE), $profile ? 'green' : 'red');

            // Атакующий чуть инициативнее; вышка должна перевернуть.
            $attacker = ['id' => 999999, 'name' => 'Attacker', 'level' => (int) $char['level'], 'agility' => (float) $char['agility'] + 3.0];
            $defender = ['id' => $charId, 'name' => $char['name'] ?? 'Defender', 'level' => (int) $char['level'], 'agility' => (float) $char['agility']];
            $orch     = new PvpRoundOrchestrator();
            $noTower  = $orch->determineInitiative($attacker, $defender)['name'];
            $withTow  = $orch->determineInitiative($attacker, $defender, $profile)['name'];
            CLI::write("determineInitiative без вышки → {$noTower}; с вышкой → {$withTow}", ($withTow === ($char['name'] ?? 'Defender')) ? 'green' : 'red');

            // 3. Alert detection (counting stub, без Telegram).
            CLI::write('=== 3. Alert detection ===', 'yellow');
            $mover = $db->table('characters')->where('id !=', $charId)->get()->getRowArray();
            $moverId = (int) ($mover['id'] ?? 0);
            $counter = new class extends TowerAlertService {
                public int $n = 0;
                protected function sendAlert(int $ownerId, string $moverName, int $dist, int $x, int $y): bool
                {
                    $this->n++;
                    CLI::write("  ALERT → owner={$ownerId} mover={$moverName} dist={$dist} at(X={$x},Y={$y})");
                    return true;
                }
            };
            $inRange  = $counter->notifyTowersNear($moverId, $x, $y);          // на клетке вышки → dist 0
            CLI::write("notifyTowersNear (mover ON tower cell) → sent={$inRange}", $inRange >= 1 ? 'green' : 'red');
            cache()->clean();
            $counter2 = new class extends TowerAlertService {
                public int $n = 0;
                protected function sendAlert(int $ownerId, string $moverName, int $dist, int $x, int $y): bool { $this->n++; return true; }
            };
            $outRange = $counter2->notifyTowersNear($moverId, $x + 50, $y + 50); // далеко
            CLI::write("notifyTowersNear (mover far away) → sent={$outRange}", $outRange === 0 ? 'green' : 'red');
        } finally {
            $db->table('character_buildings')->where('id', $towerRowId)->delete();
            CLI::write("cleanup: удалена временная вышка id={$towerRowId}", 'dark_gray');
        }

        CLI::write('=== smoke:s26b done ===', 'green');
    }
}
