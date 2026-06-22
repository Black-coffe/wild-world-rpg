<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB10 (ADR-137 «Узлы») — GameSettings анти-кемпа LOOT-LOCK.
 *
 * Рычаги AntiCampDwellHandler (крон `npc.anticamp-dwell`) и AntiCampService (kill-путь):
 *   - `world.nodes.anticamp.radius` — Chebyshev-радиус «зоны узла» (клетки): копим dwell, только пока
 *     перс стоит вплотную. Малый (2) — НЕ авторский R=10 (зона 441 клетка ловила бы легит-соседей).
 *   - `world.nodes.anticamp.dwell_hours` — порог НЕПРЕРЫВНОГО online-присутствия до loot-lock.
 *   - `world.nodes.anticamp.warn_minutes` — за сколько минут ДО лока шлём warn-пинг (one-shot per dwell).
 *   - `world.nodes.anticamp.active_window_minutes` — окно `last_seen`, в котором перс считается online
 *     (dwell копится только в нём; оффлайн НЕ копит — 🔴 «беззащитный оффлайн»).
 *
 * Дефолты — стартовые (ROADMAP §0.2 п8 / §3 / ADR-137); калибровка — WB15. Constitutional rule
 * (ADR-024): rationale/effect/above/below NOT NULL. Seed game_settings (НЕ createTable) → WipeManifest=KEEP.
 * Идемпотентно по setting_key.
 */
class Adr137NodesAnticampGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'world.nodes.anticamp.radius', 'value_int' => 2,
                'rationale' => 'Анти-кемп (ADR-137 WB10): Chebyshev-радиус «зоны узла» в клетках — dwell копится, только пока перс стоит ВПЛОТНУЮ к узлу. Малый радиус намеренный: авторский R=10 = зона 441 клетка ловила бы легит-соседей (false-positive) и не решал бы мотив. 2 = узел + кольцо клеток вокруг.',
                'effect' => 'Размер зоны, где засчитывается «торчание»: |Δx|≤R И |Δy|≤R от координат узла. Сброс — при первом тике вне радиуса.',
                'above' => 'Выше: зона раздувается → честные соседи/проходящие мимо ловятся в кемперы (false-positive).',
                'below' => 'Ниже (0): кемпер встаёт на соседнюю клетку и обходит лок; 0 = анти-кемп фактически выключен.',
                'rmin' => 1, 'rmax' => 4, 'hmin' => 0, 'hmax' => 20,
            ],
            [
                'setting_key' => 'world.nodes.anticamp.dwell_hours', 'value_int' => 12,
                'rationale' => 'Порог НЕПРЕРЫВНОГО online-присутствия у узла до loot-lock (ADR-137 WB10). Копится ТОЛЬКО пока перс online (last_seen в окне) — оффлайн не копит (🔴 беззащитный оффлайн). 12ч активной игры, припаркованной на одной точке = очевидный кемп. Превышение → kill даёт 0 трофеев + не killer для анонса; сброс при уходе из радиуса.',
                'effect' => 'Сколько online-часов «торчания» накопить до лока (аккумулятор dwell_minutes ≥ dwell_hours×60).',
                'above' => 'Выше: кемпинг почти не наказуем — монополист держит трофей точки сколько хочет.',
                'below' => 'Ниже: легит-игрок, надолго осевший у точки (база рядом/со-оп «Облава»), случайно ловит лок.',
                'rmin' => 4, 'rmax' => 48, 'hmin' => 1, 'hmax' => 720,
            ],
            [
                'setting_key' => 'world.nodes.anticamp.warn_minutes', 'value_int' => 30,
                'rationale' => 'За сколько минут ДО loot-lock прислать предупреждающий пинг от Роби (ADR-137 WB10). One-shot per текущий dwell: игрок узнаёт о надвигающемся локе и может отойти ДО потери трофея (честность, не молчаливый грифинг). Реюз last_warned_at.',
                'effect' => 'Окно перед локом, в котором отправляется warn-пинг (dwell_minutes ≥ dwell_hours×60 − warn_minutes).',
                'above' => 'Выше: предупреждение приходит рано — игрок может «качать» паузу у границы порога.',
                'below' => 'Ниже: пинг приходит впритык/после лока → игрок не успевает среагировать (фрустрация).',
                'rmin' => 5, 'rmax' => 120, 'hmin' => 0, 'hmax' => 1440,
            ],
            [
                'setting_key' => 'world.nodes.anticamp.active_window_minutes', 'value_int' => 30,
                'rationale' => 'Окно last_seen (ADR-108), в котором перс считается ONLINE для анти-кемпа (ADR-137 WB10). dwell копится только у online-персов в этом окне → оффлайн заморожен (не копит, не локается — 🔴 беззащитный оффлайн). Тот же паттерн, что OnboardingNudgeHandler.',
                'effect' => 'Насколько свежим должен быть last_seen, чтобы тик dwell засчитался.',
                'above' => 'Выше: «online» трактуется широко → перс, отошедший от устройства, продолжает копить dwell.',
                'below' => 'Ниже: даже активный игрок между действиями выпадает из окна → dwell почти не копится, лок недостижим.',
                'rmin' => 10, 'rmax' => 120, 'hmin' => 1, 'hmax' => 1440,
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert([
                'setting_key' => $r['setting_key'], 'category' => 'world', 'value_type' => 'int',
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
            'world.nodes.anticamp.radius', 'world.nodes.anticamp.dwell_hours',
            'world.nodes.anticamp.warn_minutes', 'world.nodes.anticamp.active_window_minutes',
        ])->delete();
    }
}
