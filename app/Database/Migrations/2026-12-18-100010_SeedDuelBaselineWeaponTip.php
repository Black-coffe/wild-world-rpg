<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «На арене без оружия не останешься» (TIPS-COVERAGE, CLAUDE.md; duel-baseline-weapon, поправка ADR-071).
 *
 * С duel-baseline-weapon безоружный и слабо вооружённый на арене бьют базовым оружием, своё сильнее даёт перевес с
 * весом, уворот на арене выше — небольшой перевес больше не гарантирует победу. Соседние советы: «⚔️ PvP-дуэли»
 * (PvpDuels) — про ЛЕТАЛЬНЫЙ полевой бой («не лезь в драку безоружным»), «📜 Разбор боя» — где смотреть итог. Этот —
 * что на арене новичку есть с чем выйти. Не дубль; с PvpDuels не спорит (там поле, тут арена).
 *
 * Idempotent по title_en='ArenaBaselineWeapon'. Без чисел баланса (урон, уворот, HP — в админке), media-off
 * самодостаточен, markdown-safe (парные *), категория «бой» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedDuelBaselineWeaponTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'ArenaBaselineWeapon';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🤺 *На арене без оружия не останешься.* Пришёл с пустыми руками или со слабым стволом — бьёшь '
            . 'базовым оружием, так что новичку есть с чем выйти против ветерана. Своё оружие получше даёт перевес, но '
            . 'не гарантию: на арене чаще уворачиваются, и исход решает бой. Вызвать соперника — *⚙️ Ещё → 🏟 Арена*, '
            . 'на сайте — *⚔️ Бои*. Это спорт: ни здоровья, ни опыта не теряешь.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🤺 Арена: базовое оружие у каждого',
            'title_en'   => $titleEn,
            'tip_type'   => 'бой',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'ArenaBaselineWeapon')->delete();
    }
}
