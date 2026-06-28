<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S9 (ROADMAP-RETENTION-10, ADR-147) — гейт ранней выживальческой атмосферы (NewbieAtmosphereService).
 *
 *   - enabled (killswitch, default OFF → dormant): maybeSendAtmosphere = no-op,
 *     MoveCharacterToDirectionAction byte-identical.
 *   - max_level — верхняя граница «новичка сессии-1» (кому доставляем).
 *   - rustle_chance — шанс шороха-выбора за ход (one-shot → суммарно ≤1 раз).
 *   - tired_prompt_threshold — порог выносливости для JIT-подсказки про воду.
 *
 * Категория world. Idempotent. game_settings = KEEP (WipeManifest не трогаем). 🔴 несмертельно по
 * конструкции (сервис не трогает health/tired/ресурсы) — числа здесь только про охват/частоту.
 */
class S9NewbieAtmosphereGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.events.newbie_atmosphere.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S9 ранней атмосферы. false (default) — DORMANT: новичку при ходе не приходят шорох-выбор и JIT-подсказка про воду, MoveCharacterToDirectionAction byte-identical. true — новичок (level ≤ max_level) в сессии-1 получает телеграфированную НЕСМЕРТЕЛЬНУЮ опасность (далёкая буря + шорох с выбором 🔍/🌿/🚶, исход — разный нарратив без урона/наград) и подсказку «найди воду» при низкой выносливости. Цель — «вау»-плотность первого часа (П1/П3 рано). Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'NewbieAtmosphereService::enabled гейтит весь on-move канал (шорох + JIT-вода) и экран выбора NewbieAtmosphereAction.',
                'above_effect_text'  => 'true — первый час ощущается живым и опасным (но безопасным по факту), у новичка появляется агентность → выше вовлечённость/«ещё разок».',
                'below_effect_text'  => 'false — первый час = tap/wait без атмосферы; текущее поведение.',
            ],
            [
                'setting_key'        => 'world.events.newbie_atmosphere.max_level',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'recommended_min'    => 3,
                'recommended_max'    => 9,
                'hard_min'           => 1,
                'hard_max'           => 25,
                'rationale_text'     => 'Верхняя граница «новичка сессии-1», которому доставляем атмосферу. 5 — сессия-1/2 (ниже стартовой стены); согласуется с tier_newbie_max_level=9, но уже (атмосфера именно про самое начало). Выше — захватит освоившихся, кому «вау» первого часа уже не нужен.',
                'effect_text'        => 'NewbieAtmosphereService::maxLevel — выше этого уровня on-move атмосфера не шлётся.',
                'above_effect_text'  => 'Выше — атмосфера дольше сопровождает игрока (но рискует надоесть тому, кто уже в потоке).',
                'below_effect_text'  => 'Ниже — только самые первые ходы; часть новичков «вау» не успеет получить, если поздно начали двигаться.',
            ],
            [
                'setting_key'        => 'world.events.newbie_atmosphere.rustle_chance',
                'value_type'         => 'float',
                'value_float'        => 0.18,
                'default_value_text' => '0.18',
                'recommended_min'    => 0.05,
                'recommended_max'    => 0.5,
                'hard_min'           => 0.0,
                'hard_max'           => 1.0,
                'rationale_text'     => 'Шанс шороха-выбора за один ход новичка. 0.18 — на горизонте ~29 ходов сессии-1 событие почти наверняка случится один раз (one-shot маркер не даёт повтора), но не на первом же ходу (не перегружает старт). Это атмосферный «вау», не источник дохода.',
                'effect_text'        => 'NewbieAtmosphereService::rustleChance — вероятность срабатывания one-shot шороха на ход.',
                'above_effect_text'  => 'Выше — шорох случается раньше/чаще доходит до быстро-уходящих, но может выпасть на первый ход (резко).',
                'below_effect_text'  => 'Ниже — мягче вход, но часть новичков уйдёт из сессии-1, так и не встретив шорох.',
            ],
            [
                'setting_key'        => 'world.events.newbie_atmosphere.tired_prompt_threshold',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'recommended_min'    => 10,
                'recommended_max'    => 40,
                'hard_min'           => 1,
                'hard_max'           => 99,
                'rationale_text'     => 'Порог выносливости (tired), при ≤ которого новичку один раз приходит JIT-подсказка «найди воду». 20 — заметно «на исходе», но ещё есть запас дойти до воды/базы (не паника постфактум). One-shot: учим механику один раз.',
                'effect_text'        => 'NewbieAtmosphereService::tiredThreshold — порог для one-shot подсказки про воду.',
                'above_effect_text'  => 'Выше — подсказка раньше (можно преждевременно, когда запас ещё большой).',
                'below_effect_text'  => 'Ниже — подсказка позже; новичок рискует «встать» от усталости, не поняв почему.',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.events.newbie_atmosphere.enabled',
            'world.events.newbie_atmosphere.max_level',
            'world.events.newbie_atmosphere.rustle_chance',
            'world.events.newbie_atmosphere.tired_prompt_threshold',
        ])->delete();
    }
}
