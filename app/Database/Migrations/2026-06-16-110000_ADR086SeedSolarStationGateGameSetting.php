<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-086 Фаза 1b — killswitch гейта Солнечной станции (пререквизит запуска
 * роботов + телепорта). Default false (DORMANT).
 *
 * **Ship-time safe (DORMANT):** false → AutomationGateService::blocksLaunch всегда
 * false → запуск роботов/телепорта байт-идентичен текущему → 0 эффекта на живых.
 * Активация осознанно после Tier-3 + balance-pass (overlap robot/teleport-владельцев
 * со Станцией; grandfather смягчает — идущие задачи не рвутся).
 *
 * Constitutional (ADR-024): rationale/effect/above/below NOT NULL. Idempotent.
 */
class ADR086SeedSolarStationGateGameSetting extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'building.solarstation.gate.enabled',
            'category'           => 'buildings',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'Killswitch: владение Солнечной станцией = пререквизит ЗАПУСКА новых роботов и телепортаций (ADR-086 Фаза 1b, «Станция питает автоматику»). false на ship → 0 эффекта (dormant). Активация осознанно: player-hostile (новое ограничение на продвинутых), требует Tier-3 + проверки overlap robot/teleport-владельцев со Станцией. Grandfather: гейт только на старте задачи → идущие задачи не рвутся.',
            'effect_text'        => 'AutomationGateService::blocksLaunch() в StartRobotGathering/Exploration + TeleportUse + TeleportBeaconMoveConfirm. false → запуск без проверки Станции. true → нет Станции = lock-сообщение с путём постройки (идущие задачи продолжаются).',
            'above_effect_text'  => 'true: запуск роботов и телепорт требуют Станцию. Станция становится центральной энергоинфраструктурой; продвинутые без неё временно не запускают новую автоматику/телепорт (грандфазер сохраняет идущее, дешёвая L1-постройка снимает блок).',
            'below_effect_text'  => 'false: роботы/телепорт запускаются как сейчас (Станция не требуется), её роль — только prereq Арсенала + буст электроники (Фаза 1a).',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $existing = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->get()
            ->getRowArray();
        if (empty($existing)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'building.solarstation.gate.enabled')
            ->delete();
    }
}
