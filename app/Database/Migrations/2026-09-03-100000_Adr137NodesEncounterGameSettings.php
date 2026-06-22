<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB6 (ADR-137 «Узлы») — GameSettings многоходового боя с узлом (BossEncounterService).
 *
 * Темп боя (rounds_per_tap, max_rounds), боевые статы узла (dmg/armor-кривая для NodeLevelCurve),
 * множители действий (защита/спецприём — применяются ВНЕ формулы, детерминированно), стоимость/
 * откат спецприёма, флэт-фолбэк хила предмета, TTL для GC-крона зависших боёв.
 *
 * Дефолты — стартовые (ROADMAP §2-§3); финальная калибровка — WB15. Constitutional rule (ADR-024):
 * rationale/effect/above/below NOT NULL. Seed game_settings (НЕ createTable) → WipeManifest=KEEP.
 * Идемпотентно по setting_key.
 */
class Adr137NodesEncounterGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            $this->intRow('combat.nodes.rounds_per_tap', 3,
                'Сколько раундов проигрывается за один тап действия в бою с узлом. 3 = ощутимый прогресс за тап, но игрок остаётся у руля (выбор действия каждые 3 раунда).',
                'Темп многоходового боя: раундов на один тап кнопки.',
                'Выше: бой проскакивает крупными кусками, теряется влияние выбора игрока на ход боя.',
                'Ниже: слишком много тапов на один бой, нудно (особенно на HP-губках севера).',
                1, 10, 1, 50),
            $this->intRow('combat.nodes.max_rounds', 300,
                'Жёсткий потолок суммарных раундов одного боя (по всем тапам). Без него high-tier соло упёрся бы в вечную ничью (HP-губка + floor урона) вместо честного исхода. На потолке бой завершается отступлением.',
                'Максимум раундов боя; по достижении — принудительное завершение (timeout → отступление).',
                'Выше: верхние узлы можно «достукивать» дольше соло, размывая гейт «нужна группа».',
                'Ниже: даже законные затяжные бои обрываются раньше времени.',
                50, 1000, 10, 5000),
            $this->intRow('combat.nodes.dmg_base', 12,
                'Базовый урон узла (BattleCharacter.damageValue) на L1: damage = dmg_base + level·dmg_per_level. 12 держит южный L1-узел смертельно-некомфортным, но соло-проходимым.',
                'Аддитивная база боевого урона всех узлов (NodeLevelCurve::damageForLevel).',
                'Выше: даже L1-узлы бьют больно — юг перестаёт быть новичковым.',
                'Ниже: узлы не угрожают, бой теряет напряжение.',
                1, 60, 0, 500),
            $this->floatRow('combat.nodes.dmg_per_level', 0.5,
                'Прирост урона узла за уровень. 0.5 → L150 ≈ 87 базового (× clamp(levelDiff)·0.02, минус броня игрока). Разница уровней клампится combat.nodes.leveldiff_cap (WB1) → даже верхний узел не уаншотает.',
                'Наклон кривой урона узла по уровню.',
                'Выше: северные узлы быстро становятся непроходимы малой группой.',
                'Ниже: уровень почти не делает узел опаснее, эндгейм-узлы не кусаются.',
                0.0, 5.0, 0.0, 50.0),
            $this->intRow('combat.nodes.armor_base', 4,
                'Базовая броня узла на L1 (режет урон игрока на armor/(100+armor)). 4 ≈ −4% урона на L1 — почти незаметно для новичка.',
                'Аддитивная база брони узла (NodeLevelCurve::armorForLevel).',
                'Выше: даже низкие узлы вязкие, дольше соло-бой на юге.',
                'Ниже: броня не ощущается до высоких уровней.',
                0, 60, 0, 500),
            $this->floatRow('combat.nodes.armor_per_level', 0.4,
                'Прирост брони узла за уровень. 0.4 → L150 ≈ 64 брони (≈ −39% урона, до cap). Вместе с HP-кривой формирует «губку» верхних тиров, но floor урона (baseDamage·0.5) оставляет узел добиваемым терпением — реальный гейт «нужна группа» это реген WB7.',
                'Наклон кривой брони узла по уровню.',
                'Выше: верхние узлы почти неуязвимы соло.',
                'Ниже: броня не растёт, узлы пробиваются слишком легко.',
                0.0, 5.0, 0.0, 50.0),
            $this->intRow('combat.nodes.armor_cap', 110,
                'Жёсткий потолок брони узла. 110 = максимум ~52% снижения урона; floor урона игрока (baseDamage·0.5) её перекрывает (чистая броня-стена обходима терпением — by design).',
                'Верхний предел брони узла (NodeLevelCurve::armorForLevel).',
                'Выше: снижение урона приближается к floor, узлы становятся неоправданно вязкими.',
                'Ниже: верхние узлы теряют живучесть от брони.',
                10, 200, 0, 500),
            $this->floatRow('combat.nodes.defense_out_mult', 0.6,
                'Множитель ИСХОДЯЩЕГО урона игрока в раунды, когда он выбрал «🛡 Защита» (бьёт слабее, но и получает меньше). Применяется ВНЕ формулы DamageService (детерминированный флаг → mt_rand нетронут, fixture-fence цел).',
                'Урон игрока × этот множитель в защитном чанке.',
                'Выше (→1.0): защита почти не снижает свой урон — мало отличается от удара.',
                'Ниже: в защите игрок почти не наносит урон (чистая оборона).',
                0.0, 1.0, 0.0, 1.0),
            $this->floatRow('combat.nodes.defense_in_mult', 0.6,
                'Множитель ВХОДЯЩЕГО урона по игроку, когда он выбрал «🛡 Защита» (получает меньше). Применяется ВНЕ формулы (как WoodenWall) → детерминированно.',
                'Урон узла по игроку × этот множитель в защитном чанке.',
                'Выше (→1.0): защита почти не спасает от урона.',
                'Ниже: защита делает игрока почти неуязвимым на чанк (риск чит-тактики «вечная защита» — балансится reg/откатом).',
                0.0, 1.0, 0.0, 1.0),
            $this->floatRow('combat.nodes.special_damage_mult', 2.0,
                'Множитель урона игрока при «🎯 Спецприём» (мощный удар за выносливость, с откатом). 2.0 = двойной урон чанка.',
                'Урон игрока × этот множитель в спец-чанке.',
                'Выше: спецприём доминирует, обычный удар бессмыслен.',
                'Ниже: спецприём не стоит траты выносливости и отката.',
                1.0, 5.0, 1.0, 50.0),
            $this->intRow('combat.nodes.special_cooldown_rounds', 3,
                'Откат спецприёма в раундах (нельзя спамить мощный удар). 3 = доступен примерно раз в чанк.',
                'Минимум раундов между двумя спецприёмами.',
                'Выше: спецприём редкий, тактически ценный, но почти не влияет на ход боя.',
                'Ниже: спам спецприёма (если хватает выносливости) обесценивает другие действия.',
                0, 50, 0, 500),
            $this->intRow('combat.nodes.special_tired_cost', 20,
                'Стоимость спецприёма в выносливости (characters.tired, 0-100). 20 → ~5 спецприёмов на полной выносливости. Связывает бой с дом-циклом восстановления.',
                'Сколько выносливости списывается за один спецприём.',
                'Выше: спецприём дорогой, доступен редко.',
                'Ниже: спецприём почти бесплатен, теряется ресурсный выбор.',
                0, 100, 0, 100),
            $this->intRow('combat.nodes.item_heal', 40,
                'Флэт-фолбэк хила «🍖 Предмет», если у съеденного предмета нет настройки medical.<name>.heal_health. Хил = первый resource-SINK узлов (против их faucet-наград). Лечит HP игрока в бою, тратя 1 медикамент из инвентаря.',
                'Сколько HP восстанавливает предмет в бою, если у него нет своей heal-настройки.',
                'Выше: один предмет почти полностью лечит — бой обнуляется аптечками.',
                'Ниже: предмет почти бесполезен в бою.',
                0, 500, 0, 5000),
            $this->intRow('combat.nodes.encounter_ttl_minutes', 30,
                'Через сколько минут бездействия активный бой считается зависшим и гасится GC-кроном в «отступление» (игрок ушёл/закрыл чат). HP узла при этом остаётся (персистентен).',
                'TTL бездействия активного боя до авто-отступления (GC).',
                'Выше: зависшие бои держат узел «занятым» дольше (для со-опа WB8 не критично — HP общий).',
                'Ниже: законная пауза игрока между тапами может оборваться авто-отступлением.',
                1, 240, 1, 1440),
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function intRow(string $key, int $val, string $rationale, string $effect, string $above, string $below, int $rMin, int $rMax, int $hMin, int $hMax): array
    {
        return [
            'setting_key' => $key, 'category' => 'combat', 'value_type' => 'int', 'value_int' => $val,
            'default_value_text' => (string) $val, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $rMin, 'recommended_max' => $rMax, 'hard_min' => $hMin, 'hard_max' => $hMax,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function floatRow(string $key, float $val, string $rationale, string $effect, string $above, string $below, float $rMin, float $rMax, float $hMin, float $hMax): array
    {
        return [
            'setting_key' => $key, 'category' => 'combat', 'value_type' => 'float', 'value_float' => $val,
            'default_value_text' => (string) $val, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $rMin, 'recommended_max' => $rMax, 'hard_min' => $hMin, 'hard_max' => $hMax,
        ];
    }

    public function down(): void
    {
        $keys = [
            'combat.nodes.rounds_per_tap', 'combat.nodes.max_rounds',
            'combat.nodes.dmg_base', 'combat.nodes.dmg_per_level',
            'combat.nodes.armor_base', 'combat.nodes.armor_per_level', 'combat.nodes.armor_cap',
            'combat.nodes.defense_out_mult', 'combat.nodes.defense_in_mult',
            'combat.nodes.special_damage_mult', 'combat.nodes.special_cooldown_rounds', 'combat.nodes.special_tired_cost',
            'combat.nodes.item_heal', 'combat.nodes.encounter_ttl_minutes',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
