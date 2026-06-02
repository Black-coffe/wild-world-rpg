<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 Фаза 3 — killswitch фракционных квестов (admin-tunable, ADR-024).
 *
 * `quests.faction_quests_enabled` (bool, default **false** dormant): отдельный гейт
 * ПОВЕРХ `quests.extended_enabled`. true — фракционные квесты (quests.faction_id 1-4)
 * видны/стартуются/прогрессят для игроков своей фракции; false — dormant (контент
 * засеян, но невидим/не прогрессит). Позволяет держать faction-контент выключенным,
 * пока общие расширенные квесты (Фазы 1-2) уже активны. category=world. Idempotent.
 */
class QuestFactionEnabledGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'quests.faction_quests_enabled';

        $exists = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'Killswitch фракционных квестов (ADR-088 Фаза 3): квесты с quests.faction_id (1 Милитари / 2 Партизаны / 3 Инженеры / 4 Фермеры) видны/стартуются/прогрессят только для игроков своей фракции. Отдельный гейт поверх quests.extended_enabled — чтобы держать faction-контент dormant, пока общие расширенные квесты (Фазы 1-2) уже активны. Ship dormant, активация после Tier-3.',
            'effect_text'        => 'QuestChainService::factionGateOk: true → AvailableQuests/GenericQuestStartAction показывают и стартуют фракционный квест только при совпадении фракции игрока; QuestObjectiveHandler прогрессит его. false → фракционные квесты исключены из UI и обработки (не-фракционные квесты не затронуты).',
            'above_effect_text'  => 'true (вкл): каждая из 4 фракций получает тематические квесты (Милитари — зачистки/арсенал, Партизаны — взрывчатка/засады, Инженеры — электроника/робомастерская, Фермеры — урожай/агрокомплекс) → фракционная идентичность и ретеншн.',
            'below_effect_text'  => 'false (выкл, текущий default): мгновенный dormant без редеплоя; общие расширенные квесты (extended_enabled) и цепочки не затронуты (0 регрессии).',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'quests.faction_quests_enabled')->delete();
    }
}
