<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Лёд, пещеры, пустыня — тоже аптека» (TIPS-COVERAGE, ADR-134; D-DEAD-RES волна 2).
 *
 * Пять полевых лекарств первого верстака дают потребителя последним 14 ресурсам без дела. Совет — что сырьё
 * льдов, пещер, пустыни и вулканов тоже идёт в лекарства и где их варить. Сосед — «🐍 Лекарства из сырья пустошей»
 * (FieldMedicineFromWastelandStock, волна 1): тот называет змеиный яд, серу и снег; этот — другое сырьё и другие
 * лекарства, не дубль. Ран новые лекарства не снимают — сказано прямо, как в соседе.
 *
 * Idempotent по title_en='TailMedicineFromBiomeStock'. Без чисел баланса (лечение, уровни, время — в админке),
 * media-off самодостаточен, markdown-safe (парные *), категория «крафт» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedTailMedicineTip extends Migration
{
    private const TITLE_EN = 'TailMedicineFromBiomeStock';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🦭 *Лёд, пещеры, пустыня — тоже аптека.* Масло тюленя, обсидиан, подземные животные, миражные '
            . 'кристаллы и медицинское сырьё больше не лежат мёртвым грузом: из них варят *Тюлений бальзам*, '
            . '*Термальную закваску*, *Пещерный жир*, *Пустынную соль* и *Обезболивающий укол*. Каждое лекарство '
            . 'собирается из того, что добывают в одном краю, — сходил в льды или в пещеры, и сырьё на рецепт уже при '
            . 'тебе. Ищи их в *🔨 Крафт → 🔨 Общий крафт → 💊 Лекарства*: карточка покажет, что лечит, сколько сырья '
            . 'нужно и с какого уровня. Пьют их с экрана *💊 Аптечка*. Ран они не снимают — для ран по-прежнему '
            . 'антисептик, бинт и аптечка.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🦭 Лекарства из сырья льдов, пещер и пустыни',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', self::TITLE_EN)->delete();
    }
}
