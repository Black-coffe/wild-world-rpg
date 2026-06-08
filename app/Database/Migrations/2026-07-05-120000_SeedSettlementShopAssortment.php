<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 3 (рефайн) — курируемый ассортимент фракционных лавок оплотов.
 *
 * Каждый оплот продаёт 3 тематичных СУЩЕСТВУЮЩИХ предмета (реюз арта/баланса; новых предметов нет):
 *  - Бастион Милитари (1): штурм. винтовка + тактическая броня + силовая броня «Титан».
 *  - Схрон Партизан (2): охотничий жилет + снайперка + «Броня Тени» (легендарка лидера Тень).
 *  - Мастерские Инженеров (3): экзоскелет + плазма + гаусс-пистолет.
 *  - Артель Фермеров (4): нагрудник + «Коса Жатвы» + «Броня хуторской стражи» (тематич. легендарки).
 *
 * base_price = курируемая цена лавки (берём каталожную price предмета). Faction-скидка/наценка
 * применяется поверх в SettlementShopService. Idempotent: резолв settlement по code, item по name_en;
 * вставка только если пары (settlement_id,item_type,item_id) ещё нет.
 */
class SeedSettlementShopAssortment extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('settlement_shop_items')) {
            return;
        }
        $now = date('Y-m-d H:i:s');

        // [settlement_code => [ [type, name_en, base_price], ... ]]
        $plan = [
            'stronghold_military' => [
                ['weapon', 'AssaultRifle556',   1500],
                ['outfit', 'TacticalArmorSuit', 1500],
                ['outfit', 'TitanPowerArmor',   3000],
            ],
            'stronghold_partisan' => [
                ['outfit', 'HunterVest',     300],
                ['weapon', 'SniperRifle308', 2200],
                ['outfit', 'ShadowArmor',    2000],
            ],
            'stronghold_engineer' => [
                ['outfit', 'ExoskeletonStrekoza', 1500],
                ['weapon', 'PlasmaRifle',         3000],
                ['weapon', 'GaussPistol',         4200],
            ],
            'stronghold_farmer' => [
                ['outfit', 'ScrapChestplate',     150],
                ['weapon', 'FarmersHarvestScythe', 42000],
                ['outfit', 'FarmsteadGuardArmor',  42000],
            ],
        ];

        foreach ($plan as $code => $items) {
            $settlement = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
            if (empty($settlement)) {
                continue;
            }
            $settlementId = (int) $settlement['id'];

            $sort = 0;
            foreach ($items as [$type, $nameEn, $price]) {
                $itemId = $this->resolveItemId($type, $nameEn);
                if ($itemId <= 0) {
                    continue;
                }
                $exists = $this->db->table('settlement_shop_items')
                    ->where('settlement_id', $settlementId)
                    ->where('item_type', $type)
                    ->where('item_id', $itemId)
                    ->get()->getRowArray();
                if (! empty($exists)) {
                    $sort++;
                    continue;
                }
                $this->db->table('settlement_shop_items')->insert([
                    'settlement_id' => $settlementId,
                    'item_type'     => $type,
                    'item_id'       => $itemId,
                    'base_price'    => (int) $price,
                    'sort_order'    => $sort++,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    private function resolveItemId(string $type, string $nameEn): int
    {
        $table = $type === 'weapon' ? 'weapons' : ($type === 'outfit' ? 'outfits' : 'crafted_items');
        $col   = $type === 'crafted' ? 'name_eng' : 'name_en';
        $row   = $this->db->table($table)->where($col, $nameEn)->get()->getRowArray();

        return ! empty($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
    }

    public function down(): void
    {
        if (! $this->db->tableExists('settlement_shop_items')) {
            return;
        }
        $codes = ['stronghold_military', 'stronghold_partisan', 'stronghold_engineer', 'stronghold_farmer'];
        foreach ($codes as $code) {
            $s = $this->db->table('settlements')->where('code', $code)->get()->getRowArray();
            if (! empty($s)) {
                $this->db->table('settlement_shop_items')->where('settlement_id', (int) $s['id'])->delete();
            }
        }
    }
}
