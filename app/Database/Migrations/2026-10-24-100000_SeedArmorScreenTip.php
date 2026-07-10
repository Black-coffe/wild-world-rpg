<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет про экран брони и одежды.
 *
 * Вердикт «нужен совет: ДА». Обоснование: сам по себе арт (11 картинок брони) совета НЕ
 * требует — картинка есть enhancement, а не механика (MEDIA-OFF, ADR-020). Но аудит
 * `game_tips` при вынесении вердикта показал: про экипировку нет НИ ОДНОГО совета
 * (`content LIKE '%брон%' OR '%экипиров%'` → 0 строк). При этом экран осмотра брони стал
 * находимым только что (v0.51.514), а до того был мёртвой веткой. Дыра в покрытии.
 *
 * Совет про навигацию и понятия, БЕЗ чисел баланса (анти-дрейф): не называем ни брони,
 * ни процентов сопротивлений — они живут в БД и разойдутся с текстом.
 *
 * Честность пути (UX-DISCOVERABILITY): экран заперт за постройкой «Арсенал»
 * (`GearArmorAction` → lock-button «🔒 Нужен Арсенал»). Совет об этом говорит прямо,
 * иначе он обещал бы путь, которого у новичка нет.
 *
 * Idempotent (по title_en). tip_type='персонаж' (валидное ENUM из 14). markdown-safe
 * (парные `*`). Media-off самодостаточен: весь смысл в тексте.
 */
class SeedArmorScreenTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'ArmorScreen';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '👕 *Броня — это не только цифра защиты.* '
            . 'Открыть: *🧑 Я* → *⚔️ Экип* → *👕 Броня / Одежда*. '
            . 'Нажми на любую вещь в списке — откроется её карточка: чего она стоит в бою, '
            . 'как влияет на скорость и скрытность, и кнопка *Одеть* или *Снять*. '
            . 'Одежда и броня хранятся в *Арсенале*, поэтому пока ты его не построил, '
            . 'экран покажет замок и подскажет дорогу к стройке. '
            . 'Голым на остров лучше не выходить: даже рваная рубаха держит удар лучше, чем ничего.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '👕 Броня: где смотреть и как надеть',
            'title_en'   => $titleEn,
            'tip_type'   => 'персонаж',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'ArmorScreen')->delete();
    }
}
