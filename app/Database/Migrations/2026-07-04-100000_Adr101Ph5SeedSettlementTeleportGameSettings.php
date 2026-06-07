<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 5 — быстрое перемещение между открытыми поселениями (admin-tunable).
 * GameSettings `settlements.teleport.*` (category=world).
 *
 *  - settlements.teleport.enabled          — killswitch (DEFAULT false = dormant).
 *  - settlements.teleport.cost             — стоимость одного перехода в золоте.
 *  - settlements.teleport.cooldown_minutes — кулдаун между переходами (минуты).
 *
 * Читается {@see App\Services\Settlement\SettlementTeleportService}. Idempotent. Rich
 * rationale (ADR-024). Не создаёт таблиц/player-колонок (кулдаун — cache) → WipeManifest
 * не трогаем (game_settings уже KEEP).
 */
class Adr101Ph5SeedSettlementTeleportGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->boolRow('settlements.teleport.enabled', 'world', false, 'false', null, null, null, null,
            'Killswitch быстрого перемещения между открытыми поселениями (Фаза 5). DEFAULT false — dormant до балансовой валидации владельца (как вся механика поселений). Включение делает карту «связной» (Rust-like).',
            'SettlementTeleportService::enabled(). OFF → кнопка «🛰 Быстрое перемещение» скрыта в хабе, переходы запрещены (byte-identical мир).',
            'true (ON): игроки телепортируются между открытыми поселениями за золото — экономит время, но снижает ценность похода/маяков.',
            'false (OFF): механика выключена, только пеший путь и игроцкие маяки.');

        $rows[] = $this->intRow('settlements.teleport.cost', 'world', 500, '500', '0', '5000', '0', '1000000',
            'Стоимость одного перехода между поселениями в золоте. 500 — заметный, но необременительный sink для среднего игрока; дешевле длинного похода по времени, дороже бытовых трат.',
            'SettlementTeleportService::cost(). Списывается при подтверждённом переходе (одним апдейтом с move).',
            'Выше (1000+): переходы дороги → редкое использование, телепорт-сеть почти мёртвая, поход остаётся основным.',
            'Ниже (0-100): почти бесплатно → телепорт обесценивает поход/маяки и расстояния на карте.');

        $rows[] = $this->intRow('settlements.teleport.cooldown_minutes', 'world', 60, '60', '0', '240', '0', '10080',
            'Кулдаун между переходами в минутах. 60 — зеркалит окно бесплатного телепорт-маяка; не даёт спамить мгновенные прыжки, сохраняет вес перемещения.',
            'SettlementTeleportService::cooldownRemainingSeconds(). Взводится после успешного перехода (cache-based, на персонажа).',
            'Выше (120+): редкие переходы → телепорт как «аварийный», карта остаётся «большой».',
            'Ниже (0-10): частые прыжки → мир ощущается маленьким, обесценивание похода.');

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge([
                'value_int' => null, 'value_float' => null, 'value_bool' => null, 'value_string' => null,
                'recommended_min' => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $row));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function intRow(string $key, string $cat, int $int, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return [
            'setting_key' => $key, 'category' => $cat, 'value_type' => 'int', 'value_int' => $int,
            'default_value_text' => $defaultText, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $recMin, 'recommended_max' => $recMax, 'hard_min' => $hardMin, 'hard_max' => $hardMax,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function boolRow(string $key, string $cat, bool $bool, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return [
            'setting_key' => $key, 'category' => $cat, 'value_type' => 'bool', 'value_bool' => $bool ? 1 : 0,
            'default_value_text' => $defaultText, 'rationale_text' => $rationale, 'effect_text' => $effect,
            'above_effect_text' => $above, 'below_effect_text' => $below,
            'recommended_min' => $recMin, 'recommended_max' => $recMax, 'hard_min' => $hardMin, 'hard_max' => $hardMax,
        ];
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'settlements.teleport.enabled',
                'settlements.teleport.cost',
                'settlements.teleport.cooldown_minutes',
            ])
            ->delete();
    }
}
