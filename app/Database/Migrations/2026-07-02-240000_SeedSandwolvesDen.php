<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 2 — «Логово Песчаных Волков» (враждебный хаб). По §Принцип 1 — НА СЕВЕРЕ карты
 * (низкий Y, опасный биом вулкан/пустыня): чем севернее, тем опаснее/ценнее.
 *
 * type=bandit, zone_policy=hostile (НЕ safe → атака/провокация доступны = опасность). Мэр = Карн
 * (RaiderWarlordKarn, уже построен ADR-089) — его home_cell переносим на якорь Логова. +2 жителя
 * (passive, чтобы хаб был доступен, а не авто-бой): Барыга-скупщик (чёрный рынок) + Крупье (казино).
 * Опасность = сильный Карн + hostile-приём + провокация→бой (его дерево). Услуги: 🖤 чёрный рынок
 * (sell) + 🎰 казино (entertainment).
 *
 * enabled=1; слой уже активен (killswitch settlements.enabled ON с Ф1). Idempotent.
 */
class SeedSandwolvesDen extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Северная опасная клетка: вулкан(8)/пустыня(9), Y 120-340, ближе к X500/Y200.
        $cell = $this->db->query(
            'SELECT cell_number, coordinate_x, coordinate_y FROM map '
            . 'WHERE coordinate_y BETWEEN 120 AND 340 AND biome_id IN (8,9) '
            . 'ORDER BY (ABS(coordinate_x-500)+ABS(coordinate_y-200)) ASC LIMIT 1'
        )->getRowArray();
        if (empty($cell)) {
            $cell = $this->db->query(
                'SELECT cell_number, coordinate_x, coordinate_y FROM map '
                . 'WHERE coordinate_y BETWEEN 100 AND 400 AND biome_id IS NOT NULL AND biome_id <> 4 '
                . 'ORDER BY coordinate_y ASC LIMIT 1'
            )->getRowArray();
        }
        if (empty($cell)) {
            return;
        }
        $anchor = (int) $cell['cell_number'];
        $ax     = (int) $cell['coordinate_x'];
        $ay     = (int) $cell['coordinate_y'];

        // 2. Новые жители (passive). [en, ru, описание, greeting, talk, ask]
        $residents = [
            ['DenFenceBaryga', 'Барыга-скупщик',
                'Скользкий тип с бегающими глазами и тяжёлым кошелём — берёт что угодно, не спрашивая, откуда.',
                'Барыга ухмыляется щербатым ртом: «А-а, свежая кровь. Принёс что продать? У нас тут берут всё — и платят честно, по-волчьи.»',
                '«Чёрный рынок, друг. Тут уходит то, что в приличных местах не возьмут. Без лишних вопросов.»',
                '«Совет? Не зли Карна. И не доставай ствол в Логове, если не готов пустить его в ход.»'],
            ['DenCroupierLot', 'Крупье Жребий',
                'Худой человек с колодой и костями, в глазах — азарт и пустота; крутит игру для всех, кто рискнёт.',
                'Крупье тасует колоду: «Заходи, рискни. Колесо, кости, камень-ножницы — пустошь и так лотерея, чего бояться?»',
                '«Здесь либо подымаешься, либо уходишь ни с чем. Зато весело. Делай ставку.»',
                '«Шансы честные — Карн не любит, когда жульничают на его земле. Проигрался — сам виноват.»'],
        ];

        $newIds = [];
        foreach ($residents as $r) {
            [$en, $ru, $desc, $greet, $talk, $ask] = $r;
            $existing = $this->db->table('npcs')->where('npc_name_en', $en)->get()->getRowArray();
            if (! empty($existing)) {
                $newIds[$en] = (int) $existing['id'];
                continue;
            }
            $this->db->table('npcs')->insert([
                'npc_name_en'  => $en,
                'npc_name_ru'  => $ru,
                'npc_type'     => 'neutral',
                'description'  => $desc,
                'level'        => 12,
                'health'       => 200,
                'strength'     => 14,
                'agility'      => 12,
                'intellect'    => 10,
                'damage_type'  => 'Physical',
                'damage_value' => 18,
                'attack_speed' => 1,
                'armor'        => 14,
                'ai_behavior'  => 'passive',
                'faction_id'   => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $npcId = (int) $this->db->insertID();
            $newIds[$en] = $npcId;
            foreach ([['greeting', $greet], ['talk', $talk], ['ask', $ask]] as $d) {
                $this->db->table('npc_dialogues')->insert([
                    'npc_id' => $npcId, 'dialogue_type' => $d[0], 'text_ru' => $d[1],
                    'weight' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // 3. Карн → мэр Логова: его home_cell переносим на якорь (NamedNpcPlacement поставит его сюда).
        $karn = $this->db->table('npcs')->where('npc_name_en', 'RaiderWarlordKarn')->get()->getRowArray();
        $karnId = $karn ? (int) $karn['id'] : 0;
        if ($karnId > 0) {
            $cs = [];
            if (is_string($karn['custom_settings'] ?? null) && $karn['custom_settings'] !== '') {
                $dec = json_decode($karn['custom_settings'], true);
                if (is_array($dec)) {
                    $cs = $dec;
                }
            }
            $cs['home_cell'] = $anchor;
            $cs['home_x']    = $ax;
            $cs['home_y']    = $ay;
            $this->db->table('npcs')->where('id', $karnId)->update(['custom_settings' => json_encode($cs, JSON_UNESCAPED_UNICODE)]);
        }

        // 4. Поселение (idempotent по code).
        $code = 'sandwolves_den';
        $existSettle = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
        if (empty($existSettle)) {
            $this->db->table('settlements')->insert([
                'code'           => $code,
                'name_ru'        => 'Логово Песчаных Волков',
                'type'           => 'bandit',
                'anchor_cell'    => $anchor,
                'coordinate_x'   => $ax,
                'coordinate_y'   => $ay,
                'faction_id'     => null,
                'zone_policy'    => 'hostile',
                'icon'           => '🔥',
                'description_ru' => 'Лагерь рейдеров вожака Карна на выжженном севере. Сюда не ходят за безопасностью — ходят за тем, чего нет у честных: чёрный рынок, кости и колесо. Но держи ствол в кобуре и язык за зубами: Песчаные Волки злопамятны.',
                'enabled'        => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $settlementId = (int) $this->db->insertID();
        } else {
            $settlementId = (int) $existSettle['id'];
        }

        // 5. Ростер: Карн (mayor) + Барыга (blackmarket) + Крупье (casino).
        $roster = [];
        if ($karnId > 0) {
            $roster[] = [$karnId, 'mayor', null, 0];
        }
        if (isset($newIds['DenFenceBaryga'])) {
            $roster[] = [$newIds['DenFenceBaryga'], 'vendor', 'blackmarket', 1];
        }
        if (isset($newIds['DenCroupierLot'])) {
            $roster[] = [$newIds['DenCroupierLot'], 'service', 'casino', 2];
        }
        foreach ($roster as $entry) {
            [$npcId, $role, $svc, $sort] = $entry;
            $exists = $this->db->table('settlement_npcs')
                ->where('settlement_id', $settlementId)->where('npc_id', $npcId)->get()->getRowArray();
            if (empty($exists)) {
                $this->db->table('settlement_npcs')->insert([
                    'settlement_id' => $settlementId, 'npc_id' => $npcId, 'role' => $role,
                    'service_key' => $svc, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $names = ['DenFenceBaryga', 'DenCroupierLot'];
        $ids = [];
        foreach ($this->db->table('npcs')->whereIn('npc_name_en', $names)->get()->getResultArray() as $n) {
            $ids[] = (int) $n['id'];
        }
        if ($ids !== []) {
            $this->db->table('npc_dialogues')->whereIn('npc_id', $ids)->delete();
            $this->db->table('settlement_npcs')->whereIn('npc_id', $ids)->delete();
            $this->db->table('npcs')->whereIn('id', $ids)->delete();
        }
        $den = $this->db->table('settlements')->where('code', 'sandwolves_den')->get()->getRowArray();
        if (! empty($den)) {
            $this->db->table('settlement_npcs')->where('settlement_id', (int) $den['id'])->delete();
            $this->db->table('settlements')->where('id', (int) $den['id'])->delete();
        }
    }
}
