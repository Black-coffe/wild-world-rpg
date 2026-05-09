<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.139 — Content-pass для MeteorShelter (community idea #2 follow-up).
 *
 * Original AddMeteorImpactEvent (v0.51.127) написала description, що бреше
 * гравцям: "Расходуется не сразу — конструкция переживёт несколько падений,
 * прежде чем рассыпется." — але F7 protection_item infrastructure
 * НЕ робить consume взагалі. Item лежить у інвентарі довічно.
 *
 * Заміняємо на чесний текст: захист активний автоматично, конструкція
 * не зношується. Якщо у майбутньому з'явиться deplete-on-impact mechanic —
 * відкотимо опис разом з code change.
 *
 * Idempotent: WHERE перевіряє стару version description. Якщо row вже має
 * нову — UPDATE no-op. Safe для повторних запусків.
 *
 * Inline edit базової міграції зроблено окремо у parent file для
 * fresh installs (без необхідності rollback цієї міграції).
 */
class FixMeteorShelterDescription extends Migration
{
    private const OLD_DESCRIPTION = 'Переносное укрытие из камня и металла. При активном Метеоритном ударе уменьшает потерю ресурсов на 50%. Расходуется не сразу — конструкция переживёт несколько падений, прежде чем рассыпется.';

    private const NEW_DESCRIPTION = 'Переносное укрытие из камня и металла. При активном Метеоритном ударе автоматически уменьшает потерю ресурсов на 50%. Достаточно одного укрытия в инвентаре — конструкция не изнашивается и работает столько раз, сколько грянет удар.';

    public function up(): void
    {
        $this->db->table('crafted_items')
            ->where('name_eng', 'MeteorShelter')
            ->where('description', self::OLD_DESCRIPTION)
            ->update(['description' => self::NEW_DESCRIPTION]);
    }

    public function down(): void
    {
        $this->db->table('crafted_items')
            ->where('name_eng', 'MeteorShelter')
            ->where('description', self::NEW_DESCRIPTION)
            ->update(['description' => self::OLD_DESCRIPTION]);
    }
}
