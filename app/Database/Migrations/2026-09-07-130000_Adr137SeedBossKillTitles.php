<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB11 (ADR-137 «Узлы») — титулы «Убийца [босс]» (3 архетипа, отложены из WB9).
 *
 * WB9 отложил титул сюда: `titles` (ADR-112/E11) выдаются cron-poll'ом по source_type='level'|
 * 'achievement'; dynamic-per-boss титул туда не лёг. Решение: 3 СТАТИЧНЫХ титула по архетипу узла
 * (Мясник/Цербер/Патриарх) с НОВЫМ source_type='boss_kill' — TitleCheckCron такой тип ПРОПУСКАЕТ
 * (qualifyingCharacterIds возвращает [] для неизвестного типа), выдаёт ТОЛЬКО kill-путь напрямую
 * (`TitleService::awardByBossKill` по npc_name_en = source_ref, идемпотентно).
 *
 * Пара к анонсу WB11: анонс обезличен (без ника), а личный титул убийцы — приватная награда за килл.
 * Гейтится общим killswitch `titles.enabled` (выдача только когда система титулов активна).
 *
 * titles = KEEP (контент). Идемпотентно по title_key. source_ref = npc_name_en боссов ADR-106.
 */
class Adr137SeedBossKillTitles extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            $this->t('boss_kill_scar',      'Убийца Мясника',    'boss_scar_butcher',      '🔪', 190, 'Сломи узел, который держал Шрам, Мясник Пустоши.'),
            $this->t('boss_kill_cerberus',  'Убийца Цербера',    'boss_cerberus_prototype', '⚙️', 200, 'Сломи узел, который держал довоенный «Цербер».'),
            $this->t('boss_kill_patriarch', 'Убийца Патриарха',  'boss_pack_patriarch',     '🐺', 210, 'Сломи узел, который держал Патриарх Стаи.'),
        ];

        foreach ($rows as $r) {
            $exists = $this->db->table('titles')->where('title_key', $r['title_key'])->countAllResults();
            if ($exists === 0) {
                $r['created_at'] = $now;
                $r['updated_at'] = $now;
                $this->db->table('titles')->insert($r);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function t(string $key, string $name, string $npcNameEn, string $icon, int $sort, string $desc): array
    {
        return [
            'title_key'   => $key,
            'name'        => $name,
            'description' => $desc,
            'source_type' => 'boss_kill',  // выдаётся ТОЛЬКО kill-путём (cron такой тип пропускает)
            'source_ref'  => $npcNameEn,   // npc_name_en боссов ADR-106
            'icon'        => $icon,
            'sort_order'  => $sort,
            'enabled'     => 1,
        ];
    }

    public function down(): void
    {
        $this->db->table('titles')->whereIn('title_key', [
            'boss_kill_scar', 'boss_kill_cerberus', 'boss_kill_patriarch',
        ])->delete();
    }
}
