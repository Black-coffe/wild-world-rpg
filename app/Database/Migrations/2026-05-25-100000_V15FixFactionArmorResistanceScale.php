<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V15 (ROADMAP-vNext Фаза 3 closer) — фикс шкалы сопротивлений фракционной брони.
 *
 * Баг V14 (вскрыт Tier-3 prep): 4 фракционные брони авторились с сопротивлениями
 * в шкале 0–1 (фракции: 0.20, 0.25 …), тогда как ВСЯ остальная броня и боёвка
 * используют шкалу 0–100 (как `armor_value`). `PvpDamageCalculator::computeArmorResistance`
 * делит значение на 100 → у фракц. брони эффект выходил ~0 (0.20/100 = 0.2%
 * вместо задуманных 20%). То есть тематические сопротивления (ADR-046:
 * +20% физ / +25% огонь / +25% яд / +20% скрытность) были мертвы даже в PvP.
 *
 * Фикс — привести 4 брони к шкале 0–100 (×100), как задокументировано в ADR-046.
 * Идемпотентно: задаём явные целевые значения по name_en (повтор = no-op).
 * Значения зеркалят исправленный исходник `…100000_V14AddFactionArmor`.
 *
 * После фикса: сопротивления реально работают в PvP, а экраны (gear-detail /
 * craft-preview / ToggleEquip) показывают корректные «%».
 */
class V15FixFactionArmorResistanceScale extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // name_en => [physical, fire, poison, speed_modifier, stealth_modifier] в шкале 0–100.
        $fixes = [
            'BunkerPlateArmor'    => [20, 5, 0, -8, -5],
            'TechnoShieldSuit'    => [10, 25, 5, 0, 0],
            'GhostCityCloak'      => [8, 0, 5, 10, 20],
            'FarmsteadGuardArmor' => [12, 5, 25, -2, 0],
        ];

        foreach ($fixes as $nameEn => [$phys, $fire, $poison, $speed, $stealth]) {
            $this->db->table('outfits')
                ->where('name_en', $nameEn)
                ->update([
                    'physical_resistance' => $phys,
                    'fire_resistance'     => $fire,
                    'poison_resistance'   => $poison,
                    'speed_modifier'      => $speed,
                    'stealth_modifier'    => $stealth,
                    'updated_at'          => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Откат к исходной (баговой) фракционной шкале 0–1 — для симметрии миграции.
        $revert = [
            'BunkerPlateArmor'    => [0.20, 0.05, 0, -0.08, -0.05],
            'TechnoShieldSuit'    => [0.10, 0.25, 0.05, 0, 0],
            'GhostCityCloak'      => [0.08, 0, 0.05, 0.10, 0.20],
            'FarmsteadGuardArmor' => [0.12, 0.05, 0.25, -0.02, 0],
        ];

        foreach ($revert as $nameEn => [$phys, $fire, $poison, $speed, $stealth]) {
            $this->db->table('outfits')
                ->where('name_en', $nameEn)
                ->update([
                    'physical_resistance' => $phys,
                    'fire_resistance'     => $fire,
                    'poison_resistance'   => $poison,
                    'speed_modifier'      => $speed,
                    'stealth_modifier'    => $stealth,
                ]);
        }
    }
}
