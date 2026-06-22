<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB7 (ADR-137 «Узлы») — GameSettings регена узла и цены отступления.
 *
 *  - `combat.nodes.hp_regen_per_minute` — РЕГЕН-ГЕЙТ: узел восстанавливает HP между боями
 *    (NodeHealthRegenHandler). Главный математический гейт «нужна группа» БЕЗ party-движка:
 *    соло-DPS < реген на верхнем тире → один не успевает, 3+ async-участника «Облавы» успевают.
 *    Реген НЕ идёт во время активного боя (updated_at-пауза + исключение active-encounter).
 *  - `combat.nodes.regen_pause_minutes` — грейс после последнего урона до старта регена (чтобы
 *    пауза между тапами одного игрока не «лечила» узел прямо в бою).
 *  - `combat.nodes.flee_tired_cost` — штраф выносливости за отступление.
 *  - `combat.nodes.engage_cooldown_minutes` — per-(игрок,узел) откат повторного захода после отхода
 *    (нельзя мгновенно перезайти и танковать заново).
 *
 * Дефолты — стартовые (ROADMAP §2-§3); калибровка — WB15. Constitutional rule (ADR-024):
 * rationale/effect/above/below NOT NULL. Seed game_settings → WipeManifest=KEEP. Идемпотентно.
 */
class Adr137NodesRegenRetreatGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'combat.nodes.hp_regen_per_minute', 'value_int' => 20,
                'rationale' => 'Реген-гейт узла (ADR-137 WB7): HP узла восстанавливается на столько в минуту между боями (NodeHealthRegenHandler). Главный математический гейт «нужна группа» без party-движка — соло-DPS на верхних тирах < реген (один чип-и-беги не успевает), 3+ async-участника «Облавы» суммарно успевают. Не идёт во время активного боя.',
                'effect' => 'Скорость восстановления HP alive-узла вне активного боя.',
                'above' => 'Выше: даже дуэт/трио не успевают за регеном → верхние узлы непроходимы (мёртвый хвост).',
                'below' => 'Ниже: соло-чип-и-беги добивает любой узел терпением → клан-стена обходится в одиночку.',
                'rmin' => 0, 'rmax' => 200, 'hmin' => 0, 'hmax' => 100000,
            ],
            [
                'setting_key' => 'combat.nodes.regen_pause_minutes', 'value_int' => 3,
                'rationale' => 'Грейс-пауза после последнего урона по узлу до старта регена. Чтобы естественная пауза между тапами одного игрока (раздумье/отвлёкся) НЕ лечила узел прямо посреди его боя.',
                'effect' => 'Сколько минут после последнего урона узел не регенерирует.',
                'above' => 'Выше: реген почти не включается (узел долго «помнит» урон) → гейт слабеет.',
                'below' => 'Ниже: реген стартует почти сразу → даже короткая пауза игрока в бою лечит узел.',
                'rmin' => 0, 'rmax' => 30, 'hmin' => 0, 'hmax' => 1440,
            ],
            [
                'setting_key' => 'combat.nodes.flee_tired_cost', 'value_int' => 10,
                'rationale' => 'Штраф выносливости (characters.tired) за отступление от узла. Отход — честный выход без потери опыта/статов (уважает П2-Казуала), но не бесплатный: связывает побег с дом-циклом восстановления выносливости.',
                'effect' => 'Сколько выносливости списывается при отступлении.',
                'above' => 'Выше: частые отходы быстро обнуляют выносливость (наказание за нерешительность).',
                'below' => 'Ниже: отход почти бесплатен — чип-и-беги тактика дешевле.',
                'rmin' => 0, 'rmax' => 50, 'hmin' => 0, 'hmax' => 100,
            ],
            [
                'setting_key' => 'combat.nodes.engage_cooldown_minutes', 'value_int' => 5,
                'rationale' => 'Откат повторного захода на ТОТ ЖЕ узел после отступления/гибели, per-(игрок,узел). Нельзя мгновенно перезайти и заново танковать (вместе с реген-гейтом не даёт соло «качать» узел бесконечными перезаходами).',
                'effect' => 'Через сколько минут после отхода игрок может снова напасть на этот же узел.',
                'above' => 'Выше: отход надолго запирает узел для игрока → фрустрация при честном тактическом отходе.',
                'below' => 'Ниже: перезаход почти мгновенный → реген-гейт частично обходится спамом заходов.',
                'rmin' => 0, 'rmax' => 60, 'hmin' => 0, 'hmax' => 1440,
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert([
                'setting_key' => $r['setting_key'], 'category' => 'combat', 'value_type' => 'int',
                'value_int' => $r['value_int'], 'default_value_text' => (string) $r['value_int'],
                'rationale_text' => $r['rationale'], 'effect_text' => $r['effect'],
                'above_effect_text' => $r['above'], 'below_effect_text' => $r['below'],
                'recommended_min' => $r['rmin'], 'recommended_max' => $r['rmax'],
                'hard_min' => $r['hmin'], 'hard_max' => $r['hmax'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'combat.nodes.hp_regen_per_minute', 'combat.nodes.regen_pause_minutes',
            'combat.nodes.flee_tired_cost', 'combat.nodes.engage_cooldown_minutes',
        ])->delete();
    }
}
