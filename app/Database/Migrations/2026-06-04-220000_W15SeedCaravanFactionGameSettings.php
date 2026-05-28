<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W15 (vNext2, Фаза 3, ADR-069) — GameSettings faction-aligned caravans (admin-tunable, ADR-024).
 *
 * `caravan.faction.enabled` (bool, default **false = DORMANT**): караваны получают
 * faction-аффилиацию (скидка члену / наценка ривалу). false → faction_id не роллится,
 * модификатор цены не применяется (V25/W5/W14 поведение). Активация в конце ROADMAP.
 * Idempotent.
 */
class W15SeedCaravanFactionGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'caravan.faction.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch faction-aligned караванов (ADR-069 W15). true — караван имеет фракцию (скидка члену той же фракции, наценка ривалу: Милитари↔Партизаны, Инженеры↔Фермеры). false — DORMANT: faction_id не роллится, модификатор цены не применяется. Активация в конце ROADMAP с анонсом.',
                'effect_text'        => 'SpawnCaravanCron роллит faction_id (resource-спавн); CaravanLookAction/CaravanBuyAction применяют member-discount/rival-markup по фракции игрока (поверх — bargain W14b).',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (текущий default) = faction-affinity выключена.',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.faction.affinity_chance',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.5,
                'default_value_text' => '0.5',
                'rationale_text'     => 'Доля resource-караванов, получающих фракцию (когда faction.enabled). 0.5 — половина караванов аффилированы, половина нейтральны (разнообразие: часть даёт фракционную скидку/наценку, часть торгует со всеми ровно).',
                'effect_text'        => 'SpawnCaravanCron: при срабатывании выставляет faction_id = random(1-4).',
                'above_effect_text'  => 'При 1.0 ВСЕ караваны фракционные → нейтральных нет, чужаки везде платят наценку.',
                'below_effect_text'  => 'При 0 ни один караван не аффилирован (faction-affinity де-факто выключена даже при enabled).',
                'recommended_min'    => '0.2',
                'recommended_max'    => '0.8',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.faction.member_discount_pct',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Скидка (%) для члена фракции каравана. 5% — мягкий бонус за фракционную идентичность поверх caravan fix-discount (и опционального торга). Не делает свои караваны раздачей.',
                'effect_text'        => 'CaravanService::applyFactionAffinity (member) = ceil(price × (100−pct)/100).',
                'above_effect_text'  => 'При 30%+ свои караваны почти даром для членов → перекос экономики фракций.',
                'below_effect_text'  => 'При 0 членство не даёт ценовой выгоды (теряется стимул «свой караван»).',
                'recommended_min'    => '3',
                'recommended_max'    => '15',
                'hard_min'           => '0',
                'hard_max'           => '90',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.faction.rival_markup_pct',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 15,
                'default_value_text' => '15',
                'rationale_text'     => 'Наценка (%) для ривала фракции каравана (hostility = markup, contract-safe — можно купить, просто дороже). 15% — заметная «настороженность к чужаку», но не блок доступа к редкому ресурсу.',
                'effect_text'        => 'CaravanService::applyFactionAffinity (rival) = ceil(price × (100+pct)/100). Ривалри: Милитари↔Партизаны, Инженеры↔Фермеры.',
                'above_effect_text'  => 'При 100%+ ривал-караваны фактически недоступны по цене → де-факто отказ-торговать (денит контент, П2/П3).',
                'below_effect_text'  => 'При 0 враждебность не ощущается (теряется половина фичи — «стрельба для враг-faction»).',
                'recommended_min'    => '10',
                'recommended_max'    => '40',
                'hard_min'           => '0',
                'hard_max'           => '300',
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
                'caravan.faction.enabled', 'caravan.faction.affinity_chance',
                'caravan.faction.member_discount_pct', 'caravan.faction.rival_markup_pct',
            ])->delete();
    }
}
