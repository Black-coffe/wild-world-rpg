<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E-FIRST (2026-11-01): первый экран новичка без конкурента.
 *
 * До первого шага у новичка бота внизу одна кнопка «🧭 Сделать первый шаг» вместо полного меню;
 * полное меню приезжает сразу после первого шага. Срез d-srez §3 (прод, октябрь): 11 из 21
 * новичка первым тапом уходят в нижнее меню и делают 6.4 тапа за сутки против 69 у вошедших
 * через первый шаг; правка текста `menu_defer` расщепление не убрала.
 *
 * Выключено по умолчанию: включение на проде через админку после зелёного Tier-3 на preprod.
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key.
 */
class ColdOpenFirstStepKeyboardGameSettings extends Migration
{
    private const KEY = 'onboarding.cold_open_v2.first_step_keyboard';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => self::KEY,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'default_value_text' => '0',
            'rationale_text'     => 'Убирает с первого экрана вторую дорогу. До первого шага у новичка внизу одна кнопка «🧭 Сделать первый шаг», ведущая туда же, куда экран Роби; полное меню приезжает сразу после шага. Правка текста носителя (menu_defer) не остановила уход новичков в меню, поэтому теперь меню до шага просто нет. Выключено по умолчанию: включается после зелёного смоука на preprod, успех и откат меряются по порогу из плана E-FIRST.',
            'effect_text'        => 'Касается только новичка бота на /start: сообщение-носитель несёт клавиатуру из одной кнопки вместо полного меню (ColdOpenWelcomeService), а её нажатие открывает компас с меткой источника `cold` (ADR-168). Экран Роби, веб (/play) и существующие игроки не меняются; /start и /menu по-прежнему возвращают полное меню.',
            'above_effect_text'  => 'Значений выше 1 нет — это переключатель.',
            'below_effect_text'  => 'Выключить (0) — новичок снова получает полное меню на первом экране, и вернётся измеренное расщепление: около половины новичков первым тапом уходят в меню и делают в разы меньше действий за сутки.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')
            ->where('setting_key', self::KEY)
            ->get()
            ->getRowArray();

        if (empty($exists)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', self::KEY)->delete();
    }
}
