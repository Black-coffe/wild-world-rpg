<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-160 — единая точка расчёта длительности стройки: потолок счёта статов в админку.
 *
 * Время стройки — обратная интерполяция между `tasks.min_duration` и `max_duration` по
 * взвешенному счёту статов (опыт 0.3 / ловкость 0.3 / интеллект 0.4). «Потолок счёта» —
 * значение, при котором персонаж получает минимальное время. У стройки он исторически
 * 2000, у крафта 1000, и оба жили магическими числами в коде: у стройки — в ДВУХ копиях
 * формулы (`GenericBuildingAction` и `GenericBuildingInfoAction`), которые уже разъехались.
 *
 * Значение 2000 = ровно прежнее поведение (byte-identical), поэтому активация ничего не
 * меняет; ключ нужен, чтобы прогрессию можно было править без релиза.
 *
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц и player-колонок нет.
 */
class Adr160BuildDurationScoreCap extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'buildings.duration.stat_score_cap',
            'category'           => 'buildings',
            'value_type'         => 'float',
            'value_float'        => 2000.0,
            'default_value_text' => '2000',
            'rationale_text'     => 'Потолок взвешенного счёта статов (опыт×0.3 + ловкость×0.3 + интеллект×0.4), при котором стройка занимает минимальное время задачи. Ниже потолка время интерполируется к максимуму: новичок со счётом 0 получает max_duration, персонаж со счётом ≥ потолка — min_duration. Сейчас 2000 — исходное значение из кода, вдвое выше крафтового (1000): стройка намеренно «догоняет» минимум медленнее крафта, база развивается размереннее, чем верстак. Значение byte-identical прежнему поведению, поэтому ключ ничего не изменил при внедрении.',
            'effect_text'        => 'BuildDurationService::scoreCap() — единственная точка чтения; ею считают и реальную длительность стройки (GenericBuildingAction), и обещание «Строительство займёт ~N минут» на экране постройки (GenericBuildingInfoAction). Сами min/max задачи (tasks) и состав ресурсов не меняются. Множители времени (постройки/сытость/специализация/проект фракции) к стройке НЕ применяются — этот ключ на них не влияет.',
            'above_effect_text'  => 'Выше (напр. 4000): персонажи дольше остаются в верхней части коридора — стройка ощутимо длиннее на всём среднем участке прогрессии, база растёт медленнее, ценность каждой постройки выше, но у активных игроков растёт простой в ожидании.',
            'below_effect_text'  => 'Ниже (напр. 1000, как у крафта): минимум достигается вдвое раньше, ветераны строят почти по min_duration, разница между новичком и опытным сглаживается — стройка перестаёт быть ощутимым тайм-гейтом развития базы.',
            'recommended_min'    => '1000',
            'recommended_max'    => '4000',
            'hard_min'           => '100',
            'hard_max'           => '20000',
        ];

        $defaults = [
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('game_settings')->insert(array_merge($defaults, $row));
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'buildings.duration.stat_score_cap')->delete();
    }
}
