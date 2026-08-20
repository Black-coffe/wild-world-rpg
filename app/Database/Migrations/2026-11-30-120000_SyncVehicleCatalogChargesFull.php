<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * transport-06.1 (ADR-174, `docs/specs/transport-system/`) — каталог
 * `crafted_items.durability_count` пяти машин синхронизирован с
 * единственным источником правды заряда — `world.vehicle.<key>.charges_full`
 * (`2026-11-28-100000_SeedVehicleGameSettings.php`). Свежескрафченная машина
 * читает стартовый заряд из этой каталожной колонки; до этой миграции она
 * несла старые дозаряднее числа — новая машина заводилась «неполной».
 *
 * Матчим по `name_eng`, НЕ по id — предыдущая миграция каталога транспорта
 * (`2026-11-29-100000_TransportCatalogCleanup.php`) правила строки по
 * жёстко зашитым id, ревью отдельно указало на риск: на окружении с другими
 * id это правит чужой предмет. `name_eng` — контракт 1:1 с рецептом
 * (см. ту же миграцию), уникален внутри пяти машин транспорта.
 *
 * Идемпотентно: прямой SET значения, повторный прогон ничего не меняет.
 * Если строки с таким `name_eng` нет — `update()` затрагивает 0 строк,
 * миграция не падает.
 *
 * `crafted_items` = KEEP (WipeManifest не трогаем — правим шаблон каталога,
 * не выданные экземпляры `crafted_items_log`).
 */
class SyncVehicleCatalogChargesFull extends Migration
{
    /** @var array<string, int> name_eng => charges_full (новое значение) */
    private array $chargesFull = [
        'LightCart'       => 300,
        'MountainBike'     => 350,
        'Snowmobile'       => 400,
        'DraftCart'        => 400,
        'AutonomousDrone'  => 350,
    ];

    /** @var array<string, int> name_eng => durability_count (старое значение, для down()) */
    private array $previousDurability = [
        'LightCart'       => 120,
        'MountainBike'     => 150,
        'Snowmobile'       => 100,
        'DraftCart'        => 200,
        'AutonomousDrone'  => 80,
    ];

    public function up(): void
    {
        foreach ($this->chargesFull as $nameEng => $charges) {
            $this->db->table('crafted_items')
                ->where('name_eng', $nameEng)
                ->update(['durability_count' => $charges]);
        }
    }

    public function down(): void
    {
        foreach ($this->previousDurability as $nameEng => $durability) {
            $this->db->table('crafted_items')
                ->where('name_eng', $nameEng)
                ->update(['durability_count' => $durability]);
        }
    }
}
