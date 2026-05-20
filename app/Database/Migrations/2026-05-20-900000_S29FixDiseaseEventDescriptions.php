<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S29 (ROADMAP-CRAFT §9 Фаза 6) — content-pass: lying descriptions cleanup.
 *
 * Audit-first (2026-05-20, workflow `feedback_content_pass_workflow`): прогон
 * user-facing строк (crafted_items / events / buildings) vs реальные механики.
 * Чисто почти везде (consume-claims честны: EmergencyTransfusion dur=1 «одноразовый»,
 * SurgicalKit dur=3 «×3»; MeteorShelter/MeteorImpact уже точны после v0.51.139).
 *
 * Единственный документированный mismatch — **disease-события** (S19 finding):
 *   - «Эпидемия»: «Распространение болезни, влияющей на здоровье» — flavor, но
 *     умалчивает реальные рычаги.
 *   - «Жаркая лихорадка»: «Если заболел - сиди дома!» — врёт про *персистентное
 *     состояние «заболел»*, которого в коде НЕТ (есть только урон по тикам).
 *
 * Реальная механика (Config\WorldEvents Epidemic/Fever): effect_kind=damage_health,
 * state_modifier base_idle=0.06 vs biome_idle=0.5 (на базе слечь в ~8× реже),
 * protection_item='Antiseptic' (−50% урона). Fever: biome_type_specific (сырые
 * биомы → health, сухие → tired). Приводим описания к gold-standard «Метеоритного
 * удара» (тот единственный называет protection_item + base-safety).
 *
 * Idempotent: UPDATE только если ещё старый текст (WHERE description LIKE old-pattern).
 * Источника в коде/сидерах нет (события засеяны исторически) → правка только данных.
 */
class S29FixDiseaseEventDescriptions extends Migration
{
    /** @var array<int, array{name:string, old_like:string, new:string}> */
    private array $fixes = [
        [
            'name'     => 'Эпидемия',
            'old_like' => '%Распространение болезни, влияющей%',
            'new'      => 'Вспышка болезни подтачивает здоровье и силы выживших, пока длится. В открытых биомах слечь куда легче, чем на базе; Антисептик в инвентаре вдвое снижает урон. Переждать дома — разумно.',
        ],
        [
            'name'     => 'Жаркая лихорадка',
            'old_like' => '%Если заболел%',
            'new'      => 'Влажность и жара разносят лихорадку. Вне базы подхватить её куда проще: в сырых биомах она бьёт по здоровью, в сухих — по силам. Антисептик в инвентаре уменьшает урон вдвое. Если можешь — пережди дома.',
        ],
    ];

    public function up(): void
    {
        foreach ($this->fixes as $f) {
            $this->db->table('events')
                ->where('name', $f['name'])
                ->like('description', trim($f['old_like'], '%'))
                ->update(['description' => $f['new']]);
        }
    }

    public function down(): void
    {
        // Восстанавливаем исходные (lying) тексты — только если стоит наш новый.
        $rollback = [
            ['name' => 'Эпидемия',          'new_like' => '%подтачивает здоровье и силы%', 'old' => 'Распространение болезни, влияющей на здоровье персонажей.'],
            ['name' => 'Жаркая лихорадка',  'new_like' => '%Вне базы подхватить её куда проще%', 'old' => 'Влажность и тепло способствуют распространению болезней. Если заболел - сиди дома!'],
        ];
        foreach ($rollback as $r) {
            $this->db->table('events')
                ->where('name', $r['name'])
                ->like('description', trim($r['new_like'], '%'))
                ->update(['description' => $r['old']]);
        }
    }
}
