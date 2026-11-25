<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-200 (спека sudden-death-1125) — пассивный урон не убивает: три ключа баланса здоровья (ADR-024).
 *
 * - `health.passive_floor` — пол, ниже которого мировые события и голод здоровье не опускают
 *   (`PassiveHealthFloor`, S1). Жёсткий минимум 1.0 — выше порога рулетки смерти 0.99.
 * - `health.event_gather_min` — ниже этого здоровья добыча в биоме активного события с уроном не начинается
 *   (`GatherAction`, S2).
 * - `health.passive_warn_threshold` — пересечение этого порога пассивным уроном вниз даёт одно предупреждение
 *   (`EventDispatcher`, S3).
 *
 * game_settings = KEEP (WipeManifest не трогаем). Идемпотентно по setting_key. Категория world — рядом с
 * `health.warn.*`.
 */
class SeedPassiveFloorSettings extends Migration
{
    /**
     * @var list<array{setting_key:string,category:string,value_type:string,value_float:float,default_value_text:string,recommended_min:string,recommended_max:string,hard_min:string,hard_max:string,rationale_text:string,effect_text:string,above_effect_text:string,below_effect_text:string}>
     */
    public const ROWS = [
        [
            'setting_key'        => 'health.passive_floor',
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => 1.0,
            'default_value_text' => '1.0',
            'recommended_min'    => '1.0',
            'recommended_max'    => '5.0',
            'hard_min'           => '1.0',
            'hard_max'           => '50.0',
            'rationale_text'     => 'Слово владельца 25.11 («A: пассивное не убивает»): мировые события и голод ранят, но не добивают. 1.0 — наименьший пол выше порога рулетки смерти (0.99): персонаж, которого били только события и голод, в рулетку не попадает, но остаётся при последнем здоровье — добыча прерывается, на базу надо идти. Тот же пол, что у яда.',
            'effect_text'        => 'Ниже этого значения здоровье не опускают удары мировых событий (`IntentApplier`) и суточный голод (`FoodAndWaterConsumptionHandler`). Если здоровье уже ниже (после похода или шага в опасный биом), они его не меняют и не поднимают. Поход, шаги и бой этим полом не ограничены.',
            'above_effect_text'  => 'При 10–20 события перестают быть угрозой: персонаж в эпицентре держится на заметном здоровье и может спокойно добывать дальше, а цена пола (прерванная добыча) срабатывает реже, чем игрок успевает испугаться.',
            'below_effect_text'  => 'Ниже 1.0 нельзя: пол попадает в рулетку смерти (health <= 0.99), и события снова убивают без предупреждения — ровно та жалоба, ради которой ключ заведён.',
        ],
        [
            'setting_key'        => 'health.event_gather_min',
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => 30.0,
            'default_value_text' => '30',
            'recommended_min'    => '15',
            'recommended_max'    => '60',
            'hard_min'           => '1',
            'hard_max'           => '100',
            'rationale_text'     => 'Цена пола (слово владельца «Прервать добычу в биоме»): с низким здоровьем в биоме активного события с уроном новая добыча не начинается. Событие бьёт 3–10 HP примерно каждую третью минуту — 30 HP хватает на короткую добычу, после которой персонаж ещё не на полу.',
            'effect_text'        => 'Порог в `GatherAction` (бот и /play): здоровье ниже — отказ «в биоме идёт событие, ранит; иди на базу / подлечись». В биомах без такого события добыча стартует как раньше.',
            'above_effect_text'  => 'При 60–80 в биом события почти не зайти добывать даже слегка раненым — событие превращается в запрет на биом, игроки сидят без дела часами.',
            'below_effect_text'  => 'При 5–10 добыча стартует почти с пола и тут же прерывается полом — игрок тратит запуск впустую и видит два отказа подряд вместо одного понятного.',
        ],
        [
            'setting_key'        => 'health.passive_warn_threshold',
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => 30.0,
            'default_value_text' => '30',
            'recommended_min'    => '15',
            'recommended_max'    => '50',
            'hard_min'           => '5',
            'hard_max'           => '90',
            'rationale_text'     => 'Игрок 24.11: «предпосылок не было» — первое предупреждение пришло в ту же минуту, что смерть. Одно сообщение при падении ниже 30 от пассивного урона: при 3–10 HP за удар у игрока остаётся несколько ударов (десятки минут), чтобы уйти на базу. Совпадает с порогом добычи — одно число, которое игрок запоминает.',
            'effect_text'        => 'Когда удар события или голода переводит здоровье через этот порог вниз — одно предупреждение «уходи на базу» сразу из пути урона (не из минутной рулетки); удар, перепрыгнувший несколько порогов, даёт одно сообщение. Учитывает отключение предупреждений игроком.',
            'above_effect_text'  => 'При 60–80 предупреждение приходит почти каждому, кто попал под событие, и перестаёт что-то значить — его начинают пропускать.',
            'below_effect_text'  => 'При 5–10 предупреждение приходит за один-два удара до пола — игрок не успевает дойти до базы, и прерванная добыча снова выглядит как внезапность.',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::ROWS as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', array_column(self::ROWS, 'setting_key'))
            ->delete();
    }
}
