<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * V22 (ADR-054) — «Биомы решают»: data-shape карта biome_id → профиль добычи.
 *
 * НЕ баланс (ADR-024 исключение — это маппинг, как ImageRegistry/CROP_MAP): какие
 * ресурсы у биома «фирменные» (signature, ×signature_multiplier) и какие «дефицитные»
 * (scarce, ×offbiome_multiplier). Сами множители — в GameSettings (live-tunable),
 * читаются BiomeGatherProfileService.
 *
 * `slug` = ключ GameSettings (gather.biome.<slug>.signature_multiplier).
 *
 * RU-имена ресурсов выверены по доступности в биоме (resources.biome_id FIND_IN_SET,
 * прод 2026-05-25): scarce/signature перечисляют ТОЛЬКО реально добываемые в биоме
 * ресурсы, иначе модификатор был бы no-op. Имена стабильны (resources.name UNIQUE NOT NULL).
 *
 * Инвариант non-hostile: legacy-биомы (forest/mountains/deserts) сохраняют прежние
 * scarce-списки (Лес Камни, Горы Древесина/Мхи/Вода, Пустыни Вода). Новые 6 биомов —
 * scarce=[] (только бусты, никто не теряет).
 */
final class GatherBiomeProfiles extends BaseConfig
{
    /**
     * biome_id (таблица biomes) → профиль.
     *
     * @var array<int, array{slug:string, signature:list<string>, scarce:list<string>}>
     */
    public const PROFILES = [
        // 1 — Леса (legacy: Древесина ×10, Камни ÷2). 297 игроков.
        1 => [
            'slug'      => 'forest',
            'signature' => ['Древесина', 'Смола деревьев', 'Мед', 'Орехи', 'Кора деревьев'],
            'scarce'    => ['Камни'],
        ],
        // 2 — Горы (legacy: Камни ×3; Древесина/Мхи ÷2, Вода ÷4 → теперь ×0.5 мягче).
        2 => [
            'slug'      => 'mountains',
            'signature' => ['Камни', 'Железная руда', 'Редкие руды', 'Снежный баран'],
            'scarce'    => ['Древесина', 'Мхи', 'Вода'],
        ],
        // 3 — Тундра и ледяные пустоши.
        3 => [
            'slug'      => 'tundra',
            'signature' => ['Снег', 'Лед', 'Арктические цветы', 'Масло тюленя'],
            'scarce'    => [],
        ],
        // 4 — Реки.
        4 => [
            'slug'      => 'rivers',
            'signature' => ['Рыба', 'Водоросли', 'Водные растения', 'Улитки и моллюски', 'Ил', 'Береговая растительность'],
            'scarce'    => [],
        ],
        // 5 — Тропические джунгли.
        5 => [
            'slug'      => 'tropics',
            'signature' => ['Фрукты', 'Лианы', 'Цветы орхидей', 'Тропические насекомые'],
            'scarce'    => [],
        ],
        // 6 — Поля и равнины.
        6 => [
            'slug'      => 'plains',
            'signature' => ['Зерновые культуры', 'Текстильные культуры', 'Овощи'],
            'scarce'    => [],
        ],
        // 7 — Пещеры и подземелья.
        7 => [
            'slug'      => 'caves',
            'signature' => ['Кристаллы', 'Драгоценные камни', 'Сталактиты и сталагмиты', 'Подземные животные', 'Капельники'],
            'scarce'    => [],
        ],
        // 8 — Вулканические территории.
        8 => [
            'slug'      => 'volcanic',
            'signature' => ['Обсидиан', 'Сера', 'Лавовый камень', 'Вулканический пепел', 'Базальт'],
            'scarce'    => [],
        ],
        // 9 — Пустыни (legacy: Песок ×10). scarce=[] — Вода в биоме 9 НЕ добывается
        // (resources.biome_id Воды = 1-8, без 9), legacy «Вода ÷10» был no-op (anti-drift).
        9 => [
            'slug'      => 'deserts',
            'signature' => ['Песок', 'Кактус', 'Алоэ', 'Шёлк пауков-пустынников', 'Пустынный агат'],
            'scarce'    => [],
        ],
    ];

    /** Slug биома для ключа GameSettings; null если биом не в карте. */
    public static function slugFor(?int $biomeId): ?string
    {
        if ($biomeId === null) {
            return null;
        }
        return self::PROFILES[$biomeId]['slug'] ?? null;
    }

    /**
     * Signature-ресурсы биома (RU-имена). Пустой список если биом не в карте.
     *
     * @return list<string>
     */
    public static function signatureFor(?int $biomeId): array
    {
        if ($biomeId === null) {
            return [];
        }
        return self::PROFILES[$biomeId]['signature'] ?? [];
    }

    /**
     * Scarce-ресурсы биома (RU-имена). Пустой список если биом не в карте.
     *
     * @return list<string>
     */
    public static function scarceFor(?int $biomeId): array
    {
        if ($biomeId === null) {
            return [];
        }
        return self::PROFILES[$biomeId]['scarce'] ?? [];
    }
}
