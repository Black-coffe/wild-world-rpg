<?php

namespace App\Services\Player\TeleportBeacon;

use App\Models\CraftedItemsLogModel;
use App\Models\TeleportBeaconModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * v0.51.54 (TeleportBeaconSetAction decomp Step 3) — extract DB write +
 * inventory subtract logic з `installBeacon`.
 *
 * exploit-fix-07 (ADR-181, `EA-gaps-04`) — порядок перевёрнут: раньше строка
 * `teleport_beacons` вставлялась ДО списания предмета, а результат `subtractItem()`
 * (чтение-потом-запись, `bool`) игнорировался — при неудачном списании (или гонке
 * двух тапов) игрок получал маяк бесплатно. Теперь списание идёт условной записью
 * ({@see ConditionalWriteService::decrementIfAtLeast()}) ПЕРВЫМ шагом, INSERT
 * маяка — только на подтверждённом `WriteOutcome::Applied`, обе операции — в
 * одной транзакции: на отказе не остаётся ни маяка, ни списанного предмета.
 * Именованный лок не заводится — ADR-181 §Р4 явно говорит, что здесь достаточно
 * перевёрнутого порядка плюс условной записи.
 *
 * Public API:
 *   install(int charId, array mapRow, int playerLevel, array beaconItem): array{
 *     outcome: WriteOutcome, // Applied — маяк поставлен; Refused/Missing — отказ
 *     updatedCount: int,     // total beacons after install (0 на отказе)
 *     beaconLeft: int        // remaining у inventory after subtract (0 на отказе)
 *   }
 *
 * Side effects (только при Applied):
 *  1. decrementIfAtLeast('crafted_items_log', ..., 'quantity', 1, deleteWhenEmpty: true)
 *  2. INSERT teleport_beacons row (ownership_type='author', remaining_uses и
 *     tax_cost — из админки, см. {@see BeaconSettings})
 *  3. Read total beacon count + beacon items_left для downstream display
 */
class BeaconInstaller
{
    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private TeleportBeaconModel $teleportBeaconModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private ConditionalWriteService $writeService;
    /**
     * Запас телепортов и налог больше не константы этого класса: это баланс, и по
     * ADR-024 он живёт в админке ({@see BeaconSettings}). Раньше 100/180 стояли
     * литералами и здесь, и в текстах экранов — при ребалансе экраны начали бы врать.
     */
    private BeaconSettings $balance;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(
        ?TeleportBeaconModel $teleportBeaconModel = null,
        ?CraftedItemsLogModel $craftedItemsLogModel = null,
        ?BeaconSettings $balance = null,
        ?BaseConnection $db = null,
        ?ConditionalWriteService $writeService = null
    ) {
        $this->teleportBeaconModel  = $teleportBeaconModel  ?? new TeleportBeaconModel();
        $this->craftedItemsLogModel = $craftedItemsLogModel ?? new CraftedItemsLogModel();
        $this->balance              = $balance              ?? new BeaconSettings();
        $this->db                   = $db                   ?? Database::connect();
        $this->writeService         = $writeService          ?? new ConditionalWriteService($this->db);
    }

    /**
     * Установка маяка: списание предмета (условная запись) → INSERT row →
     * counters return, одной транзакцией.
     *
     * @param array<string,mixed> $mapRow   map table row (потрібен `id`,
     *                                       `coordinate_x`, `coordinate_y`)
     * @param array<string,mixed> $beaconItem crafted_items row для inventory
     *                                        lookup (потрібен `id`)
     * @return array{outcome: WriteOutcome, updatedCount: int, beaconLeft: int}
     */
    public function install(
        int $charId,
        array $mapRow,
        int $playerLevel,
        array $beaconItem
    ): array {
        $this->db->transStart();

        // 1) Списание предмета — условной записью, а не read-then-write. Читаем
        // только `id` строки лога — решает `WHERE quantity >= ?` внутри примитива,
        // не это чтение (см. докблок ConditionalWriteService).
        $logRow = $this->craftedItemsLogModel
            ->where('character_id', $charId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();

        $logRowId = is_array($logRow) && isset($logRow['id']) && is_scalar($logRow['id'])
            ? (int) $logRow['id']
            : null;

        $outcome = $logRowId !== null
            ? $this->writeService->decrementIfAtLeast('crafted_items_log', $logRowId, 'quantity', 1, true)
            : WriteOutcome::Missing;

        if ($outcome !== WriteOutcome::Applied) {
            $this->db->transComplete();

            return [
                'outcome'      => $outcome,
                'updatedCount' => 0,
                'beaconLeft'   => 0,
            ];
        }

        // 2) Списание подтверждено — теперь можно вставлять маяк.
        $this->teleportBeaconModel->insert([
            'character_id'             => $charId,
            'faction_id'               => null,
            'player_level_at_creation' => $playerLevel,
            'map_cell_id'              => (int) $mapRow['id'],
            'coordinate_x'             => $mapRow['coordinate_x'],
            'coordinate_y'             => $mapRow['coordinate_y'],
            'tax_cost'                 => $this->balance->taxPerDay(),
            'remaining_uses'           => $this->balance->maxUses(),
            'last_teleport_at'         => null,
            'ownership_type'           => 'author',
            'settings_json'            => null,
        ]);

        // 3) Total beacon count post-INSERT
        $updatedCount = $this->teleportBeaconModel
            ->where('character_id', $charId)
            ->countAllResults();

        // 4) Read remaining beacon items для display
        $beaconLogUpdated = $this->craftedItemsLogModel
            ->where('character_id', $charId)
            ->where('crafted_item_id', $beaconItem['id'])
            ->first();
        $beaconLeft = $beaconLogUpdated ? (int) $beaconLogUpdated['quantity'] : 0;

        $this->db->transComplete();

        return [
            'outcome'      => WriteOutcome::Applied,
            'updatedCount' => (int) $updatedCount,
            'beaconLeft'   => $beaconLeft,
        ];
    }
}
