<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Фикс стены онбординг-шага 3 «Руки помнят» (S10 фикс-лист, диагностика 2026-07-03).
 *
 * Диагноз: шаг 3 (craft_any) проходят лишь ~35% (главный обрыв E4-цепочки). Причина —
 * реальная ресурс-стена: шаг 2 обещает скрафтить «Повязку», но её рецепт требует
 * Травы×2 + Кора×2 + Водоросли×3, а у новичка после стартового набора
 * (Вода/Древесина/Кора/Галька) НЕТ ни Трав, ни Водорослей (Травы добываются только с lvl 2).
 * Ни один безверстачный рецепт не собирается из набора → новичок упирается в тупик.
 *
 * Фикс (Вариант A): добавляем в стартовый набор Травы (id 3) + Водоросли (id 20) —
 * ровно два недостающих инпута Повязки. Теперь фреш-чар сразу собирает обещанную
 * Повязку и проходит шаг 3, как и сулит гайд («крафти сейчас»). Фантомный «Факел»
 * (рецепта не существует) убран из done_text/хинтов отдельными правками кода.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed существующей game_settings (НЕ createTable) → WipeManifest не затрагивается
 * (game_settings уже KEEP). Идемпотентно по setting_key.
 */
class StarterKitAddHerbsAlgaeForFirstCraft extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.starter_kit.herbs',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => 'Количество Трав (resource id 3, rarity 6, lvl 2) в стартовом наборе. Добавлено для фикса стены онбординг-шага 3 «Руки помнят» (~35% прохождения — главный обрыв E4-цепочки): Повязка (первый рекомендованный крафт) требует Травы×2 + Кора×2 + Водоросли×3, но Трав у новичка не было (добываются только с lvl 2). 4 ед. = на ~2 Повязки, разблокирует первый крафт обучающей цепочки (ADR-103) без ожидания добычи.',
                'effect_text'        => 'StarterKitService::grant выдаёт это число Трав новому чару (addOrIncreaseResource) при killswitch onboarding.starter_kit.enabled=ON. Вместе с Водорослями снимает ресурс-стену шага 3 (Повязка становится собираемой сразу).',
                'above_effect_text'  => 'Выше: больше Трав на старте — можно скрафтить больше Повязок/мед-предметов сразу, но слабее стимул добывать. >50 = заметная инъекция мед-материала в раннюю экономику.',
                'below_effect_text'  => 'Ниже (0): Травы в набор не кладём — стена шага 3 «Руки помнят» возвращается (Повязку не собрать без добычи Трав, а они с lvl 2).',
                'recommended_min'    => 0,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 500,
            ],
            [
                'setting_key'        => 'onboarding.starter_kit.algae',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Количество Водорослей (resource id 20, rarity 7, lvl 1) в стартовом наборе. Добавлено для фикса стены онбординг-шага 3 «Руки помнят»: Повязка требует Водоросли×3, которых у новичка в наборе не было. 6 ед. = на ~2 Повязки, вместе с Травами разблокирует первый крафт.',
                'effect_text'        => 'StarterKitService::grant выдаёт это число Водорослей новому чару при killswitch ON. Второй недостающий инпут Повязки — вместе с Травами снимает стену шага 3.',
                'above_effect_text'  => 'Выше: больше Водорослей — больше мед-крафта сразу. >50 = заметная инъекция.',
                'below_effect_text'  => 'Ниже (0): Водоросли в набор не кладём — Повязку не собрать, стена шага 3 возвращается.',
                'recommended_min'    => 0,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 500,
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
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['onboarding.starter_kit.herbs', 'onboarding.starter_kit.algae'])
            ->delete();
    }
}
