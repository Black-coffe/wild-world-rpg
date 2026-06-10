<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 2 — оффлайн-digest «пока тебя не было».
 *
 *   • returnability.digest.enabled (bool, default false → DORMANT) — killswitch;
 *   • returnability.digest.grace_hours (int, default 24) — минимальная давность
 *     отсутствия, после которой при возврате показывается сводка.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class E6ReturnDigestGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'returnability.digest.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch оффлайн-digest «пока тебя не было» (ADR-108 Ф2): при первом взаимодействии после паузы ≥ grace_hours игрок получает сводку фоновых событий (достроено/скрафчено/добыто, авто-PvE нападения, мировые события) из лог-таблиц за окно [last_seen, now]. default=false → dormant. Цель — возвращаемость (E1: back-D7 ≈ 4%): «мир жил без тебя» даёт повод вернуться и заглянуть на базу.',
                'effect_text'        => 'BotController::webhook() ДО handle() зовёт ReturnDigestService::maybeSendDigest. One-shot per возврат (last_seen штампуется ПОСЛЕ обработки). Пустой digest (ничего не произошло) не отправляется. MEDIA-OFF текст Роби.',
                'above_effect_text'  => 'true: вернувшийся после паузы игрок видит сводку → выше шанс остаться (заглянуть на базу/карту). Риск минимален — шлётся только при реальных фоновых изменениях и только раз за возврат.',
                'below_effect_text'  => 'false: digest не шлётся (dormant). Возврат игрока ничем не отмечается. Emergency-off.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'returnability.digest.grace_hours',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 24,
                'default_value_text' => '24',
                'rationale_text'     => 'Минимальная давность отсутствия (часы) для показа digest. 24 = «не был сутки» — естественный порог «вернулся после паузы», за сутки накапливаются заметные фоновые изменения (налог, достройки, события). Меньше — спамим частых игроков; больше — пропускаем дневной отток.',
                'effect_text'        => 'ReturnDigestService шлёт сводку только если hoursAgo(last_seen) ≥ этого значения. Окно сбора = [last_seen, now].',
                'above_effect_text'  => 'Выше (напр. 72): digest только для давно отсутствовавших (3+ дня) — реже, но точно «вернулся после долгой паузы». Дневной отток не получит повода вернуться.',
                'below_effect_text'  => 'Ниже (напр. 6): digest даже после короткой паузы — чаще, но риск надоесть активным игрокам и показать «пустую» сводку. 1 = почти каждое возвращение.',
                'recommended_min'    => 6,
                'recommended_max'    => 72,
                'hard_min'           => 1,
                'hard_max'           => 720,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $keys = [
            'returnability.digest.enabled',
            'returnability.digest.grace_hours',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
