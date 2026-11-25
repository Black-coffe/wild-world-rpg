<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-200 (спека sudden-death-1125, ревью раунд 4, minor): после S5 порог добычи в биоме события — 15, порог
 * предупреждения — 30. Rationale `health.passive_warn_threshold` из `SeedPassiveFloorSettings` утверждал, что
 * они совпадают. Меняется только текст; значение и границы не трогаются. game_settings = KEEP. Идемпотентно.
 */
class FixPassiveWarnRationale extends Migration
{
    public const KEY = 'health.passive_warn_threshold';

    public const NEW_RATIONALE = 'Игрок 24.11: «предпосылок не было» — первое предупреждение пришло в ту же минуту, что смерть. Одно сообщение при падении ниже 30 от пассивного урона: при 3–10 HP за удар у игрока остаётся несколько ударов (десятки минут), чтобы уйти на базу. Выше порога добычи в биоме события (`health.event_gather_min`) — предупреждение приходит раньше, чем добыча там закроется.';

    public const OLD_RATIONALE = 'Игрок 24.11: «предпосылок не было» — первое предупреждение пришло в ту же минуту, что смерть. Одно сообщение при падении ниже 30 от пассивного урона: при 3–10 HP за удар у игрока остаётся несколько ударов (десятки минут), чтобы уйти на базу. Совпадает с порогом добычи — одно число, которое игрок запоминает.';

    public function up(): void
    {
        $this->set(self::NEW_RATIONALE);
    }

    public function down(): void
    {
        $this->set(self::OLD_RATIONALE);
    }

    private function set(string $text): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->update(['rationale_text' => $text, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
