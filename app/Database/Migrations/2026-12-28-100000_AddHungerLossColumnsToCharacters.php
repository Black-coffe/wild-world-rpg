<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-200 (спека sudden-death-1125, ревью раунд 2) — последняя просадка здоровья от суточного голода хранится в
 * строке персонажа, а не в `action_log`.
 *
 * Раунд 1 писал её строкой `action_log` (`hunger_health_loss`), но `action_log` читают как активность игрока
 * (`TributeService::actionTimestamps()` — анти-сговор, `PlayerDetectionService::fetchLastActiveMap()`,
 * `RelocateAbandonedCharacters::findCandidates()`): голодный брошенный персонаж выглядел живым, два голодных
 * аккаунта — одновременно активными. Слово владельца: «Ещё раунд: хранить вне action_log (Рекомендую)».
 *
 * - `hunger_hp_lost` — сколько ❤️ фактически снял последний голод (после пассивного пола).
 * - `hunger_hp_lost_at` — когда. Читает `DeathMessageBuilder::recentHunger()` (окно — сутки).
 *
 * WipeManifest: `characters` = CHARACTER_RESET, обе колонки в `characterResetValues` → null (состояние
 * персонажа, не преференс). Идемпотентно (fieldExists).
 */
class AddHungerLossColumnsToCharacters extends Migration
{
    public function up(): void
    {
        $cols = [];

        if (! $this->db->fieldExists('hunger_hp_lost', 'characters')) {
            $cols['hunger_hp_lost'] = [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => true,
                'comment'    => 'ADR-200 — сколько здоровья снял последний суточный голод',
            ];
        }
        if (! $this->db->fieldExists('hunger_hp_lost_at', 'characters')) {
            $cols['hunger_hp_lost_at'] = [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'ADR-200 — когда последний суточный голод снял здоровье',
            ];
        }

        if ($cols !== []) {
            $this->forge->addColumn('characters', $cols);
        }
    }

    public function down(): void
    {
        foreach (['hunger_hp_lost', 'hunger_hp_lost_at'] as $col) {
            if ($this->db->fieldExists($col, 'characters')) {
                $this->forge->dropColumn('characters', $col);
            }
        }
    }
}
