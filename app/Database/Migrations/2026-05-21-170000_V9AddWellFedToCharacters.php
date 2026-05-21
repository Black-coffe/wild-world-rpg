<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V9 (ROADMAP-CRAFT-vNext, Фаза 2, ADR-034) — «Сытость» (food-buff foundation).
 *
 * `characters.well_fed_until` (nullable DATETIME) — момент истечения buff'а сытости.
 * Поел блюдо (V8) → well_fed_until = now + food.<meal>.well_fed_minutes. Пока
 * now < well_fed_until: крафт быстрее (×food.well_fed.craft_time_multiplier),
 * добыча щедрее (×food.well_fed.gather_yield_multiplier). PvE-only, детерминир.,
 * pure-bonus (null = нет buff'а = прежнее поведение, 0 регрессии).
 *
 * Lazy expiry (сравнение now на чтении) — отдельный cron не нужен.
 */
class V9AddWellFedToCharacters extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('characters', [
            'well_fed_until' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'comment' => 'V9 (ADR-034): момент истечения buff\'а «Сытость» (быстрее крафт + щедрее добыча). null = не сыт.',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('characters', 'well_fed_until');
    }
}
