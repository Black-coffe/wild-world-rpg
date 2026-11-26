<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * HONEST-OFF (honest-off-1127) — совет №143 «💬 Общий чат сообщества» больше не обещает, что в чате
 * «иногда отвечает и сам Роби»: `community.enabled` выключен, на проде `community_messages` = 0.
 * Остаётся то, что верно: чат есть, там помогают живые выжившие, подробности — `/guide` → «💬 Общий чат».
 * Когда включат `community.enabled` (пункт COMM), текст возвращает спека включения.
 *
 * UPDATE строки по title_en='Adr176CommunityChatExists' — второго совета нет. Идемпотентно (повтор пишет
 * тот же текст; строки нет — ничего не делает). down() возвращает текст сида Adr176SeedCommunityChatTip —
 * копией (`OLD_CONTENT`): классы миграций не автозагружаются, ссылка на соседнюю миграцию уронила бы откат.
 * Категория не меняется. Без чисел, тон Роби, markdown-safe (парные *). Схема не меняется — WipeManifest
 * не затронут (`game_tips` = KEEP).
 */
class CommunityChatTipWithoutRobiAnswers extends Migration
{
    public const TITLE_EN = 'Adr176CommunityChatExists';

    public const NEW_CONTENT = '💬 Знал, что у сообщества есть общий чат? Там живые выжившие помогают друг '
        . 'другу. Подробнее (где искать, чего ждать и куда с личным вопросом) — команда */guide* → раздел '
        . '*«💬 Общий чат»*.';

    /** Текст Adr176SeedCommunityChatTip, дословно (сверяет тест). */
    public const OLD_CONTENT = '💬 Знал, что у сообщества есть общий чат? Там живые выжившие помогают друг '
        . 'другу — а если вопрос повиснет без ответа, иногда отвечает и сам Роби. Подробнее '
        . '(где искать, чего ждать и куда с личным вопросом) — команда */guide* → раздел '
        . '*«💬 Общий чат»*.';

    public function up(): void
    {
        $this->setContent(self::NEW_CONTENT);
    }

    public function down(): void
    {
        $this->setContent(self::OLD_CONTENT);
    }

    private function setContent(string $content): void
    {
        $this->db->table('game_tips')
            ->where('title_en', self::TITLE_EN)
            ->update(['content' => $content, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
