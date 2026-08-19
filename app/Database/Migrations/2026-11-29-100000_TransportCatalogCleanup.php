<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * transport-06 (ADR-174, docs/specs/transport-system/) — чистка каталога
 * `crafted_items` type='transport' перед вводом пяти рабочих рецептов.
 *
 * Три независимых действия, все безопасны при повторном запуске (обновление
 * по PK/по текущему значению, ни одна строка НЕ удаляется — 21 багованная
 * строка владения от старого крана остаётся грандфазером, решение владельца):
 *
 * 1. Новая колонка `crafted_items.status` (ENUM active/deprecated,
 *    default 'active') — до сих пор витрина не умела помечать позицию
 *    устаревшей без физического удаления строки.
 * 2. Id 48 «Воздушный шар» → status='deprecated' (полёт ломает туман войны
 *    radius-1, ADR-019 «Движение И есть разведка» — концепт §1).
 * 3. Id 46 переименован «Конная повозка»/«Horse Cart» → «Тягловая
 *    повозка»/«DraftCart» (тягловый скот узаконен Фермерам, концепт §1) и
 *    ещё три строки нормализуют `name_eng` без пробела — контракт плана
 *    фиксирует `item_name_eng` рецепта 1:1 с `crafted_items.name_eng`:
 *    id 43 'Light Cart'→'LightCart', id 47 'Mountain Bike'→'MountainBike',
 *    id 49 'Autonomous Drone'→'AutonomousDrone'. Id 50 'Snowmobile' уже
 *    без пробела — не трогаем.
 *
 * Плот/Лодка с мотором/Парусник (id 42/44/51) и Верблюд (id 45) — НЕ
 * трогаем (заморожены за водным/пустынным ADR, concept-final.md §1).
 *
 * WipeManifest не трогаем: `crafted_items` уже KEEP, новая колонка не
 * player-связанная (контент-метаданные, не прогресс персонажа).
 */
class TransportCatalogCleanup extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('status', 'crafted_items')) {
            $this->forge->addColumn('crafted_items', [
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => ['active', 'deprecated'],
                    'default'    => 'active',
                    'null'       => false,
                    'after'      => 'type',
                ],
            ]);
        }

        // Id 48 «Воздушный шар» — deprecated (не удаляем, 21 грандфазерная
        // строка владения по каталогу транспорта в сумме остаётся нетронутой).
        $this->db->table('crafted_items')
            ->where('id', 48)
            ->update(['status' => 'deprecated']);

        // Id 46: «Конная повозка»/«Horse Cart» → «Тягловая повозка»/«DraftCart».
        $this->db->table('crafted_items')
            ->where('id', 46)
            ->update([
                'name_rus' => 'Тягловая повозка',
                'name_eng' => 'DraftCart',
            ]);

        // Нормализация name_eng без пробела — контракт `item_name_eng` рецепта.
        $this->db->table('crafted_items')->where('id', 43)->update(['name_eng' => 'LightCart']);
        $this->db->table('crafted_items')->where('id', 47)->update(['name_eng' => 'MountainBike']);
        $this->db->table('crafted_items')->where('id', 49)->update(['name_eng' => 'AutonomousDrone']);
    }

    public function down(): void
    {
        $this->db->table('crafted_items')->where('id', 46)->update([
            'name_rus' => 'Конная повозка',
            'name_eng' => 'Horse Cart',
        ]);
        $this->db->table('crafted_items')->where('id', 43)->update(['name_eng' => 'Light Cart']);
        $this->db->table('crafted_items')->where('id', 47)->update(['name_eng' => 'Mountain Bike']);
        $this->db->table('crafted_items')->where('id', 49)->update(['name_eng' => 'Autonomous Drone']);
        $this->db->table('crafted_items')->where('id', 48)->update(['status' => 'active']);

        if ($this->db->fieldExists('status', 'crafted_items')) {
            $this->forge->dropColumn('crafted_items', 'status');
        }
    }
}
