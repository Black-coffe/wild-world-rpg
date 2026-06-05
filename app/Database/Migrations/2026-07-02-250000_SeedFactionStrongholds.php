<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 3 — оплоты 4 фракций. Мэры = существующие лидеры (ADR-089):
 * Варга(Милитари)/Тень(Партизаны)/Дитрих(Инженеры)/Марта(Фермеры). По §Принцип 1 — СРЕДНИЙ
 * пояс карты (Y 400-560: между safe-Перекрёстком на юге и опасным Логовом на севере). Биом по
 * фракции: Милитари=Горы, Партизаны=Леса, Инженеры=Пещеры, Фермеры=Поля.
 *
 * type=faction, zone_policy=safe (нельзя бить резидентов). Различие фракций — в ПРИЁМЕ (attitude
 * по faction-модификатору NpcRelationService: тёплый своим / насторожённый чужим) + faction-проект
 * (гейтится самим factionProject). Лидеры уже квестгиверы → разговор даёт фракц-квест.
 *
 * Каждый лидер: home_cell→якорь оплота + удаление старого спавна (урок Карна Ф2: смена home не
 * двигает живой спавн) → крон пересоздаёт на месте. enabled=1; слой активен с Ф1. Idempotent.
 */
class SeedFactionStrongholds extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [code, faction_id, leader_en, biome, targetX, targetY, name, icon, lore]
        $strongholds = [
            ['stronghold_military', 1, 'MilitarySergeantVargas', 2, 250, 470, 'Бастион Милитари', '⚔️',
                'Укреплённый блокпост Милитари в горах: бетон, колючка и дисциплина. Своих встречают как братьев по оружию, чужих — стволом наизготовку.'],
            ['stronghold_partisan', 2, 'PartisanLiaisonShade', 1, 760, 450, 'Схрон Партизан', '🎯',
                'Замаскированный лесной лагерь Партизан. Сюда приходят свои — за оружием и слухами; чужак почувствует прицел в спине прежде, чем найдёт вход.'],
            ['stronghold_engineer', 3, 'EngineerMechanicDietrich', 7, 360, 540, 'Мастерские Инженеров', '⚙️',
                'Подземные мастерские Инженеров: гул генераторов, запах смазки и свет ламп. Своим тут чинят и собирают что угодно, чужих держат за порогом.'],
            ['stronghold_farmer', 4, 'FarmerElderMarta', 6, 650, 520, 'Артель Фермеров', '🌾',
                'Обнесённая частоколом ферма-артель на равнине. Своих накормят и дадут семян, чужому в лучшем случае укажут на ворота.'],
        ];

        foreach ($strongholds as $sh) {
            [$code, $facId, $leaderEn, $biome, $tx, $ty, $name, $icon, $lore] = $sh;

            // 1. Курируемая клетка среднего пояса в биоме фракции (фолбэки — шире).
            $cell = $this->db->query(
                'SELECT cell_number, coordinate_x, coordinate_y FROM map '
                . 'WHERE coordinate_y BETWEEN 400 AND 560 AND biome_id = ? '
                . 'ORDER BY (ABS(coordinate_x-?)+ABS(coordinate_y-?)) ASC LIMIT 1',
                [$biome, $tx, $ty]
            )->getRowArray();
            if (empty($cell)) {
                $cell = $this->db->query(
                    'SELECT cell_number, coordinate_x, coordinate_y FROM map '
                    . 'WHERE coordinate_y BETWEEN 400 AND 560 AND biome_id IS NOT NULL AND biome_id <> 4 '
                    . 'ORDER BY (ABS(coordinate_x-?)+ABS(coordinate_y-?)) ASC LIMIT 1',
                    [$tx, $ty]
                )->getRowArray();
            }
            if (empty($cell)) {
                continue;
            }
            $anchor = (int) $cell['cell_number'];
            $ax     = (int) $cell['coordinate_x'];
            $ay     = (int) $cell['coordinate_y'];

            // 2. Лидер → мэр: home_cell на якорь + удалить старый спавн (крон пересоздаст здесь).
            $leader   = $this->db->table('npcs')->where('npc_name_en', $leaderEn)->get()->getRowArray();
            $leaderId = $leader ? (int) $leader['id'] : 0;
            if ($leaderId > 0) {
                $cs = [];
                if (is_string($leader['custom_settings'] ?? null) && $leader['custom_settings'] !== '') {
                    $dec = json_decode($leader['custom_settings'], true);
                    if (is_array($dec)) {
                        $cs = $dec;
                    }
                }
                $cs['home_cell'] = $anchor;
                $cs['home_x']    = $ax;
                $cs['home_y']    = $ay;
                $this->db->table('npcs')->where('id', $leaderId)->update(['custom_settings' => json_encode($cs, JSON_UNESCAPED_UNICODE)]);
                $this->db->table('npc_spawns')->where('npc_id', $leaderId)->delete();
            }

            // 3. Оплот (idempotent по code).
            $exist = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
            if (empty($exist)) {
                $this->db->table('settlements')->insert([
                    'code'           => $code,
                    'name_ru'        => $name,
                    'type'           => 'faction',
                    'anchor_cell'    => $anchor,
                    'coordinate_x'   => $ax,
                    'coordinate_y'   => $ay,
                    'faction_id'     => $facId,
                    'zone_policy'    => 'safe',
                    'icon'           => $icon,
                    'description_ru' => $lore,
                    'enabled'        => 1,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $settlementId = (int) $this->db->insertID();
            } else {
                $settlementId = (int) $exist['id'];
            }

            // 4. Ростер: лидер = мэр (talk → фракц-квест/диалог; услуги лавки+проекта — type-based в хабе).
            if ($leaderId > 0) {
                $rExist = $this->db->table('settlement_npcs')
                    ->where('settlement_id', $settlementId)->where('npc_id', $leaderId)->get()->getRowArray();
                if (empty($rExist)) {
                    $this->db->table('settlement_npcs')->insert([
                        'settlement_id' => $settlementId, 'npc_id' => $leaderId, 'role' => 'mayor',
                        'service_key' => null, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $codes = ['stronghold_military', 'stronghold_partisan', 'stronghold_engineer', 'stronghold_farmer'];
        foreach ($codes as $code) {
            $s = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
            if (! empty($s)) {
                $this->db->table('settlement_npcs')->where('settlement_id', (int) $s['id'])->delete();
                $this->db->table('settlements')->where('id', (int) $s['id'])->delete();
            }
        }
    }
}
