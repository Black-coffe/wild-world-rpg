<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V22 (ADR-054) — «Биомы решают»: GameSettings для biome-driven gather rebalance.
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 *
 * 11 ключей (category=resources): killswitch + offbiome_multiplier (scarce) +
 * 9 per-biome signature_multiplier (forest/deserts 10.0 legacy, остальные 3.0).
 */
class V22SeedBiomeGatherGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [];

        // ── killswitch ──────────────────────────────────────────────────
        $rows[] = [
            'setting_key'        => 'gather.biome.rebalance_enabled',
            'category'           => 'resources',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'default_value_text' => 'true',
            'rationale_text'     => 'Killswitch biome-driven добычи (ADR-054). true — у каждого из 9 биомов свои signature-ресурсы (буст) и scarce-ресурсы (дефицит), карта решает что где добывать. false — модификатор биома отключён, добыча идёт по чистой формуле rarity (откат к pre-V22 без редеплоя).',
            'effect_text'        => 'BiomeGatherProfileService::isEnabled. false → modifyResourcesByBiome возвращает добычу без изменений, UI-хинт «биом богат» скрыт.',
            'above_effect_text'  => 'true: биом-специализация активна — Лес=Древесина, Вулкан=Обсидиан/Сера, Пещеры=Кристаллы и т.д.',
            'below_effect_text'  => 'false: все биомы дают одинаково по rarity (карта перестаёт влиять на добычу — регресс к старой плоской модели для 6 биомов).',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        // ── offbiome (scarce) множитель ─────────────────────────────────
        $rows[] = [
            'setting_key'        => 'gather.biome.offbiome_multiplier',
            'category'           => 'resources',
            'value_type'         => 'float',
            'value_bool'         => null,
            'value_int'          => null,
            'value_float'        => 0.5,
            'value_string'       => null,
            'default_value_text' => '0.5',
            'rationale_text'     => 'Множитель «дефицитных» (scarce, off-theme) ресурсов биома. 0.5 = вдвое меньше обычного. Применяется только к ресурсам из scarce-списка биома (Config\GatherBiomeProfiles): Лес→Камни, Горы→Древесина/Мхи/Вода. 0.5 точно воспроизводит legacy ÷2 (Лес Камни, Горы Древесина/Мхи) и мягче прежней Горы Вода ÷4 (послабление, не nerf).',
            'effect_text'        => 'BiomeGatherProfileService::offbiomeMultiplier. Применяется в modifyResourcesByBiome к scarce-ресурсам биома (amount × это).',
            'above_effect_text'  => 'При 1.0 дефицит исчезает — off-theme ресурсы добываются как обычно, биомы менее различимы.',
            'below_effect_text'  => 'При 0.1 off-theme ресурсы почти не добываются в чужом биоме (жёстко, как старый Пустыни Вода ÷10) — может раздражать игроков, которым нужен «не тот» ресурс здесь.',
            'recommended_min'    => '0.25',
            'recommended_max'    => '1.0',
            'hard_min'           => '0.01',
            'hard_max'           => '1.0',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        // ── per-biome signature множители ───────────────────────────────
        // slug => [biome_ru, default, signature_примеры, legacy?]
        $biomes = [
            'forest'    => ['Леса', 10.0, 'Древесина, Смола, Мёд, Орехи', true],
            'mountains' => ['Горы', 3.0, 'Камни, Железная руда, Редкие руды', true],
            'tundra'    => ['Тундра', 3.0, 'Снег, Лёд, Арктические цветы, Масло тюленя', false],
            'rivers'    => ['Реки', 3.0, 'Рыба, Водоросли, Водные растения, Ил', false],
            'tropics'   => ['Тропические джунгли', 3.0, 'Фрукты, Лианы, Орхидеи, Тропические насекомые', false],
            'plains'    => ['Поля и равнины', 3.0, 'Зерновые, Текстильные культуры, Овощи', false],
            'caves'     => ['Пещеры', 3.0, 'Кристаллы, Драгоценные камни, Сталактиты', false],
            'volcanic'  => ['Вулканические территории', 3.0, 'Обсидиан, Сера, Лавовый камень, Базальт', false],
            'deserts'   => ['Пустыни', 10.0, 'Песок, Кактус, Алоэ, Пустынный агат', true],
        ];

        foreach ($biomes as $slug => [$ru, $default, $examples, $legacy]) {
            $legacyNote = $legacy
                ? " Сохраняет legacy-значение биома (pre-V22) — игроки тут уже привыкли к этому объёму."
                : " Новый буст (до V22 биом был плоским — без модификатора).";
            $rows[] = [
                'setting_key'        => "gather.biome.{$slug}.signature_multiplier",
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_bool'         => null,
                'value_int'          => null,
                'value_float'        => $default,
                'value_string'       => null,
                'default_value_text' => (string) $default,
                'rationale_text'     => "Множитель «фирменных» (signature) ресурсов биома «{$ru}»: {$examples}. {$default}× обычной добычи — это причина идти именно в этот биом за этими ресурсами." . $legacyNote,
                'effect_text'        => "BiomeGatherProfileService::signatureMultiplier('{$slug}'). Применяется в modifyResourcesByBiome к signature-ресурсам биома {$ru} (amount × это).",
                'above_effect_text'  => "Выше — биом «{$ru}» становится ещё богаче на свои ресурсы (риск переизбытка/инфляции этих ресурсов, как сейчас Лес с Древесиной).",
                'below_effect_text'  => "При 1.0 биом «{$ru}» теряет специализацию — нет смысла идти сюда за {$examples}, карта обедняется.",
                'recommended_min'    => '1.0',
                'recommended_max'    => '12.0',
                'hard_min'           => '1.0',
                'hard_max'           => '50.0',
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $keys = [
            'gather.biome.rebalance_enabled',
            'gather.biome.offbiome_multiplier',
            'gather.biome.forest.signature_multiplier',
            'gather.biome.mountains.signature_multiplier',
            'gather.biome.tundra.signature_multiplier',
            'gather.biome.rivers.signature_multiplier',
            'gather.biome.tropics.signature_multiplier',
            'gather.biome.plains.signature_multiplier',
            'gather.biome.caves.signature_multiplier',
            'gather.biome.volcanic.signature_multiplier',
            'gather.biome.deserts.signature_multiplier',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
