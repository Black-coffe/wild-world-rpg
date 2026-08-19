<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-173 — куратор пула крафт-наград PvE. RewardService::getRandomCraftedItems()
 * выбирал предметы только по цене (price >= 5000 / price < 5000), без фильтра по
 * типу — в розыгрыш попадал весь каталог crafted_items, включая постройки, роботов,
 * дронов и верстаки (те самые типы, что застрахованы craft_insurance.eligible_types).
 * Повторяет живой паттерн craft_insurance.eligible_types (ADR-172).
 *
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class Adr173SeedPveRewardPoolGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'pve.reward.craft_types',
                'category'           => 'combat',
                'value_type'         => 'string',
                'value_string'       => 'drug,food,component,tool,weapon,clothing',
                'default_value_text' => 'drug,food,component,tool,weapon,clothing',
                'rationale_text'     => 'CSV-список crafted_items.type, допущенных в пул наград за победу в PvE. Пул раньше отбирался только по цене — в розыгрыш попадал весь каталог, включая постройки, роботов, дронов и верстаки. Это ровно те типы, что застрахованы craft_insurance.eligible_types: faucet раздавал даром активы, которые страховка защищает как ценные. Список сужен до расходуемых/боевых типов, которые логично получить трофеем.',
                'effect_text'        => 'RewardService::getRandomCraftedItems() фильтрует пул по crafted_items.type IN (список). Применяется при type_filter_enabled=true.',
                'above_effect_text'  => 'Добавление building/robots/transport/workbench вернёт в пул позиции ценой в десятки тысяч золота — faucet снова обгоняет крафт и обесценивает страховку этих же типов.',
                'below_effect_text'  => 'Сужение до одного-двух типов (например только food) обеднит награду — победа над NPC станет однообразной, часть игроков перестанет замечать выпадение вообще.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pve.reward.price_cap',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 10000,
                'default_value_text' => '10000',
                'rationale_text'     => 'Потолок цены предмета, допущенного в пул наград PvE. 0 — без потолка. Белый список типов отсекает роботов/верстаки/базы по типу, потолок ловит второй класс промаха — дорогую позицию внутри разрешённого типа (weapon не гарантированно дешёвая категория).',
                'effect_text'        => 'RewardService::getRandomCraftedItems() добавляет условие price <= price_cap (если > 0) поверх фильтра по типу. Применяется при type_filter_enabled=true.',
                'above_effect_text'  => 'При 20000 в пул возвращаются позиции стоимостью в несколько боёв — faucet снова обгоняет крафт даже среди разрешённых типов.',
                'below_effect_text'  => 'При 2000 из наград исчезает почти всё оружие, награда сводится к еде и компонентам — победа над сильным NPC перестаёт ощущаться ценной.',
                'recommended_min'    => '2000',
                'recommended_max'    => '20000',
                'hard_min'           => '0',
                'hard_max'           => '200000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pve.reward.expensive_threshold',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 5000,
                'default_value_text' => '5000',
                'rationale_text'     => 'Порог цены, по которому RewardService делит NPC на «сильного» (дорогой пул) и «слабого» (дешёвый пул) при выдаче награды. Заменяет магический литерал 5000, инлайненный дважды в getRandomCraftedItems() без обоснования и давно переставший что-либо значить — робот за 80000 и Осадная башня за 1500 лежали по разные стороны только формально.',
                'effect_text'        => 'RewardService::getRandomCraftedItems($count, $expensive): $expensive=true → price >= expensive_threshold, false → price < expensive_threshold.',
                'above_effect_text'  => 'При 20000 «дорогой» пул станет совсем узким — сильные NPC будут чаще выдавать мелочь, победа над боссом обесценится.',
                'below_effect_text'  => 'При 2000 «дешёвый» пул слабых NPC начнёт включать заметно дорогие предметы — слабые враги станут неоправданно выгодной фермой.',
                'recommended_min'    => '2000',
                'recommended_max'    => '20000',
                'hard_min'           => '100',
                'hard_max'           => '200000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pve.reward.type_filter_enabled',
                'category'           => 'combat',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch фильтрации пула PvE-наград по типу и потолку цены. true — RewardService применяет pve.reward.craft_types и pve.reward.price_cap. false — мгновенный откат к поведению до фикса (весь каталог по одной только цене), на случай если куратор пула сломает выдачу наград.',
                'effect_text'        => 'RewardService::getRandomCraftedItems() — при false игнорирует craft_types и price_cap, фильтрует только по expensive_threshold (как до ADR-173).',
                'above_effect_text'  => 'Параметр bool, «выше» не применимо — включённое состояние это нормальный рабочий режим.',
                'below_effect_text'  => 'false возвращает старое поведение: в пул снова попадает весь каталог crafted_items без ограничения по типу и цене, включая роботов/верстаки/постройки.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'pve.reward.craft_types',
                'pve.reward.price_cap',
                'pve.reward.expensive_threshold',
                'pve.reward.type_filter_enabled',
            ])
            ->delete();
    }
}
