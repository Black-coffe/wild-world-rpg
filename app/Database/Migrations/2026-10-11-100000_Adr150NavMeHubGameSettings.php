<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 Слайс 2 — killswitch группы «🧑 Я» (пере-архитектура навигации).
 *
 * Наводит порядок в персональном блоке карточки Перс: группирует 🎒 Инвентарь / ⚔️ Экип /
 * 💊 Аптечка / 🧍 Страховка в единый блок «Я» (сейчас разбросаны вперемешку с чужегрупповыми
 * кнопками) и добавляет кнопку возврата «◀️ Я» на экраны Аптечки и Страховки (сейчас — тупики
 * без возврата на карточку: Аптечка уводит в «Действия», Страховка вообще без возврата).
 *
 * false (default) — DORMANT: карточка Перс и экраны byte-identical (кнопки/порядок как раньше,
 * возврата на экранах Аптечки/Страховки нет). true — персональный блок сгруппирован + «◀️ Я».
 * Активация после Tier-3 + решение владельца. Своп полного 6-грид reply-каркаса — на финале ADR-150.
 *
 * game_settings = KEEP (WipeManifest не трогаем — таблица уже классифицирована).
 * Idempotent по setting_key. Категория world (рядом с navigation.world_hub — Слайс 1).
 */
class Adr150NavMeHubGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'navigation.me_hub.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-150 Слайс 2: killswitch группы «🧑 Я». Аудит показал: под-хабы «📊 Прогресс»/«⚙️ Развитие»/«🎒 Инвентарь» уже консолидированы (N-навигация 11.06), но персональные кнопки (Инвентарь/Экип/Аптечка/Страховка) висят на карточке вперемешку с чужегрупповыми, а экраны Аптечки и Страховки — тупики без возврата на карточку. false (default) — DORMANT, byte-identical. true — персональный блок сгруппирован + кнопка возврата «◀️ Я» на Аптечке/Страховке. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'CharacterService.showCharacterInfo группирует 🎒/⚔️/💊/🧍 в единый блок «Я»; PharmacyAction и PersonalInsurance получают кнопку возврата «◀️ Я» (callback character). При OFF порядок кнопок и отсутствие возврата — как раньше.',
                'above_effect_text'  => 'true — персональные экраны собраны логично, из Аптечки/Страховки один тап назад на карточку (чинит тупики, что нашёл аудит). Кросс-групповые кнопки (Магазин/События/Маяки) остаются на карточке до постройки их групп (осиротеть не могут).',
                'below_effect_text'  => 'false — текущее поведение: персональные кнопки разбросаны, Аптечка уводит в «Действия», Страховка без возврата (тупик).',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'navigation.me_hub.enabled')->delete();
    }
}
