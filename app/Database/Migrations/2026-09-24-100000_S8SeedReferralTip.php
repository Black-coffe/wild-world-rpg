<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — совет «Позови выжившего»: учит, что друга можно
 * пригласить по личной ссылке и получить за это honor-титул.
 *
 * Дополняет ONBOARDING/GUIDE: проактивный напоминатель (TipService «Совет дня» + /tips). media-off
 * + markdown-safe (парные `*`). tip_type='общие' (валидный ENUM). Про навигацию/понятие, НЕ числа
 * баланса (порог уровня — тюнинг, в текст не зашиваем → анти-дрейф). Idempotent по title_en.
 * game_tips = KEEP.
 */
class S8SeedReferralTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'ReferralCallSurvivor';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '📣 *Позови выжившего.* В карточке *«Персонаж»* есть кнопка *«👥 Позови выжившего»*: '
            . 'там твоя личная ссылка-приглашение. Перешли её другу — он начнёт на острове как новый '
            . 'выживший. Когда он освоится и пробьёт первую стену уровней, ты получишь honor-титул '
            . '*«Зовущий»*. Это не даёт игрового преимущества — только знак, что ты вернул в мир ещё '
            . 'одного человека. Чем больше реальных людей рядом — тем живее остров.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '📣 Позови выжившего — пригласи друга',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'ReferralCallSurvivor')->delete();
    }
}
