<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-150 ФИНАЛ — правда пути в совете `ReferralCallSurvivor` (UX-DISCOVERABILITY, tip-consistency).
 *
 * Совет обещал: «В карточке *«Персонаж»* есть кнопка *«👥 Позови выжившего»*». В финале реферал
 * переезжает в свой канонический дом — «⚙️ Ещё» (с карточки Перса кросс-групповые кнопки убраны).
 * Совет, обещающий несуществующий путь, — ровно тот баг, из-за которого появилось правило
 * UX-DISCOVERABILITY (инцидент Faction Project 2026-05-27). Чиним в той же задаче.
 *
 * Идемпотентно: UPDATE по `title_en`, текст переписан целиком (не зависит от прошлого содержимого).
 * markdown-safe (парные `*`), media-off (весь смысл в тексте).
 */
class Adr150FixReferralTipPath extends Migration
{
    private const TITLE_EN = 'ReferralCallSurvivor';

    private const NEW_CONTENT = '📣 *Позови выжившего.* Нажми *«⚙️ Ещё»* внизу экрана → '
        . '*«👥 Позови выжившего»*: там твоя личная ссылка-приглашение. Перешли её другу — он начнёт '
        . 'на острове как новый выживший. Когда он освоится и пробьёт первую стену уровней, ты получишь '
        . 'honor-титул *«Зовущий»*. Это не даёт игрового преимущества — только знак, что ты вернул в мир '
        . 'ещё одного человека. Чем больше реальных людей рядом — тем живее остров.';

    private const OLD_CONTENT = '📣 *Позови выжившего.* В карточке *«Персонаж»* есть кнопка '
        . '*«👥 Позови выжившего»*: там твоя личная ссылка-приглашение. Перешли её другу — он начнёт '
        . 'на острове как новый выживший. Когда он освоится и пробьёт первую стену уровней, ты получишь '
        . 'honor-титул *«Зовущий»*. Это не даёт игрового преимущества — только знак, что ты вернул в мир '
        . 'ещё одного человека. Чем больше реальных людей рядом — тем живее остров.';

    public function up(): void
    {
        $this->db->table('game_tips')
            ->where('title_en', self::TITLE_EN)
            ->update(['content' => self::NEW_CONTENT, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')
            ->where('title_en', self::TITLE_EN)
            ->update(['content' => self::OLD_CONTENT, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
