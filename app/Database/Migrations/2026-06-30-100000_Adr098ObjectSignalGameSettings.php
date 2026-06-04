<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-098 — «Радио-сигнал к редким объектам»: 3 world.signal.* GameSettings
 * (admin-tunable, ADR-024).
 *
 * Сигнал ловится в Походе (MarchingTaskHandler → ObjectSignalService): ближайший
 * active strategic-объект в радиусе → пинг с направлением + дистанцией. Killswitch,
 * радиус детекта, анти-спам кулдаун. Каждый ключ с rich rationale (why/effect/
 * above/below) — invariant ADR-024. category='world'. Idempotent по setting_key.
 */
class Adr098ObjectSignalGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.signal.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch радио-сигналов к редким (strategic) объектам в Походе. true — игроки в марше иногда ловят сигнал к ближайшему active бункеру/технопарку/городу-призраку и т.п.',
                'effect_text'        => 'false → ObjectSignalService.signalNearbyObjects() сразу возвращает 0, никаких сигналов не шлётся (поведение Похода byte-identical с до-ADR-098).',
                'above_effect_text'  => 'true (вкл): подсказки-сигналы к редким объектам активны — повышают находимость strategic-лута, оживляют дальние Походы.',
                'below_effect_text'  => 'false (выкл): мгновенный откат фичи без редеплоя (если сигналы спамят / вводят в заблуждение / нагружают).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'world.signal.range_cells',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Радиус детекта сигнала (клетки, Евклидова метрика) от позиции игрока в Походе. 20 — при ~20 strategic-объектах на карту 1000×1000 даёт ~50% шанс поймать сигнал за поход в 20 шагов: «иногда набредаешь на лид», но не GPS. Сигнал — лид, а не точная наводка.',
                'effect_text'        => 'ObjectSignalService ищет ближайший active strategic-объект в box [x±R, y±R] и фильтрует Евклидовым R. Пинг = направление (8 румбов) + ~дистанция до него.',
                'above_effect_text'  => 'Больше (40+) → сигналы ловятся почти каждый поход, редкие объекты находятся слишком легко, теряется ценность «находки».',
                'below_effect_text'  => 'Меньше (5-) → сигнал ловится, лишь когда игрок практически на объекте → фича почти не проявляется, ощущается мёртвой.',
                'recommended_min'    => '10',
                'recommended_max'    => '40',
                'hard_min'           => '1',
                'hard_max'           => '200',
            ],
            [
                'setting_key'        => 'world.signal.cooldown_sec',
                'value_type'         => 'int',
                'value_int'          => 600,
                'default_value_text' => '600',
                'rationale_text'     => 'Анти-спам: кулдаун (сек) на пару (игрок, конкретный объект). 600 — в длинном Походе по одному объекту игрок получает повторный пинг не чаще раза в ~10 минут (дистанция в пинге обновляется → видно, что «сигнал крепнет»), без потока сообщений каждый шаг.',
                'effect_text'        => 'После отправки сигнала ставится cache-ключ obj_signal_cd_{player}_{instance} на это время; пока он жив — повторный сигнал по этому же объекту не шлётся.',
                'above_effect_text'  => 'Больше (3600+) → за поход к объекту приходит лишь 1 пинг, игрок может «проскочить» мимо, не зная, что приблизился.',
                'below_effect_text'  => 'Меньше (60-) → пинги почти каждый шаг, пока объект в радиусе → спам в чате.',
                'recommended_min'    => '120',
                'recommended_max'    => '3600',
                'hard_min'           => '30',
                'hard_max'           => '86400',
            ],
        ];

        $defaults = [
            'category'        => 'world',
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

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.signal.enabled',
            'world.signal.range_cells',
            'world.signal.cooldown_sec',
        ])->delete();
    }
}
