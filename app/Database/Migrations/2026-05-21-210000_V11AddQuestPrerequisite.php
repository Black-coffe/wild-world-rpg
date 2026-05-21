<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V11 (ROADMAP-CRAFT-vNext, Фаза 3 старт, ADR-036) — quest-chain foundation.
 *
 * `quests.prerequisite_quest` (nullable VARCHAR) = title_en квеста, который должен
 * быть ЗАВЕРШЁН персонажем, прежде чем этот квест станет доступен. null = нет
 * предусловия (доступен сразу — прежнее поведение, 0 регрессии для всех квестов).
 *
 * Многоэтапность = цепочка связанных квестов (A → B → C). Foundation для V12/V13
 * (strategic quest-chains) + T3→T4 (T4-крафт гейтится на финальный квест цепочки
 * через существующий S25 `required_quest`). title_en — стабильный кросс-env ключ
 * (код ссылается на квесты по title_en).
 */
class V11AddQuestPrerequisite extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('quests', [
            'prerequisite_quest' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
                'comment'    => 'V11 (ADR-036): title_en квеста-предусловия (должен быть завершён). null = доступен сразу.',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('quests', 'prerequisite_quest');
    }
}
