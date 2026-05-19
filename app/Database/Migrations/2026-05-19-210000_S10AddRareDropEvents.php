<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S10 (v0.51.192) — 4 rare-drop события (1 per biome без существующего rare event'а).
 *
 * Reuse F7.2 `RareResourceGrantEffect` через `handler_key='rare_resource_grant'`
 * (ADR-023 dispatcher-priority). Соответствующие entries в
 * `Config\WorldEvents.php` добавлены параллельно (effect_params).
 *
 * Биом-распределение rare events post-S10:
 *   - Леса     (1): BerryBoom (существующий)
 *   - Горы     (2): MountainArmyDepot ← новый (S10)
 *   - Тропики  (5): ExoticFlowering + IndustrialDumpFind (S10)
 *   - Реки     (4): FishStock
 *   - Пещеры   (7): RevealingHiddenCameras + PreCollapseVaultOpening (S10)
 *   - Вулканы  (8): VolcanicFuelCache ← новый (S10)
 *
 * Tropical (5) и Caves (7) уже имели rare drops до S10 — добавляем второй
 * (специфичный к T3-крафту) для покрытия 4-resource lineup.
 *
 * Idempotent: пропускает существующие name_english (UNIQUE check).
 */
class S10AddRareDropEvents extends Migration
{
    /**
     * @var array<int, array{name:string,name_english:string,biome_ids:string,description:string,img_path:?string,duration:int}>
     */
    private const EVENTS = [
        [
            'name'         => 'Вулканический выброс',
            'name_english' => 'VolcanicFuelCache',
            'biome_ids'    => '["8"]',
            'description'  => 'Очередная сейсмическая активность вскрывает старое хранилище топливных стержней под лавовыми отложениями. Те, кто добывает в этот час, могут найти ТВЭЛы.',
            'img_path'     => null,
            'duration'     => 60,
        ],
        [
            'name'         => 'Открытие доколлапсного хранилища',
            'name_english' => 'PreCollapseVaultOpening',
            'biome_ids'    => '["7"]',
            'description'  => 'Глубокий обвал в пещере вскрыл подземное хранилище доколлапсной эпохи. Уцелевшие платы и электроника попадаются тем, кто добывает в этот момент.',
            'img_path'     => null,
            'duration'     => 60,
        ],
        [
            'name'         => 'Заброшенная промзона',
            'name_english' => 'IndustrialDumpFind',
            'biome_ids'    => '["5"]',
            'description'  => 'В тропических руинах вскрылся старый промышленный отвал. Те, кто добывает поблизости, находят чистые листы заводского пластика.',
            'img_path'     => null,
            'duration'     => 60,
        ],
        [
            'name'         => 'Армейский склад',
            'name_english' => 'MountainArmyDepot',
            'biome_ids'    => '["2"]',
            'description'  => 'В горном массиве найден заброшенный армейский склад с медицинскими реагентами. Запечатанные ампулы достаются тем, кто добывает в этот час.',
            'img_path'     => null,
            'duration'     => 60,
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::EVENTS as $row) {
            $existing = $this->db->table('events')
                ->where('name_english', $row['name_english'])
                ->get()
                ->getRowArray();

            if (! empty($existing)) {
                continue; // idempotent
            }

            $this->db->table('events')->insert([
                'name'                => $row['name'],
                'name_english'        => $row['name_english'],
                'handler_key'         => 'rare_resource_grant',
                'description'         => $row['description'],
                'biome_ids'           => $row['biome_ids'],
                'event_type'          => 'local',
                'random_coverage'     => 0,
                'duration'            => $row['duration'],
                'frequency_per_week'  => 2,
                'effect_type'         => 'none',
                'effect_value'        => null,
                'protection_item_id'  => null,
                'start_time'          => null,
                'end_time'            => null,
                'img_path'            => $row['img_path'],
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::EVENTS as $row) {
            $this->db->table('events')->where('name_english', $row['name_english'])->delete();
        }
    }
}
