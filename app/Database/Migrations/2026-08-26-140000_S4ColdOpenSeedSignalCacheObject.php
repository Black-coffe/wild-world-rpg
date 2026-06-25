<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — seed-anchor world_objects «OnbSignalCache» (спина-слайс 3).
 *
 * Один контент-anchor для cold-open приманки: biome_world_object_map ссылается на него (FK
 * world_object_id), ColdOpenSignalService находит его по name_en. НЕ спавнится кроном
 * (не в SUPPORTED_SPAWN_TYPES WorldObjectGeneratorHandler) и НЕ обрабатывается ObjectDiscoveryService
 * (нет в handler-map → null) — приманку кладёт/забирает только ColdOpenSignalService. Награда
 * читается из GameSettings (bait_gold/bait_resource_*), не из contents. world_objects = контент-
 * определение (WipeManifest KEEP, таблица уже классифицирована). Idempotent по name_en.
 */
class S4ColdOpenSeedSignalCacheObject extends Migration
{
    private const NAME_EN = 'OnbSignalCache';

    public function up(): void
    {
        $exists = $this->db->table('world_objects')->where('name_en', self::NAME_EN)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('world_objects')->insert([
            'name'            => 'Сигнальный тайник',
            'name_en'         => self::NAME_EN,
            'handler_key'     => null, // дискавери/награда — в ColdOpenSignalService, не через ObjectDiscoveryService
            'description'     => 'Cold-open приманка (ADR-139, S4-3): источник радио-сигнала у спавна новичка. Размещается/забирается ColdOpenSignalService; награда — GameSettings bait_*.',
            'biome_id'        => null,
            'max_count'       => 0,
            'discovery_tools' => null,
            'contents'        => null,
            'respawn_time'    => 0,
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('world_objects')->where('name_en', self::NAME_EN)->delete();
    }
}
