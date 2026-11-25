<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «События ранят, но не добивают» (TIPS-COVERAGE, ADR-134; ADR-200, спека sudden-death-1125 S3).
 *
 * Игрок 24.11 умер «без предпосылок»: мировое событие довело здоровье до рулетки смерти, первое сообщение пришло
 * в минуту смерти. Теперь события и голод не опускают здоровье ниже пола, но добыча в биоме события на полу
 * прерывается, а новая не начинается, пока здоровье низкое. Совет — что это значит для игрока и куда идти.
 * Сосед в той же теме — раздел `/guide` «🌟 Мировые события» (`worldevents`); совет короче и про действие.
 *
 * Idempotent по title_en='EventsWoundNotKill'. Без чисел баланса (пол и пороги — в админке), media-off
 * самодостаточен, markdown-safe (парные *), категория «события» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedEventsWoundNotKillTip extends Migration
{
    public const TITLE_EN = 'EventsWoundNotKill';
    public const TITLE_RU = '❤️ События ранят, но не добивают';
    public const TIP_TYPE = 'события';
    public const CONTENT  = '⚠️ *Мировые события ранят, но не добивают.* Буря, толчки или туман могут просадить '
        . 'здоровье до последней черты — но ниже неё не опустят. Об уроне сказано уже в сообщении о начале события, '
        . 'а когда здоровье просядет, я напишу тебе сразу. Цена у этой черты есть: с низким здоровьем добыча в биоме '
        . 'события прервётся (что успел накопать — твоё), и новая там не начнётся, пока не подлечишься. Так что '
        . 'услышал о событии в своём краю — иди на базу: стоя на её клетке, ты в укрытии. Лечись едой или медициной '
        . 'с экрана *💊 Аптечка*. Поход и шаги в опасные биомы — другое дело: там риск прежний.';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $this->db->table('game_tips')->insert([
            'title_ru'   => self::TITLE_RU,
            'title_en'   => self::TITLE_EN,
            'tip_type'   => self::TIP_TYPE,
            'content'    => self::CONTENT,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', self::TITLE_EN)->delete();
    }
}
