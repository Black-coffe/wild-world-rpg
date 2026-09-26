<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * d1-relevel-l1-l2 — награда за совет только за ручной /tips, размер — в GameSettings.
 *
 * 1. Сеет `tips.reward.experience|agility|intellect` (float) дефолтами прежних констант
 *    TipService::REWARD_* (0.01 / 0.02 / 0.04) — поведение /tips байт-идентично прежнему.
 *    Идемпотентно по `setting_key`; `game_settings` = KEEP (WipeManifest не трогаем).
 * 2. Переписывает совет #22 (`game_tips.id=22`, «Совет от Роби»): прокачка — за открытый
 *    вручную /tips, ежедневный совет от Роби — просто чтение. Без чисел баланса.
 *    UPDATE по id — идемпотентно; down() возвращает текст TipsARewriteExisting.
 */
class D1TipRewardGameSettingsAndTip22 extends Migration
{
    private const TIP_ID = 22;

    public const NEW_CONTENT = '🤖 *Совет от Роби*, открытый вручную командой /tips, даёт микро-прокачку: '
        . 'немного опыта, ловкости и интеллекта. Прочитанный совет какое-то время не повторяется — потом '
        . 'круг открывается снова. А «Совет дня», который я сам присылаю раз в сутки, — просто почитать, '
        . 'прокачки за него нет. Хочешь расти — зови /tips и читай вдумчиво!';

    public const OLD_CONTENT = '🤖 *Совет от Роби* (/tips) даёт микро-прокачку: 🌟 +0.01 опыт, 🤸 +0.02 ловкость, '
        . '🧠 +0.04 интеллект. Каждый совет засчитывается раз в 15 дней — потом круг открывается снова. Читай вдумчиво!';

    public function up(): void
    {
        $now    = date('Y-m-d H:i:s');
        $shared = [
            'category'     => 'world',
            'value_type'   => 'float',
            'value_int'    => null,
            'value_bool'   => null,
            'value_string' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        $rows = [
            [
                'setting_key'        => 'tips.reward.experience',
                'value_float'        => 0.01,
                'default_value_text' => '0.01',
                'rationale_text'     => 'Сколько опыта даёт один совет, открытый вручную командой /tips. 0.01 — прежнее зашитое значение: жест благодарности за чтение, не источник прогресса. Ежедневная авто-рассылка «Совет дня» награды не даёт вовсе (она качала до L2 тех, кто не играет).',
                'effect_text'        => 'TipService::recordView(..., reward=true) прибавляет это значение к characters.experience за каждый новый совет из /tips (каждый совет — раз в 15 дней).',
                'above_effect_text'  => 'Выше — /tips становится фармом уровня: за пул советов персонаж растёт без игры, воронка уровней теряет смысл.',
                'below_effect_text'  => 'Ниже (до 0) — чтение советов перестаёт ощущаться наградой; совет #22 обещает прокачку, которой почти нет.',
                'recommended_min'    => '0',
                'recommended_max'    => '0.05',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'tips.reward.agility',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Сколько ловкости даёт один совет, открытый вручную командой /tips. 0.02 — прежнее зашитое значение. Ловкость входит в сумму параметров, от которой считается уровень, поэтому награда держится микро.',
                'effect_text'        => 'TipService::recordView(..., reward=true) прибавляет это значение к characters.agility за каждый новый совет из /tips. Рассылка «Совет дня» не награждает.',
                'above_effect_text'  => 'Выше — чтение советов заметно двигает уровень без игры, L2 достигается одним чтением пула.',
                'below_effect_text'  => 'Ниже (до 0) — награда за чтение пропадает, обещание совета #22 становится пустым.',
                'recommended_min'    => '0',
                'recommended_max'    => '0.1',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'tips.reward.intellect',
                'value_float'        => 0.04,
                'default_value_text' => '0.04',
                'rationale_text'     => 'Сколько интеллекта даёт один совет, открытый вручную командой /tips. 0.04 — прежнее зашитое значение: интеллект — «параметр знаний», поэтому за чтение его больше, чем опыта и ловкости.',
                'effect_text'        => 'TipService::recordView(..., reward=true) прибавляет это значение к characters.intellect за каждый новый совет из /tips. Рассылка «Совет дня» не награждает.',
                'above_effect_text'  => 'Выше — интеллект и уровень растут от чтения быстрее, чем от крафта; советы вытесняют игру как путь роста.',
                'below_effect_text'  => 'Ниже (до 0) — награда за чтение пропадает, обещание совета #22 становится пустым.',
                'recommended_min'    => '0',
                'recommended_max'    => '0.2',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }

        $this->setTipContent(self::NEW_CONTENT);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'tips.reward.experience',
            'tips.reward.agility',
            'tips.reward.intellect',
        ])->delete();

        $this->setTipContent(self::OLD_CONTENT);
    }

    private function setTipContent(string $content): void
    {
        $this->db->table('game_tips')
            ->where('id', self::TIP_ID)
            ->update(['content' => $content, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
