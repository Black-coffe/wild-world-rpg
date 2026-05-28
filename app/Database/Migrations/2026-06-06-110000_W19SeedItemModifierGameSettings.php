<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W19 (vNext2, Фаза 4, ADR-074) — GameSettings модернизации предметов (admin-tunable, ADR-024).
 *
 * `craft.modifier.enabled` (bool, default **false = DORMANT**): кнопка «🔧 Модернизация» скрыта,
 * enchant no-op, боевой множитель = 1.0 (0 эффекта). Стоимость (gold + ресурс) и бонус — tunable.
 * Idempotent.
 */
class W19SeedItemModifierGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'craft.modifier.enabled',
                'category'           => 'craft',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch модернизации предметов (ADR-074). true — доступна кнопка «🔧 Модернизация» в крафт-меню, игрок тратит gold + ресурс на +X% к урону экземпляра оружия, боевой множитель применяется в PvE и PvP. false — DORMANT: кнопка скрыта, enchant отключён, множитель 1.0 (0 эффекта). Активация в конце ROADMAP.',
                'effect_text'        => 'ItemModifierService::enabled. true → EnchantAction работает, getEquippedWeapon/getEquipmentBonuses множат урон на модификатор экземпляра.',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (текущий default) = модернизация выключено, бой не затронут (множитель 1.0).',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.gold_cost',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 2000,
                'default_value_text' => '2000',
                'rationale_text'     => 'Стоимость одного модернизации в золоте. 2000 — заметный gold-sink для среднего игрока, но достижимо; вместе с редким ресурсом делает усиление осмысленной инвестицией, а не рутиной.',
                'effect_text'        => 'ItemModifierService::enchant списывает с characters.gold перед наложением модификатора.',
                'above_effect_text'  => 'Выше → модернизация становится эндгейм-роскошью, сильный gold-sink (антиинфляция), но реже используется.',
                'below_effect_text'  => 'Ниже → дёшево и массово, слабый sink, риск что у всех максимальные модификаторы.',
                'recommended_min'    => '500',
                'recommended_max'    => '10000',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.resource_name',
                'category'           => 'craft',
                'value_type'         => 'string',
                'value_string'       => 'Минералы',
                'default_value_text' => 'Минералы',
                'rationale_text'     => 'Имя ресурса-катализатора модернизации (точное name из таблицы resources). «Минералы» — редкий материал (rarity-1), тематично: переплавка металла усиливает кромку оружия. Пустая строка = только золото.',
                'effect_text'        => 'ItemModifierService::enchant требует и списывает этот ресурс через CharacterResourceModel::deductResourceForCraft по имени.',
                'above_effect_text'  => 'n/a (строка — имя ресурса). Смена на более редкий ресурс ужесточает гейт.',
                'below_effect_text'  => 'Пустая строка → модернизация стоит только золота (без ресурс-гейта).',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.resource_qty',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Количество ресурса-катализатора за одно модернизация. 5 — ощутимая, но накопимая трата редкого ресурса в дополнение к золоту.',
                'effect_text'        => 'ItemModifierService::enchant списывает столько единиц craft.modifier.resource_name.',
                'above_effect_text'  => 'Выше → жёстче ресурс-гейт, модернизация реже (эндгейм-сток редкого ресурса).',
                'below_effect_text'  => 'Ниже/0 → ресурс почти/не нужен, основной барьер — золото.',
                'recommended_min'    => '0',
                'recommended_max'    => '20',
                'hard_min'           => '0',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft.modifier.bonus_pct',
                'category'           => 'craft',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Бонус к урону оружия от одного модернизации, в процентах. 5% — умеренный прирост: заметен, но не ломает баланс (1 модификатор на предмет, перезапись — без стэка). Накапливаемые тиры — W20.',
                'effect_text'        => 'ItemModifierService::enchant пишет bonus_pct в item_modifiers; weaponDamageMultiplier = 1 + bonus_pct/100.',
                'above_effect_text'  => 'Выше → оружие сильнее доминирует над статами/уровнем; при больших значениях ломает PvE/PvP-баланс урона.',
                'below_effect_text'  => 'Ниже → модернизация почти неощутимо, демотивирует тратить gold+ресурс.',
                'recommended_min'    => '1',
                'recommended_max'    => '15',
                'hard_min'           => '0',
                'hard_max'           => '100',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'craft.modifier.enabled',
                'craft.modifier.gold_cost',
                'craft.modifier.resource_name',
                'craft.modifier.resource_qty',
                'craft.modifier.bonus_pct',
            ])->delete();
    }
}
