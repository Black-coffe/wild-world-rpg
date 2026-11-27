<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * H6.2 (h6-canon-1127, ask 8) — совет №47 «🏆 Эндгейм-крафт (T4)» больше не говорит «T4»: четвёртого уровня
 * крафта нет. По ADR-130 два верстака и три уровня крафта; фракционное оружие и броня — третий уровень,
 * «Профессиональный крафт». Гейт рецептов (`Config\CraftRecipes` :1860-1862 и дальше до :2107): 🛠
 * Профессиональный верстак + своя фракция + первый квест цепочки, то есть захват своего стратегического объекта.
 *
 * UPDATE строки по title_en='EndgameCraftT4' — ключ не меняется (его держат сид TipsBSeedNew и список его
 * down()). Идемпотентно: повтор пишет те же значения; строки нет — ничего не делает. down() возвращает
 * заголовок и текст, которые лежат на проде (SELECT 2026-11-27), копией: классы миграций не автозагружаются.
 * Категория `эндгейм` не меняется. Без чисел, тон Роби, markdown-safe (парные *). Схема не меняется —
 * WipeManifest не затронут (`game_tips` = KEEP).
 */
class FixEndgameCraftTipNoT4 extends Migration
{
    public const TITLE_EN = 'EndgameCraftT4';

    public const NEW_TITLE_RU = '🏆 Профессиональный крафт';

    public const NEW_CONTENT = '🏆 *Профессиональный крафт* — вершина развития: фракционное оружие и броня. '
        . 'Для него нужны три вещи: 🛠 Профессиональный верстак, своя фракция и пройденный квест захвата '
        . 'стратегического объекта твоей фракции. Готовь ресурсы заранее!';

    /** Заголовок на проде (SELECT 2026-11-27), дословно. */
    public const OLD_TITLE_RU = '🏆 Эндгейм-крафт (T4)';

    /** Текст на проде (SELECT 2026-11-27), дословно — он же сид TipsBSeedNew. */
    public const OLD_CONTENT = '🏆 *Топовый крафт (T4):* лучшие предметы требуют Профессиональный верстак + фракцию + '
        . 'пройденный квест. Это вершина развития — готовь ресурсы и репутацию заранее!';

    public function up(): void
    {
        $this->setTip(self::NEW_TITLE_RU, self::NEW_CONTENT);
    }

    public function down(): void
    {
        $this->setTip(self::OLD_TITLE_RU, self::OLD_CONTENT);
    }

    private function setTip(string $titleRu, string $content): void
    {
        $this->db->table('game_tips')
            ->where('title_en', self::TITLE_EN)
            ->update(['title_ru' => $titleRu, 'content' => $content, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
