<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-171 — killswitch единого пула «рюкзак + склад базы»
 * (`App\Services\Player\ResourcePoolService`, story storage-craft-insurance-01).
 *
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class Adr171SeedResourcePoolGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'storage.pool_enabled',
            'category'           => 'resources',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => 'Killswitch единого пула трат «рюкзак + склад базы» (ADR-171). true — пока игрок стоит на своей базе, крафт/ремонт/стройка/теплица видят рюкзак и склад как один запас и тратят сначала рюкзак, остаток со склада. false — откат к поведению до ADR-171: каждая механика видит только рюкзак, склад — тупик, из которого можно только вручную забрать.',
            'effect_text'        => 'ResourcePoolService::available()/consume()/breakdown(). false → isPooled() всегда false независимо от BaseCheckService, обе точки видят только character_resources.',
            'above_effect_text'  => 'Нет «above» — это bool-флаг: только true/false, промежуточных значений нет.',
            'below_effect_text'  => 'false — мгновенный откат на живом сервере, если пул спутает игроков (например, склад базы, отданной в аренду/захваченной — вопрос вне scope этой стори) без релиза, одним тумблером в админке.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $existing = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->get()
            ->getRowArray();
        if (! empty($existing)) {
            return;
        }
        $this->db->table('game_settings')->insert($row);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'storage.pool_enabled')
            ->delete();
    }
}
