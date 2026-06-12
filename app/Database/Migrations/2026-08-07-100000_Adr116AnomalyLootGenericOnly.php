<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E16 Ф2 (ADR-116) — лут аномалий generic-only (решение владельца при активации, 2026-06-12).
 *
 * Belt-экономика (gather E16 Ф1 + грандмастер-синк E16 Ф2) запущена 06-11…06-12 (0 данных) → НЕ
 * пре-фаучить поясное rarity-1 сырьё 3-м источником (аномалия-лут) до данных. Убираем belt-компонент
 * (RiftCrystal/ForerunnerAsh) из loot_config 2 аномалий, замещаем generic mid-tier (сохраняем объём
 * лута). Поясное сырьё остаётся gather+грандмастер. Idempotent (set точного config). settlements=KEEP.
 *
 * Гудящее Поле уже generic — не трогаем.
 */
class Adr116AnomalyLootGenericOnly extends Migration
{
    /** @var array<string, array<string, int>> code → новый loot_config.resources (generic-only) */
    private const LOOT = [
        'anomaly_forerunner_rift' => ['RareMetals' => 7, 'Stones' => 10, 'Oil' => 4], // было +RiftCrystal:2
        'anomaly_ash_barrow'      => ['Ironstone' => 10, 'Oil' => 5, 'Stones' => 6],  // было +ForerunnerAsh:2
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (self::LOOT as $code => $resources) {
            $this->db->table('settlements')
                ->where('code', $code)
                ->update([
                    'loot_config' => json_encode(['resources' => $resources], JSON_UNESCAPED_UNICODE),
                    'updated_at'  => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Восстановить belt-компонент (исходный сид).
        $orig = [
            'anomaly_forerunner_rift' => ['RiftCrystal' => 2, 'RareMetals' => 6, 'Stones' => 8],
            'anomaly_ash_barrow'      => ['ForerunnerAsh' => 2, 'Ironstone' => 8, 'Oil' => 4],
        ];
        foreach ($orig as $code => $resources) {
            $this->db->table('settlements')
                ->where('code', $code)
                ->update(['loot_config' => json_encode(['resources' => $resources], JSON_UNESCAPED_UNICODE)]);
        }
    }
}
