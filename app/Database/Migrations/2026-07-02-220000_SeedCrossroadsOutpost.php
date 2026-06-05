<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 1 — контент первого поселения «Перекрёсток» (нейтральный safe-аутпост).
 *
 * Курируемая центральная клетка (≈ X500/Y650, проходимый биом). 3 жителя-нейтрала (passive,
 * faction_id=NULL): Смотритель (mayor), Маркитант (vendor/trade), Кузнец (service/repair) — с
 * приветствиями/репликами. Ростер в settlement_npcs. settlements.enabled=1 (готов), но слой
 * gated killswitch settlements.enabled (=0 до активации после Tier-3) → пока dormant.
 *
 * Idempotent (npcs по npc_name_en, settlement по code). WipeManifest: всё KEEP / спавны TRANSIENT.
 */
class SeedCrossroadsOutpost extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Курируемая клетка: ближайшая проходимая к центру (500,650), биом не вода(4).
        $cell = $this->db->query(
            'SELECT id, cell_number, coordinate_x, coordinate_y FROM map '
            . 'WHERE coordinate_x BETWEEN 490 AND 510 AND coordinate_y BETWEEN 640 AND 660 '
            . 'AND biome_id IS NOT NULL AND biome_id <> 4 '
            . 'ORDER BY (ABS(coordinate_x-500)+ABS(coordinate_y-650)) ASC LIMIT 1'
        )->getRowArray();
        if (empty($cell)) {
            // фолбэк — любая проходимая клетка населённого яруса
            $cell = $this->db->query(
                'SELECT id, cell_number, coordinate_x, coordinate_y FROM map '
                . 'WHERE coordinate_y BETWEEN 401 AND 900 AND biome_id IS NOT NULL AND biome_id <> 4 LIMIT 1'
            )->getRowArray();
        }
        if (empty($cell)) {
            return; // нет карты — пропускаем (тестовая среда)
        }
        $anchor = (int) $cell['cell_number'];
        $ax     = (int) $cell['coordinate_x'];
        $ay     = (int) $cell['coordinate_y'];

        // 2. Жители (нейтралы, passive). [npc_name_en, npc_name_ru, описание, greeting, talk, ask]
        $residents = [
            ['SettlementKeeperCrossroads', 'Смотритель Перекрёстка',
                'Немолодой мужчина с цепким взглядом и старым револьвером у бедра — он держит это место в узде.',
                'Смотритель кивает: «Перекрёсток. Тут не стреляют — таков уговор. Отдыхай, торгуй, не нарывайся.»',
                '«Это нейтральная земля. Кто поднимет оружие на местных — вылетит вперёд ногами. Так у нас спокойно.»',
                '«Хочешь дела — иди к Маркитанту. Снаряга подвела — Кузнец починит. А я просто слежу за порядком.»'],
            ['SettlementTraderCrossroads', 'Маркитант Перекрёстка',
                'Прижимистый торговец с весами и коробом товара — на Перекрёстке всё проходит через его руки.',
                'Маркитант оценивающе щурится: «Принёс что-нибудь на продажу? Или приглядел чего? Заходи, поторгуемся.»',
                '«Беру ресурсы, отдаю звонким золотом. Цены честные — за нечестные тут можно и зубов лишиться.»',
                '«Слухи? За слухи отдельная плата. А вот товар — вот он, смотри.»'],
            ['SettlementSmithCrossroads', 'Кузнец Перекрёстка',
                'Кряжистый мастер у наковальни, руки в копоти — латает железо всем, кто доберётся живым.',
                'Кузнец вытирает руки: «Снаряга поизносилась? Тащи сюда, гляну. За золото верну в строй.»',
                '«Хорошее железо в пустоши на вес золота. Берегите инструмент — второго может не быть.»',
                '«Чинить умею многое. Ковать новое — это к мастерам в оплоты, у меня тут попроще.»'],
        ];

        $roster = []; // [npc_id, role, service_key, sort]
        $roleMap = [
            'SettlementKeeperCrossroads' => ['mayor', null, 0],
            'SettlementTraderCrossroads' => ['vendor', 'trade', 1],
            'SettlementSmithCrossroads'  => ['service', 'repair', 2],
        ];

        foreach ($residents as $r) {
            [$en, $ru, $desc, $greet, $talk, $ask] = $r;

            $existing = $this->db->table('npcs')->where('npc_name_en', $en)->get()->getRowArray();
            if (! empty($existing)) {
                $npcId = (int) $existing['id'];
            } else {
                $this->db->table('npcs')->insert([
                    'npc_name_en'  => $en,
                    'npc_name_ru'  => $ru,
                    'npc_type'     => 'neutral',
                    'description'  => $desc,
                    'level'        => 8,
                    'health'       => 140,
                    'strength'     => 10,
                    'agility'      => 8,
                    'intellect'    => 8,
                    'damage_type'  => 'Physical',
                    'damage_value' => 12,
                    'attack_speed' => 1,
                    'armor'        => 10,
                    'ai_behavior'  => 'passive',
                    'faction_id'   => null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
                $npcId = (int) $this->db->insertID();

                foreach ([['greeting', $greet], ['talk', $talk], ['ask', $ask]] as $d) {
                    $this->db->table('npc_dialogues')->insert([
                        'npc_id'        => $npcId,
                        'dialogue_type' => $d[0],
                        'text_ru'       => $d[1],
                        'weight'        => 1,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }
            }
            [$role, $svc, $sort] = $roleMap[$en];
            $roster[] = [$npcId, $role, $svc, $sort];
        }

        // 3. Поселение (idempotent по code).
        $code = 'crossroads_outpost';
        $existSettle = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
        if (empty($existSettle)) {
            $this->db->table('settlements')->insert([
                'code'           => $code,
                'name_ru'        => 'Перекрёсток',
                'type'           => 'outpost',
                'anchor_cell'    => $anchor,
                'coordinate_x'   => $ax,
                'coordinate_y'   => $ay,
                'faction_id'     => null,
                'zone_policy'    => 'safe',
                'icon'           => '🏚',
                'description_ru' => 'Нейтральный торговый узел на скрещении дорог. Здесь действует уговор: оружие в ножны, кто нарушит — выкинут. Сюда приходят торговать, чинить снаряжение и перевести дух.',
                'enabled'        => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $settlementId = (int) $this->db->insertID();
        } else {
            $settlementId = (int) $existSettle['id'];
        }

        // 4. Ростер жителей (idempotent по (settlement_id, npc_id)).
        foreach ($roster as $entry) {
            [$npcId, $role, $svc, $sort] = $entry;
            $exists = $this->db->table('settlement_npcs')
                ->where('settlement_id', $settlementId)->where('npc_id', $npcId)->get()->getRowArray();
            if (empty($exists)) {
                $this->db->table('settlement_npcs')->insert([
                    'settlement_id' => $settlementId,
                    'npc_id'        => $npcId,
                    'role'          => $role,
                    'service_key'   => $svc,
                    'sort_order'    => $sort,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $names = ['SettlementKeeperCrossroads', 'SettlementTraderCrossroads', 'SettlementSmithCrossroads'];
        $npcIds = [];
        foreach ($this->db->table('npcs')->whereIn('npc_name_en', $names)->get()->getResultArray() as $n) {
            $npcIds[] = (int) $n['id'];
        }
        if ($npcIds !== []) {
            $this->db->table('npc_dialogues')->whereIn('npc_id', $npcIds)->delete();
            $this->db->table('settlement_npcs')->whereIn('npc_id', $npcIds)->delete();
            $this->db->table('npcs')->whereIn('id', $npcIds)->delete();
        }
        $this->db->table('settlements')->where('code', 'crossroads_outpost')->delete();
    }
}
