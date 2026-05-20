<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S26b (ADR-031) — combat-balance GameSettings для WatchTower
 * (constitutional admin-tunable, ADR-024).
 *
 * 3 live-tunable knobs (шлифуются по фидбэку без редеплоя):
 *   - alert_range_cells: радиус, в котором вышка пингует владельца о чужаке;
 *   - defender_initiative_bonus_percent: бонус инициативы защитнику у базы;
 *   - alert_cooldown_sec: анти-спам кулдаун повторного пинга по той же паре.
 * hp/tax — building-колонки. category=combat, value_type=int. Idempotent.
 */
class S26bSeedWatchTowerGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'defense.tower.alert_range_cells',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Радиус (в клетках, Евклидова метрика), в котором WatchTower предупреждает владельца базы о входе чужого игрока. 5 шире личного радиуса обнаружения игрока (max 3) — вышка «видит дальше человека», давая шанс вернуться к базе.',
                'effect_text'        => 'TowerAlertService при каждом шаге марша любого игрока ищет active вышки в этом радиусе и шлёт владельцу Telegram-пинг (вне cooldown). Боевого эффекта сам пинг не даёт — только информацию.',
                'above_effect_text'  => 'При 15 вышка пингует о игроках за полэкрана → шум, владельца спамит даже о далёких прохожих, теряется смысл «угроза близко».',
                'below_effect_text'  => 'При 1 вышка видит только вплотную (как обычный игрок) → почти бесполезна как раннее предупреждение, не оправдывает tax 700/день.',
                'recommended_min'    => '3',
                'recommended_max'    => '10',
                'hard_min'           => '1',
                'hard_max'           => '15',
            ],
            [
                'setting_key'        => 'defense.tower.defender_initiative_bonus_percent',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'На сколько % растёт инициатива защитника-владельца у его базы при наличии active WatchTower. 8% — мягко: чаще (не всегда) бьёшь первым. Presence-based (не стакает по числу вышек). Вне cap 40% (cap только на damage_reduction).',
                'effect_text'        => 'DefenseStructureService добавляет initiative_bonus в профиль; PvpRoundOrchestrator::determineInitiative умножает i-score владельца на (1+0.08). Детерминированно (без RNG — round loop под fixture-fence).',
                'above_effect_text'  => 'При 30% защитник у базы почти всегда бьёт первым → ощутимый перекос к defender в первом ударе, особенно при равных статах.',
                'below_effect_text'  => 'При 0 вышка не даёт боевого преимущества — остаётся только alert + tax-нагрузка, теряется combat-смысл «вышка дала фору».',
                'recommended_min'    => '0',
                'recommended_max'    => '20',
                'hard_min'           => '0',
                'hard_max'           => '30',
            ],
            [
                'setting_key'        => 'defense.tower.alert_cooldown_sec',
                'value_int'          => 1800,
                'default_value_text' => '1800',
                'rationale_text'     => 'Минимум секунд между повторными пингами владельцу о ТОМ ЖЕ приближающемся игроке (анти-спам). 1800 (30 мин) — не превращает вышку в радар-трекер, но даёт повторное предупреждение если угроза задержалась.',
                'effect_text'        => 'TowerAlertService хранит cache-ключ tower_alert_cd_{owner}_{mover} с этим TTL; в пределах окна повторный пинг по той же паре не шлётся.',
                'above_effect_text'  => 'При очень большом (часы) владелец получит максимум один пинг про игрока, который кружит у базы → пропустит затяжную осаду.',
                'below_effect_text'  => 'При 0 каждый шаг чужака у базы = новый пинг → спам уведомлениями, владелец отключит бота.',
                'recommended_min'    => '300',
                'recommended_max'    => '7200',
                'hard_min'           => '0',
                'hard_max'           => '86400',
            ],
        ];

        $shared = [
            'category'   => 'combat',
            'value_type' => 'int',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'defense.tower.alert_range_cells',
            'defense.tower.defender_initiative_bonus_percent',
            'defense.tower.alert_cooldown_sec',
        ])->delete();
    }
}
