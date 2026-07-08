<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADMIN-TUNABLE BALANCE (CLAUDE.md §🎛️, ADR-024) — вынос ОСТАВШЕГОСЯ march-блока
 * из `Config\GameBalance` в live-tunable GameSettings (хвост после
 * `world.march.cells_per_tick`, 2026-10-05). Устранение «двойной правды»: значения
 * теперь ТОЛЬКО в game_settings, код держит лишь safe-baseline fallback в gsFloat/gsInt.
 *
 * 7 ключей (все category=world, читают MarchingTaskHandler / MarchAction /
 * MarchMiniInvestigateAction через GameSettingsReaderTrait):
 *   world.march.minutes_per_cell        (int,   1)
 *   world.march.max_steps_per_order     (int,   60)
 *   world.march.tired_cost_per_cell     (float, 0.5)
 *   world.march.health_cost_per_cell    (float, 0.02)
 *   world.march.danger_health_surcharge (float, 1.0)
 *   world.march.xp_per_cell             (float, 0.03)
 *   world.march.stat_per_cell           (float, 0.02)
 *
 * Дефолты = прежние значения GameBalance → byte-identical поведение до правки в админке.
 * Idempotent (skip по setting_key). game_settings = KEEP (WipeManifest не трогаем).
 *
 * Примечание: 2 мёртвых свойства убраны из GameBalance без выноса —
 * marchNpcEncounterChancePerCell (дубликат живого ключа npc.march_encounter_chance) и
 * marchHpHaltThreshold (нигде не читалось, зарезервированная невключённая механика).
 */
class MarchBalanceGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $keys = [
            [
                'setting_key'        => 'world.march.minutes_per_cell',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Сколько реальных минут занимает один крон-тик Похода (задержка между пачками клеток). 1 (default) при кроне everyMinute = шаг созревает к следующему тику (stepDueInterval = minutes−1 = PT0M, дрожание темпа убрано). Вместе с world.march.cells_per_tick задаёт темп: скорость = cells_per_tick клеток за minutes_per_cell мин. Триггер выноса — вопрос игрока Max Syskov про скорость Похода + перевод всего march-блока в live-tunable (ADR-024).',
                'effect_text'        => 'MarchingTaskHandler::stepDueInterval (end_time = start + (N−1)мин) и etaMinutes (ceil(cells/perTick) × N); MarchAction: ETA на экране Похода + stepDueInterval при старте/резюме; MarchMiniInvestigateAction: end_time при возобновлении после мини-события.',
                'above_effect_text'  => 'Выше (напр. 3): каждая клетка занимает 3 мин реального времени — Поход медленнее, дальние переходы дольше; зато меньше нагрузка на крон и меньше сообщений о находках. При гранулярности крона 1 мин >1 ощущается как искусственное замедление.',
                'below_effect_text'  => 'Ниже 1 невозможно (hard_min=1): крон everyMinute не спавнит шаги чаще раза в минуту. Для ускорения увеличивай world.march.cells_per_tick (клеток за тик), а не этот параметр.',
                'recommended_min'    => '1',
                'recommended_max'    => '3',
                'hard_min'           => '1',
                'hard_max'           => '10',
            ],
            [
                'setting_key'        => 'world.march.max_steps_per_order',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 60,
                'default_value_text' => '60',
                'rationale_text'     => 'Максимум клеток в одном заказе Похода (кнопка ➕ ограничена этим потолком; дальше — новый заказ / продление). 60 — «дошёл до края обжитой зоны за раз, но не через весь остров вслепую»: длинный автопереход без микроменеджмента, но игрок периодически переоценивает курс. Защита от «поставил ×9999 и ушёл на сутки».',
                'effect_text'        => 'MarchAction: clamp n (max(1, min(n, N))) при setup/start заказа и потолок кнопки ➕5. Влияет только на длину одного заказа — не на стоимость/скорость клетки.',
                'above_effect_text'  => 'Выше (напр. 200): можно заказать очень длинный автопереход — меньше кликов, но выше риск уйти далеко «вслепую» сквозь опасные биомы без переоценки курса; длинный заказ дольше висит.',
                'below_effect_text'  => 'Ниже (напр. 20): Поход дробится на частые короткие заказы — больше микроменеджмента, но игрок чаще осматривается и корректирует курс.',
                'recommended_min'    => '20',
                'recommended_max'    => '120',
                'hard_min'           => '1',
                'hard_max'           => '500',
            ],
            [
                'setting_key'        => 'world.march.tired_cost_per_cell',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.5,
                'default_value_text' => '0.5',
                'rationale_text'     => 'Расход выносливости (💤) за одну пройденную в Походе клетку. 0.5 — направленный переход эффективнее зигзага одиночными шагами (~3.35/шаг), но выносливость всё равно ограничивает дальность (~200 клеток на полном баке до привала). Топливо Похода, а не двигатель: скорость от него не зависит.',
                'effect_text'        => 'MarchingTaskHandler::advanceOneCell — futureTired = tired − N; при futureTired < 0.01 → привал (CELL_STOPPED). MarchAction показывает оценку расхода 💤 = клеток × N перед выступлением.',
                'above_effect_text'  => 'Выше (напр. 1.5): Поход быстрее выматывает — короче дальность до привала, чаще нужен отдых/еда; наказывает дальние вылазки.',
                'below_effect_text'  => 'Ниже (напр. 0.2): выносливости хватает на очень дальние переходы — Поход почти не ограничен усталостью, дальняя разведка дешевеет (риск обесценить петлю «поел/отдохнул»).',
                'recommended_min'    => '0.2',
                'recommended_max'    => '2.0',
                'hard_min'           => '0',
                'hard_max'           => '10',
            ],
            [
                'setting_key'        => 'world.march.health_cost_per_cell',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Базовый расход здоровья (❤️) за клетку Похода вне опасных биомов. 0.02 — символический износ в пути: сам по себе почти не убивает (~5000 клеток на полном HP), но складывается с danger-надбавкой и голодом. Топливо, не двигатель.',
                'effect_text'        => 'MarchingTaskHandler::advanceOneCell — hpCost = N (+ danger_health_surcharge в опасном биоме); при futureHp < 0.01 → привал. MarchAction: оценка ❤️ = клеток × N.',
                'above_effect_text'  => 'Выше (напр. 0.3): Поход сам по себе заметно ранит — дальние переходы требуют запаса HP/аптечек, растёт риск гибели в пути от накопленного урона.',
                'below_effect_text'  => '0 (или почти): переход по безопасным биомам не тратит здоровье — весь риск смещается на danger-биомы и голод.',
                'recommended_min'    => '0',
                'recommended_max'    => '0.3',
                'hard_min'           => '0',
                'hard_max'           => '10',
            ],
            [
                'setting_key'        => 'world.march.danger_health_surcharge',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 1.0,
                'default_value_text' => '1.0',
                'rationale_text'     => 'Дополнительный расход ❤️ за клетку в опасном биоме (danger_level ≥ 8: вулканы/радиация и т.п.) поверх базового health_cost_per_cell. 1.0 — переход через опасную зону ощутимо кусается (~100 клеток и ты труп без лечения), делая опасные биомы реальным барьером территории, а не декорацией.',
                'effect_text'        => 'MarchingTaskHandler::advanceOneCell — если danger_level ≥ 8: hpCost += N. Только для опасных биомов; на обычных не применяется.',
                'above_effect_text'  => 'Выше (напр. 3): опасные биомы почти непроходимы напролом — нужны буст/лечение/обход; сильный барьер для дальнего севера.',
                'below_effect_text'  => '0: опасные биомы перестают отличаться риском прохода от обычных — теряется смысл danger_level как гейта территории.',
                'recommended_min'    => '0',
                'recommended_max'    => '3',
                'hard_min'           => '0',
                'hard_max'           => '20',
            ],
            [
                'setting_key'        => 'world.march.xp_per_cell',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.03,
                'default_value_text' => '0.03',
                'rationale_text'     => 'Прирост опыта за клетку Похода (до множителя новичка ADR-138). 0.03 — Поход даёт скромный, но ощутимый XP-поток за исследование («движение И есть разведка»), не заменяя основные источники (бой/крафт/квесты). Умножается ×gain_multiplier для новичков (ранний онбординг).',
                'effect_text'        => 'MarchingTaskHandler::advanceOneCell — experience += N × marchMult; та же величина копится в acc[xp] для сводки по прибытии.',
                'above_effect_text'  => 'Выше (напр. 0.3): Поход становится сильным источником прокачки — игроки гриндят уровни ходьбой, смещая баланс прогрессии от боя/крафта к бесцельной ходьбе.',
                'below_effect_text'  => '0: Поход не даёт опыта — исследование теряет прогрессионный стимул, остаётся только раскрытие карты и находки.',
                'recommended_min'    => '0.01',
                'recommended_max'    => '0.3',
                'hard_min'           => '0',
                'hard_max'           => '10',
            ],
            [
                'setting_key'        => 'world.march.stat_per_cell',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Прирост характеристики (strength/agility/intellect по ротации через клетку) за клетку Похода, до множителя новичка. 0.02 — медленный «тренаж в пути»: дальние переходы понемногу качают стату, награждая активную разведку, но не заменяя целевую прокачку.',
                'effect_text'        => 'MarchingTaskHandler::advanceOneCell — statKey (ротация strength/agility/intellect по steps_done % 3) += N × marchMult; копится в acc[stat].',
                'above_effect_text'  => 'Выше (напр. 0.15): характеристики быстро растут от ходьбы — Поход превращается в основной стат-фарм, обесценивая другие пути прокачки статов.',
                'below_effect_text'  => '0: Поход не качает характеристики — остаётся только XP / находки / карта как награда за переход.',
                'recommended_min'    => '0.005',
                'recommended_max'    => '0.15',
                'hard_min'           => '0',
                'hard_max'           => '10',
            ],
        ];

        foreach ($keys as $row) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray())) {
                continue; // idempotent
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.march.minutes_per_cell',
            'world.march.max_steps_per_order',
            'world.march.tired_cost_per_cell',
            'world.march.health_cost_per_cell',
            'world.march.danger_health_surcharge',
            'world.march.xp_per_cell',
            'world.march.stat_per_cell',
        ])->delete();
    }
}
