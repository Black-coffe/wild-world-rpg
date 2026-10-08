<?php

/**
 * Defect check `duel-outcome-not-decided-by-fight` (docs/defects/duel-outcome-not-decided-by-fight.md).
 *
 * Fails (exit 1) when an equalized duel is decided by a formality instead of the fight: a duel ends without a
 * knockout inside the round cap, or the tie-break reaches seniority. Runs the production duel path —
 * `DuelService::prepare()` + `simulate()` + `resolveDuel()` on the unchanged `PvpRoundOrchestrator` — over 200
 * `mt_srand` seeds, the fighters standing on the testbot cells of 491 and 522 (457 cells apart). Equipment is a
 * DB-free stub; game settings come from the fixture, or the code defaults without one.
 *
 * usage: php scripts/defects-duel-knockout-check.php [<fixture.json>]
 *   fixture: {"settings": {"pvp.duel.baseline_health": 1000, ...}, "weapons": {"1": {"damage_value": 6, ...}}}
 *   no argument (or a non-.json argument) — current defaults, both fighters unarmed.
 */

declare(strict_types=1);

use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\PVE\DuelService;
use App\Services\PVE\PvpEquipmentRepository;

$arg = $argv[1] ?? '';

$settings = [];
$weapons  = [];
if ($arg !== '' && str_ends_with($arg, '.json')) {
    if (! is_file($arg)) {
        fwrite(STDERR, "duel-knockout-check: no such fixture: {$arg}\n");
        exit(2);
    }
    $fixture = json_decode((string) file_get_contents($arg), true);
    if (! is_array($fixture)) {
        fwrite(STDERR, "duel-knockout-check: fixture is not a JSON object: {$arg}\n");
        exit(2);
    }
    $settings = is_array($fixture['settings'] ?? null) ? $fixture['settings'] : [];
    foreach (is_array($fixture['weapons'] ?? null) ? $fixture['weapons'] : [] as $id => $w) {
        if (is_array($w)) {
            $weapons[(int) $id] = $w;
        }
    }
}

require __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';

// Кэш настроек — заглушка: значения идут только из фикстуры или умолчаний кода, а не из чужого файла кэша.
config(\Config\Cache::class)->handler = 'dummy';
\Config\Services::resetSingle('cache');

$rows = [];
foreach ($settings as $key => $value) {
    if (is_string($key) && is_numeric($value)) {
        $rows[$key] = ['setting_key' => $key, 'value_type' => 'float', 'value_float' => (float) $value];
    }
}
$model = new class ($rows) extends GameSettingsModel {
    /** @param array<string, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function findByKey(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }
};

$repo = new class ($weapons) extends PvpEquipmentRepository {
    /** @param array<int, array<string, mixed>> $weapons */
    public function __construct(private readonly array $weapons)
    {
    }

    public function getEquippedWeapon(int $characterId): ?array
    {
        return $this->weapons[$characterId] ?? null;
    }

    public function getEquippedOutfitsWithDetails(int $characterId): array
    {
        return [];
    }

    public function getMapCell(int $cellNumber): ?array
    {
        return match ($cellNumber) {
            328454 => ['coordinate_x' => 328, 'coordinate_y' => 453],
            312911 => ['coordinate_x' => 312, 'coordinate_y' => 910],
            default => null,
        };
    }

    public function getCharacterFaction(int $characterId): ?array
    {
        return null;
    }
};

$duels = new DuelService(new GameSettingsService($model));
$a     = ['id' => 1, 'name' => 'Вызов', 'level' => 1, 'strength' => 0.5, 'agility' => 0.01, 'intellect' => 0.01, 'health' => 90, 'tired' => 10, 'cell_number' => 328454, 'created_at' => '2026-01-01 00:00:00'];
$b     = ['id' => 2, 'name' => 'Ответ', 'level' => 1, 'strength' => 0.5, 'agility' => 0.01, 'intellect' => 0.01, 'health' => 90, 'tired' => 10, 'cell_number' => 312911, 'created_at' => '2026-02-01 00:00:00'];

$n         = 200;
$knockouts = 0;
$seniority = 0;
$rounds    = [];
for ($i = 0; $i < $n; $i++) {
    [$eqA, $eqB] = $duels->prepare($a, $b);
    mt_srand(1000 + $i);
    $result = $duels->simulate($eqA, $eqB, ['name' => 'Поля', 'danger_level' => 1 + $i % 3], $repo);
    $res    = $duels->resolveDuel($result, $a, $b);
    $knockouts += ($result['type'] ?? '') === 'exhausted' ? 0 : 1;
    $seniority += $res['reason'] === 'seniority' ? 1 : 0;
    $rounds[] = is_numeric($result['rounds'] ?? null) ? (int) $result['rounds'] : 0;
}
sort($rounds);
$median = $rounds[intdiv($n, 2)];

$ok = $knockouts === $n && $seniority === 0;
printf(
    "duel-knockout-check: %s — %d duels, knockouts %d, decided by seniority %d, median rounds %d (%s)\n",
    $ok ? 'OK' : 'FAIL',
    $n,
    $knockouts,
    $seniority,
    $median,
    $arg !== '' && str_ends_with($arg, '.json') ? $arg : 'code defaults'
);
exit($ok ? 0 : 1);
