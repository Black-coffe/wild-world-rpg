<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M5-ремонт по находке ревью: `world.move.danger_tired_surcharge` называл ключ «tired»,
 * а фактически надбавка ложится на ❤️ здоровье (`MoveCharacterToDirectionAction::computeStepCost()`,
 * `$healthCost += $settings['danger_health_surcharge']`). Правило проекта — admin-tunable параметр
 * называется тем, на что влияет, — переименовываем ключ на `world.move.danger_health_surcharge`.
 * Число (1.15) не меняется — это байт-идентичность (см. `SingleStepMoveCostTest`).
 *
 * Idempotent: если старый ключ уже переименован (или отсутствует), `up()` ничего не делает.
 * Второго ключа рядом со старым не создаём — старая строка переименовывается на месте (UPDATE),
 * не INSERT+DELETE, чтобы не терять `created_at`/id.
 */
class RenameDangerTiredSurchargeToHealthSetting extends Migration
{
    private const OLD_KEY = 'world.move.danger_tired_surcharge';
    private const NEW_KEY = 'world.move.danger_health_surcharge';

    public function up(): void
    {
        $old = $this->db->table('game_settings')->where('setting_key', self::OLD_KEY)->get()->getRowArray();
        if (empty($old)) {
            // Либо уже переименован, либо ещё не сеялся (SeedWorldMoveGameSettings не отработал) — идемпотентно молчим.
            return;
        }

        $new = $this->db->table('game_settings')->where('setting_key', self::NEW_KEY)->get()->getRowArray();
        if (! empty($new)) {
            // Новый ключ уже существует (повторный прогон) — просто убираем старый, второго источника правды не оставляем.
            $this->db->table('game_settings')->where('setting_key', self::OLD_KEY)->delete();

            return;
        }

        $this->db->table('game_settings')
            ->where('setting_key', self::OLD_KEY)
            ->update([
                'setting_key'        => self::NEW_KEY,
                'rationale_text'     => 'Надбавка к цене ❤️ здоровья шага, если целевая клетка опасного биома (`danger_level >= 8`). Значение перенесено 1:1 из hardcoded `+1.15` в `MoveCharacterToDirectionAction::handle()`. Ключ переименован с `danger_tired_surcharge` на `danger_health_surcharge` (M5-ремонт по находке ревью) — старое имя называло усталость, хотя надбавка всегда ложилась на здоровье; число не менялось (байт-идентичность, см. `SingleStepMoveCostTest`).',
                'effect_text'        => 'MoveCharacterToDirectionAction::computeStepCost() прибавляет это значение к здоровью, когда `$dangerBiome=true`.',
                'above_effect_text'  => 'Выше 1.15 — заход в опасный биом (danger_level>=8) заметно дороже по здоровью, игрок сильнее избегает такие клетки.',
                'below_effect_text'  => 'Ниже 1.15 — опасные биомы перестают ощутимо отличаться по цене от обычных, теряют часть risk/reward.',
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
    }

    public function down(): void
    {
        $new = $this->db->table('game_settings')->where('setting_key', self::NEW_KEY)->get()->getRowArray();
        if (empty($new)) {
            return;
        }

        $old = $this->db->table('game_settings')->where('setting_key', self::OLD_KEY)->get()->getRowArray();
        if (! empty($old)) {
            $this->db->table('game_settings')->where('setting_key', self::NEW_KEY)->delete();

            return;
        }

        $this->db->table('game_settings')
            ->where('setting_key', self::NEW_KEY)
            ->update([
                'setting_key'        => self::OLD_KEY,
                'rationale_text'     => 'Надбавка к цене ❤️ здоровья шага, если целевая клетка опасного биома (`danger_level >= 8`). Значение перенесено 1:1 из hardcoded `+1.15` в `MoveCharacterToDirectionAction::handle()`. Название ключа исторически про усталость (контракт `plan.md`), но фактически надбавка ложится на здоровье — так было в коде до выноса, менять семантику эта story не вправе (байт-идентичность).',
                'effect_text'        => 'MoveCharacterToDirectionAction::computeStepCost() прибавляет это значение к здоровью, когда `$dangerBiome=true`.',
                'above_effect_text'  => 'Выше 1.15 — заход в опасный биом (danger_level>=8) заметно дороже по здоровью, игрок сильнее избегает такие клетки.',
                'below_effect_text'  => 'Ниже 1.15 — опасные биомы перестают ощутимо отличаться по цене от обычных, теряют часть risk/reward.',
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
    }
}
