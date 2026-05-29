<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W28 (ADR-083) — killswitch «тихий порог» уведомлений (silent threshold).
 *
 * Default OFF (dormant): при false ВСЕ уведомления приходят со звуком, как
 * сегодня — 0 player-эффекта. При true рутинные task-completions (добыча /
 * крафт / стройка / разведка-роботы / теплица / посадка / ремонт) идут с
 * disable_notification=true (без звука/вибро, в чате видны), важные (PvP-атака,
 * мировые события, ачивки, бродкасты, low-health, находки-объекты) — со звуком.
 * Игрок может вернуть себе звук рутинных через тумблер в Настройках
 * (characters.notify_sound=1).
 *
 * Активируется в конце ROADMAP (как остальные dormant-фичи vNext2).
 */
class W28SeedSilentThresholdGameSetting extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('game_settings')
            ->where('setting_key', 'notifications.silent_threshold.enabled')
            ->countAllResults();
        if ($exists > 0) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => 'notifications.silent_threshold.enabled',
            'value_type'         => 'bool',
            'value_bool'         => false,
            'default_value_text' => 'false',
            'category'           => 'experimental',
            'rationale_text'     => 'Killswitch «тихого порога» уведомлений. Default OFF — на проде всё приходит со звуком как раньше (0 эффекта). Включаем как осознанный редизайн: рутинные завершения задач не должны буцать телефон каждые несколько минут, важные события — должны.',
            'effect_text'        => 'При true рутинные task-completions (добыча/крафт/стройка/разведка-роботы/теплица/посадка/ремонт) доставляются Telegram-флагом disable_notification=true (тихо: в чате видны, телефон не звенит). Важные уведомления (PvP, события, ачивки, бродкасты, low-health, находки) остаются со звуком. Per-char override: characters.notify_sound=1 возвращает игроку звук рутинных.',
            'above_effect_text'  => 'n/a — булев ключ',
            'below_effect_text'  => 'n/a — булев ключ',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'notifications.silent_threshold.enabled')
            ->delete();
    }
}
