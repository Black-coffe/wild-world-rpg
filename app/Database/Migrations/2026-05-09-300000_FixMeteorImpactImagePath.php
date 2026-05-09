<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.130 — Asset wire-in для MeteorImpact event.
 *
 * Original migration AddMeteorImpactEvent (v0.51.127) hard-coded
 * `default_event_image.png` як placeholder, але `due_to_meteor_impact.png`
 * вже існував у seed assets. Цей migration робить UPDATE на існуючий row
 * без re-running parent migration.
 *
 * Idempotent: WHERE додатково перевіряє, що зараз default placeholder.
 * Ставить тематичний `due_to_meteor_impact.png`. Якщо row уже має
 * правильне значення — UPDATE no-op.
 */
class FixMeteorImpactImagePath extends Migration
{
    public function up(): void
    {
        $this->db->table('events')
            ->where('name_english', 'MeteorImpact')
            ->where('img_path', 'uploads/telegram/default_event_image.png')
            ->update(['img_path' => 'uploads/telegram/due_to_meteor_impact.png']);
    }

    public function down(): void
    {
        $this->db->table('events')
            ->where('name_english', 'MeteorImpact')
            ->where('img_path', 'uploads/telegram/due_to_meteor_impact.png')
            ->update(['img_path' => 'uploads/telegram/default_event_image.png']);
    }
}
