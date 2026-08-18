<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-024 — баланс ран, которые не лечатся едой, целиком в админке.
 *
 * 🔴 Killswitch `debuff.enabled` = 0 (ВЫКЛЮЧЕН) при выкатке. Слой меняет правила
 * выживания сразу для всех живых игроков: часть лечения перестаёт доходить, а дела
 * начинают идти дольше. Такое включают осознанно и с объявлением, а не молча вместе
 * с деплоем. Шансы получить рану тоже 0 — двойной предохранитель: даже случайное
 * включение killswitch'а само по себе не начнёт раздавать раны.
 *
 * Порядок включения: сначала шансы (`debuff.chance.*_percent`), потом killswitch.
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key.
 */
class DebuffGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'debuff.enabled',
                'category'           => 'combat',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'Killswitch слоя «раны, которые не лечатся едой» (отравление, ожог, обморожение, перелом). Выключен на выкатке: слой меняет правила выживания для всех живых игроков разом — лечение перестаёт доходить до максимума, дела идут дольше. Включать после объявления и после того, как выставлены шансы получения.',
                'effect_text'        => 'При 1: раны выдаются источниками, действуют (тик отравления, потолок лечения, замедление сборки), видны на карточке персонажа и в «Аптечке», снимаются профильным предметом. При 0: ничего не выдаётся, уже выданные строки не действуют (сервис отдаёт пустой список), тик-крон — no-op.',
                'above_effect_text'  => 'Значений выше 1 нет — это переключатель.',
                'below_effect_text'  => 'При 0 механика полностью спит; лекарства снова остаются без своей ниши, а еда лечит всё.',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'debuff.heal_cap_percent',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 70,
                'default_value_text' => '70',
                'rationale_text'     => 'Потолок лечения при ожоге или обморожении: выше этой доли здоровья не подняться, пока рану не обработали. 70% ощутимо, но не смертельно: игрок продолжает играть, просто перестаёт быть неуязвимым за счёт еды. Это и есть ответ на «быстрее отжираться консервами».',
                'effect_text'        => 'Применяется как верхняя граница здоровья в момент применения ЛЮБОГО расходника (и еды, и лекарства) — единая точка `UsePharmacyAction`.',
                'above_effect_text'  => 'При 95 потолок почти незаметен: рана перестаёт что-либо значить, лекарства снова не нужны.',
                'below_effect_text'  => 'При 30 обожжённый игрок остаётся при трети здоровья и почти беспомощен в бою — рана превращается в приговор, а не в неудобство.',
                'recommended_min'    => 50,
                'recommended_max'    => 85,
                'hard_min'           => 10,
                'hard_max'           => 100,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'debuff.slowdown_percent',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Насколько дольше идёт сборка при переломе (за каждую ступень тяжести). 25% заметно в планировании дня, но не отбивает желание играть.',
                'effect_text'        => 'Множитель длительности крафта/ремонта в `CraftDurationService` — единственный множитель БОЛЬШЕ единицы, показан игроку в разбивке отдельной строкой «Перелом».',
                'above_effect_text'  => 'При 80 любая сборка почти вдвое дольше — игрок предпочтёт вообще не играть, пока не вылечится.',
                'below_effect_text'  => 'При 5 перелом неощутим, и лечить его незачем.',
                'recommended_min'    => 10,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 100,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'debuff.poison.hp_per_tick',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько здоровья забирает один тик отравления (умножается на тяжесть 1–3). При тике раз в 20 минут это ~9 HP в час на первой ступени — заметно, но час на возвращение к аптечке у игрока есть.',
                'effect_text'        => 'Списывается фоновым `DebuffTickHandler`. Здоровье не опускается ниже 1: яд делает хрупким, но не убивает — смерть в игре своя механика со своими последствиями.',
                'above_effect_text'  => 'При 20 отравление сжигает здоровье быстрее, чем игрок успеет дойти до базы; фактически это отложенная смерть.',
                'below_effect_text'  => 'При 1 яд можно игнорировать и просто есть — лекарства опять не нужны.',
                'recommended_min'    => 2,
                'recommended_max'    => 8,
                'hard_min'           => 1,
                'hard_max'           => 50,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'debuff.poison.tick_minutes',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Как часто отравление бьёт. 20 минут — редко настолько, чтобы не превращать игру в таймер, и часто настолько, чтобы яд не забывался.',
                'effect_text'        => 'Интервал между списаниями здоровья у одной строки отравления (`character_debuffs.last_tick_at`).',
                'above_effect_text'  => 'При 240 (4 часа) отравление почти не ощущается — игрок вылечится раньше, чем заметит.',
                'below_effect_text'  => 'При 1 минуте яд бьёт 60 раз в час и превращается в гонку со временем.',
                'recommended_min'    => 10,
                'recommended_max'    => 60,
                'hard_min'           => 1,
                'hard_max'           => 1440,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        // Шансы поймать рану при переходе — по одной записи на состояние.
        $chances = [
            'poison'    => ['Отравление во влажных биомах (леса, реки, джунгли) — укус ядовитой твари.', 'wet'],
            'burn'      => ['Ожог в вулканических территориях.', 'volcanic'],
            'frostbite' => ['Обморожение в холодных биомах (горы, тундра).', 'cold'],
            'fracture'  => ['Перелом в пещерах и подземельях — падение.', 'cave'],
        ];

        foreach ($chances as $key => [$what, $biomeType]) {
            $rows[] = [
                'setting_key'        => "debuff.chance.{$key}_percent",
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 0,
                'default_value_text' => '0',
                'rationale_text'     => "{$what} 0 на выкатке: сначала включается сам слой и проверяется на живых игроках, только потом раздаются раны. Рабочее значение — единицы процентов: рана должна быть событием, а не фоном каждого шага.",
                'effect_text'        => "Шанс (в процентах) получить состояние при входе на клетку с типом биома `{$biomeType}`. Опасность биома усиливает: danger_level 10 удваивает шанс. Повторно то же состояние при переходе не выдаётся.",
                'above_effect_text'  => 'При 50 каждый второй шаг по такому биому калечит — биом становится непроходимым, а не опасным.',
                'below_effect_text'  => 'При 0 источник выключен: состояние можно получить только другим путём (пока других нет).',
                'recommended_min'    => 1,
                'recommended_max'    => 10,
                'hard_min'           => 0,
                'hard_max'           => 100,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (empty($exists)) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->like('setting_key', 'debuff.', 'after')
            ->delete();
    }
}
