<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADMIN-TUNABLE BALANCE (CLAUDE.md, ADR-024) — вынос скорости Похода в live-tunable
 * GameSettings. Ключ `world.march.cells_per_tick` заменяет `Config\GameBalance::marchCellsPerTick`
 * (устранение «двойной правды»: значение теперь ТОЛЬКО в game_settings, код-fallback = 3).
 *
 * Читают: MarchingTaskHandler (батч клеток за тик) + MarchAction (ETA на экране Похода)
 * через GameSettingsReaderTrait::gsInt('world.march.cells_per_tick', 3).
 *
 * Категория world. Idempotent. game_settings = KEEP (WipeManifest не трогаем).
 */
class MarchCellsPerTickGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'world.march.cells_per_tick';

        if (! empty($this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray())) {
            return; // idempotent
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => 3,
            'value_float'        => null,
            'value_bool'         => null,
            'value_string'       => null,
            'default_value_text' => '3',
            'recommended_min'    => 1,
            'recommended_max'    => 6,
            'hard_min'           => 1,
            'hard_max'           => 20,
            'rationale_text'     => 'Сколько клеток Поход проходит за один крон-тик (batch). Крон everyMinute → 1 тик = 1 мин, поэтому скорость = N клеток/мин. 3 (default) — ~20 с/клетку, втрое быстрее исходного 1/тик, но без ощущения телепорта; прерывания (встреча/вода/край/привал по усталости) корректно обрывают пачку на нужной клетке. Расход ❤️/💤 и прирост xp/стат на клетку НЕ меняются — регулируется только wall-clock длинных переходов. Триггер выноса — вопрос игрока Max Syskov про скорость Похода.',
            'effect_text'        => 'MarchingTaskHandler::handle() проходит до N клеток за один вызов (advanceOneCell в цикле); MarchAction показывает ETA = ceil(клеток / N) × marchMinutesPerCell мин.',
            'above_effect_text'  => 'Выше (напр. 6) — Поход ещё быстрее (~10 с/клетку), дальние переходы почти мгновенны; но растёт риск «проскочить» точки интереса пачкой, ощущение телепорта, и за один тик прилетает больше сообщений о находках/событиях.',
            'below_effect_text'  => 'Ниже (1) — прежний темп ~1 клетка/мин: дальние переходы долгие, игрок дольше ждёт; зато каждая клетка воспринимается отдельно и находки не сливаются в пачку.',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'world.march.cells_per_tick')->delete();
    }
}
