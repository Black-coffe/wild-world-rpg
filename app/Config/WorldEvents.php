<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * F7.1 — декларативний registry усіх 24 world events (game-доменних подій).
 *
 * Назва `WorldEvents` (а не `Events`) тому що `Config\Events` зайнятий
 * фреймворком CodeIgniter для системних event-listeners (`pre_system` тощо).
 *
 * НЕ ENABLED у v0.27.0 — це паралельний source-of-truth для перевірки
 * через WorldEventsConfigTest. Реальне dispatch'ування з'явиться у F7.3
 * (EventTickHandler), коли effect-strategy classes будуть готові (F7.2).
 *
 * Замінює (поетапно, через F7.10 cleanup):
 *   - 19 hand-rolled handler'ів у app/TaskHandlers/Events/ (~5389 LOC)
 *   - hardcoded probability-gates у кожному handler'і
 *   - hardcoded `eventModel->where('name_english', X)->first()` lookup'и
 *
 * Структура запису:
 *   - 'effect_kind'         : один з 10 enum'ів — який клас Effect застосовувати
 *   - 'effect_params'       : kind-specific параметри
 *   - 'duration_minutes'    : цільова дюрація (замінює DB events.duration після
 *                             F7.10 migration; зараз DB має значно більші)
 *   - 'frequency_weight'    : вага в weighted random_choice (F7.9 семантика;
 *                             замінює DB.frequency_per_week)
 *   - 'tick_chance'         : float 0..1 — ймовірність dispatch'а на одному tick'у.
 *                             Дзеркало legacy `mt_rand(1,100) > X` гейтів усередині
 *                             handler'ів (Hurricane=0.35, Epidemic=0.10, Fever=1.0).
 *                             Дисспетчер F7.3 гейтить compute() по цьому полю.
 *   - 'protection_item'     : keyword crafted_item, який застосовує -50% damage
 *                             або +50% buff коли є в інвентарі (F7.7)
 *   - 'notification_kind'   : 'lifecycle' (start+end summary) | 'silent' | 'critical'
 *
 * Effect kinds enum (10):
 *   - damage_health        : зменшення health/tired (Hurricane, Snowfall, Volcanic, ...)
 *   - damage_resources     : відсоткова втрата stack ресурсів (MeteorRain rework)
 *   - heal                 : відновлення health/tired (GeothermalFountains)
 *   - attribute_boost      : +до stat або gold (Starfall, NorthernLights)
 *   - reveal_cells         : відкриває нові ячейки на мапі (MountainEcho)
 *   - gold_grant           : додає gold за формулою (GoldMine)
 *   - rare_resource_grant  : додає rare resource (DungeonOpening, BerryBoom, ...)
 *   - task_extend          : подовжує end_time активних задач (MirageOases, PolarNight)
 *   - gather_debuff        : знижує gather rate / збільшує водоспоживання (Dryness, ...)
 *   - noop                 : без механічного ефекту (резерв для майбутніх thematic подій)
 *
 * State modifier (поширений):
 *   ['base_idle' => 0, 'biome_idle' => 0.7, 'biome_active' => 1.0]
 *   - base_idle    : гравець на базі, не Gather/Explore  → захищений (0%)
 *   - biome_idle   : гравець не на базі, не Gather/Explore → частковий ефект (~70%)
 *   - biome_active : гравець у Gather/Explore             → повний ефект (100%)
 *
 * Див. mmorpg-vault/lore/refactor/F7-Audit.md
 */
class WorldEvents extends BaseConfig
{
    /**
     * Перелік валідних effect_kind enum'ів. Використовується WorldEventsConfigTest.
     */
    public const VALID_EFFECT_KINDS = [
        'damage_health',
        'damage_resources',
        'heal',
        'attribute_boost',
        'reveal_cells',
        'gold_grant',
        'rare_resource_grant',
        'task_extend',
        'gather_debuff',
        'noop',
    ];

    /**
     * Перелік валідних notification_kind.
     */
    public const VALID_NOTIFICATION_KINDS = [
        'lifecycle',  // start + end summary (default)
        'silent',     // зовсім без нотіфікацій (фоновий ефект)
        'critical',   // тільки якщо magnitude перевищує threshold
    ];

    /**
     * Стандартний state_modifier для damage-event'ів.
     */
    private const DEFAULT_DAMAGE_STATE_MODIFIER = [
        'base_idle'    => 0.0,
        'biome_idle'   => 0.7,
        'biome_active' => 1.0,
    ];

    /**
     * @var array<string, array{
     *     effect_kind: string,
     *     effect_params: array<string, mixed>,
     *     duration_minutes: int,
     *     frequency_weight: int,
     *     protection_item: ?string,
     *     notification_kind: string,
     * }>
     *
     * Ключ — `events.name_english` з БД (точне співпадіння). Поки що ключ
     * залишається сполучною ланкою; у F7.10 додамо UUID-стабільний ключ.
     */
    public array $events = [
        // ============================================================
        // 🌪 Damage-events (10 шт): погодні катаклізми, біом-специфічні
        // ============================================================

        'Hurricane' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'     => 'health',
                'state_modifier'    => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                // batch 5 balance review: было effect_value(65)×level×biome×random ≈ до ~96 HP/тик
                // (≈instakill для low-level — как баг «Эпидемии»). Теперь явный диапазон ≤10% max HP/тик
                // (см. WorldEventsDamageBoundsTest). Legacy-модификаторы (level/biome/random) убраны.
                'health_loss_range' => [2, 8],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.35,  // legacy HurricaneHandler 35% gate
            'protection_item'   => 'Bandage',
            'notification_kind' => 'lifecycle',
        ],

        'NightAttacks' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'        => 'both',  // health AND tired
                'state_modifier'       => [
                    'base_idle'    => 0.0,
                    'biome_idle'   => 0.5,
                    'biome_active' => 1.0,
                ],
                'time_window'          => ['20:00', '05:00'],  // лише в нічні години
                'sleeping_player_skip' => 12,                  // skip якщо last_seen > 12h
                // batch 5 balance review: было effect_value(50)×random ≈ до ~65 HP + ~65 вынос./тик. Теперь ≤10%/тик.
                'health_loss_range'    => [1, 6],
                'tired_loss_range'     => [1, 6],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.10,  // legacy NightAttacksHandler 10% gate
            'protection_item'   => null,  // F7.7: будь-яка зброя в інвентарі
            'notification_kind' => 'lifecycle',
        ],

        'MeteorRain' => [
            'effect_kind'   => 'damage_resources',
            'effect_params' => [
                'percent_per_event' => 15,  // -15% від stack ресурсів за подію
                'state_modifier'    => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'min_stack'         => 1,   // нижче 1 не падаємо
                'one_shot_at_end'   => true,
            ],
            'duration_minutes'  => 30,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // MeteorRain — apply on tick (rework з legacy random-cells)
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // v0.51.127 (community idea #2) — single-impact паралельно continuous MeteorRain.
        // Single-shot semantics: duration_minutes=1 + tick_chance=1.0 →
        // EventTickHandler runs every minute → exactly 1 tick → exactly 1 apply,
        // далі event переходить у completed і EventCloseHandler шле end-summary.
        'MeteorImpact' => [
            'effect_kind'   => 'damage_resources',
            'effect_params' => [
                'percent_per_event' => 40,  // devastating one-shot (vs MeteorRain 15% continuous)
                'state_modifier'    => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'min_stack'         => 1,
            ],
            'duration_minutes'  => 1,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,
            'protection_item'   => 'MeteorShelter',  // -50% loss якщо є у інвентарі (F7.7)
            'notification_kind' => 'lifecycle',
        ],

        'Epidemic' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'    => 'both',
                'state_modifier'   => [
                    'base_idle'    => 0.06,  // 6% chance заразитись на базі
                    'biome_idle'   => 0.5,
                    'biome_active' => 0.9,
                ],
                'two_stage_chance' => 0.5,   // первинний 50%-фільтр перед state_modifier
                'health_loss_range'=> [1, 5],
                'tired_loss_range' => [1, 3],
                'sample_percent'   => 1,     // обробляємо 1% characters/tick (як зараз)
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.10,  // legacy EpidemicHandler 10% gate
            'protection_item'   => 'Antiseptic',
            'notification_kind' => 'lifecycle',
        ],

        'FlashForestFire' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'     => 'both',
                'state_modifier'    => [
                    'base_idle'    => 0.0,
                    'biome_idle'   => 0.5,
                    'biome_active' => 0.75,  // 75% chance hit при gather
                ],
                // batch 5 balance review: было effect_value(78)×level×biome×random ≈ до ~87 HP/тик. Теперь ≤10%/тик; вынос. меньше — пожар бьёт по здоровью.
                'health_loss_range' => [2, 8],
                'tired_loss_range'  => [1, 4],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.20,  // legacy FlashForestFireHandler 20% gate
            'protection_item'   => 'WoodMaterials',  // символічна вогнезахисна обробка
            'notification_kind' => 'lifecycle',
        ],

        'Snowfall' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'  => 'random_h_or_t',
                'state_modifier' => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                // batch 5 balance review: было [1, 90] (!) — почти весь health-бар одним тиком. Теперь ≤10% max HP/тик.
                'damage_range'   => [1, 9],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy SnowFallHandler 30% gate
            'required_season'   => 'winter',  // V4 (ADR-032): снегопад только зимой
            'protection_item'   => 'Bandage',
            'notification_kind' => 'lifecycle',
        ],

        'SpringFlood' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'     => 'health',
                'state_modifier'    => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                // batch 5 balance review: было effect_value(75)×level×random ≈ до ~111 HP/тик. Теперь ≤10%/тик.
                'health_loss_range' => [2, 8],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy SpringFloodHandler 30% gate
            'required_season'   => 'spring',  // V4 (ADR-032): половодье только весной
            'protection_item'   => 'Bandage',
            'notification_kind' => 'lifecycle',
        ],

        'Tremor' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'     => 'health',
                'state_modifier'    => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                // batch 5 balance review: было effect_value(95)×level×random ≈ до ~141 HP/тик. Теперь ≤10%/тик (без protection_item — верхняя граница чуть выше).
                'health_loss_range' => [3, 9],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy TremorHandler 30% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // ⚠️ name_english в БД = 'volcanic_eruption' (snake_case). НЕ міняємо ключ
        // щоб lookup працював. У F7.10 переімінуємо в 'VolcanicEruption' разом з
        // міграцією іменування у БД.
        'volcanic_eruption' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'     => 'health',
                'state_modifier'    => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                // batch 5 balance review: было effect_value(96) × base_damage_mul(10) × level×biome×random ≈ до ~1400 HP/тик
                // (!! — жёсткий инстакилл кого угодно, аналог бага «Эпидемии», только хуже). `base_damage_mul` УБРАН.
                // Теперь ≤10% max HP/тик — самое опасное биом-событие, без protection_item.
                'health_loss_range' => [4, 10],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy VolcanicEruptionHandler 30% gate
            'protection_item'   => null,  // F7.7: можливо HeatResistantGear
            'notification_kind' => 'lifecycle',
        ],

        'Sandstorm' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'    => 'tired',
                'state_modifier'   => [
                    'base_idle'    => 0.0,
                    'biome_idle'   => 0.8,
                    'biome_active' => 1.0,
                ],
                // batch 5 balance review: было effect_value(default 30)×level×biome×random ≈ до ~44 вынос./тик + drain атрибута.
                // Теперь ≤10% max/тик по выносливости. attr_drain убран — нишевый flavor, безопаснее без него (effect_value-путь обходил bound).
                'tired_loss_range' => [2, 8],
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.25,  // legacy SandStormHandler 25% gate
            'protection_item'   => null,  // F7.7: SandGoggles
            'notification_kind' => 'lifecycle',
        ],

        // ============================================================
        // 🦠 Gather-debuff events (3 шт): впливають на видобуток
        // ============================================================

        'Dryness' => [
            'effect_kind'   => 'gather_debuff',
            'effect_params' => [
                'gather_rate_modifier'         => -0.30,  // -30% gather
                'water_consumption_multiplier' => 2.0,
                'biome_type_filter'            => null,   // global
            ],
            'duration_minutes'  => 90,  // було 860 (14г) — кардинально скорочено
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // gather_debuff — це state, не tick
            'required_season'   => 'summer',  // V4 (ADR-032): засуха только летом
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'Fever' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'        => 'biome_type_specific',
                'state_modifier'       => [
                    'base_idle'    => 0.06,
                    'biome_idle'   => 0.5,
                    'biome_active' => 0.9,
                ],
                'two_stage_chance'     => 0.5,
                'biome_type_specific'  => [
                    // batch 5 balance review: было wet=[5,10] (на верхней границе bound + tick_chance=1.0 → каждый тик).
                    // Ужато до [3,7] чтобы оставить запас под ≤10%/тик. (biome_type_specific-урон не множится на state_coef.)
                    'wet'     => ['health' => [3, 7]],
                    'dry'     => ['tired'  => [3, 7]],
                    'default' => ['health' => [1, 3], 'tired' => [1, 3]],
                ],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // legacy FeverHandler не мав top-level gate (тільки two_stage внутрі)
            'protection_item'   => 'Antiseptic',
            'notification_kind' => 'lifecycle',
        ],

        'LocustExodus' => [
            'effect_kind'   => 'gather_debuff',
            'effect_params' => [
                'gather_rate_modifier'         => -0.50,
                'water_consumption_multiplier' => 1.0,
                'food_only'                    => true,
                'biome_type_filter'            => null,  // біом 6 (Поля) задається через DB.biome_ids
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // gather_debuff — це state, не tick
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // ============================================================
        // ✨ Buff events (5 шт): атрибутні бонуси
        // ============================================================

        'NorthernLights' => [
            'effect_kind'   => 'attribute_boost',
            'effect_params' => [
                'attribute_pool'    => ['experience', 'health', 'strength', 'agility', 'intellect', 'tired', 'gold'],
                'small_boost_range' => [1.00, 1.11],   // для exp/str/agi/int
                'large_boost_range' => [1, 100],        // для health/tired/gold
                'cap_h_t_g'         => 100,
                'one_shot_at_start' => true,            // 1 boost / гравець / подія (не tick)
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.18,  // legacy NorthernLightsHandler 18% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'Starfall' => [
            'effect_kind'   => 'attribute_boost',
            'effect_params' => [
                'attribute_pool'    => ['experience', 'health', 'strength', 'agility', 'intellect', 'tired', 'gold'],
                'small_boost_range' => [1.00, 1.11],
                'large_boost_range' => [1, 100],
                'cap_h_t_g'         => 100,
                'one_shot_at_start' => true,
            ],
            'duration_minutes'  => 35,    // швидка подія (як зараз)
            'frequency_weight'  => 5,     // має бути найчастішою (лор: «сотні падаючих зірок»)
            'tick_chance'       => 0.20,  // legacy ShootingStarHandler 20% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'GoldMine' => [
            'effect_kind'   => 'gold_grant',
            'effect_params' => [
                'base_range'        => [1000, 150000],
                'stat_divisor'      => 3000.0,           // (exp+agi+int)/divisor
                'effect_value_mul'  => true,             // × event.effect_value/100
                'cap_formula'       => 'level_50',       // min(gold, max(500, level × 50))
                'requires_state'    => 'gather',
                'chance_per_tick'   => 0.06,             // 6% (1:1 з GoldVeinHandler)
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // GoldGrant.compute робить власний chance_per_tick=0.06
            'protection_item'   => null,
            'notification_kind' => 'critical',  // золото > 5000 = override throttle
        ],

        'GeothermalFountains' => [
            'effect_kind'   => 'heal',
            'effect_params' => [
                'heal_target'  => 'random_h_or_t',
                'amount_range' => [1, 10],
                'cap'          => 100,
                'one_shot_at_start' => true,
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 2,
            'tick_chance'       => 0.15,  // legacy GeothermalSpringsHandler 15% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'MountainEcho' => [
            'effect_kind'   => 'reveal_cells',
            'effect_params' => [
                'level_table' => [
                    10  => 0.1,
                    20  => 1,
                    50  => 5,
                    100 => 10,
                    200 => 20,
                    300 => 30,
                    500 => 50,
                    999 => 99,
                ],
                'on_base_modifier'  => 0.25,  // на базі ефект ослаблений (як зараз)
                'one_shot_at_start' => true,
            ],
            'duration_minutes'  => 45,
            'frequency_weight'  => 3,
            'tick_chance'       => 0.10,  // legacy MountainEchoHandler 10% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // ============================================================
        // 🎁 Rare-resource events (5 шт): дають унікальний loot
        // ============================================================

        'BerryBoom' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'berry',
                'rarity_filter'      => null,
                'amount_range'       => [2, 5],
                'chance_per_tick'    => 0.20,            // 20% за tick (часта подія)
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,            // біом 1 (Лісостеп) задається DB.biome_ids
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 2,
            'tick_chance'       => 1.0,   // RareResource.compute робить власний chance_per_tick=0.20
            'required_season'   => 'autumn',  // V4 (ADR-032): ягодный бум — осенняя жатва
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'FishStock' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'fish',
                'rarity_filter'      => null,
                'amount_range'       => [1, 3],
                'chance_per_tick'    => 0.15,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,            // біом 4 (Ріки) через DB
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 2,
            'tick_chance'       => 1.0,   // RareResource.compute робить власний chance_per_tick=0.15
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'ExoticFlowering' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'rare_flower',
                'rarity_filter'      => 1,               // тільки rarity=1
                'amount_range'       => [1, 2],
                'chance_per_tick'    => 0.10,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,            // біом 5 (Тропіки) через DB
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 2,
            'tick_chance'       => 1.0,   // RareResource.compute робить власний chance_per_tick=0.10
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'RevealingHiddenCameras' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'RevealingHiddenCameras',  // 1:1 з handler
                'rarity_filter'      => 1,
                'amount_range'       => [1, 3],
                'chance_per_tick'    => 0.10,
                'requires_state'     => 'gather',
                'biome_type_filter'  => 'cave',
            ],
            'duration_minutes'  => 56,
            'frequency_weight'  => 2,
            'tick_chance'       => 1.0,   // RareResource.compute робить власний chance_per_tick=0.10
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // S10 (v0.51.192) — 4 биом-специфичных rare-drop события для T3-крафта.
        // Все 4 параметра (chance_per_tick / amount_range) live-tunable через
        // GameSettings keys `rare_event.<name_english>.{chance_per_tick|amount_min|amount_max}`.
        // RareResourceGrantEffect::compute() читает GameSettings override fallback на effect_params.

        'VolcanicFuelCache' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'fuel_rods',
                'rarity_filter'      => 1,
                'amount_range'       => [1, 2],
                'chance_per_tick'    => 0.08,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,  // біом через DB.biome_ids=["8"]
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'PreCollapseVaultOpening' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'pre_collapse_electronics',
                'rarity_filter'      => 1,
                'amount_range'       => [1, 2],
                'chance_per_tick'    => 0.08,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,  // біом через DB.biome_ids=["7"]
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'IndustrialDumpFind' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'industrial_plastic',
                'rarity_filter'      => 1,
                'amount_range'       => [1, 3],
                'chance_per_tick'    => 0.12,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,  // біом через DB.biome_ids=["5"]
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'MountainArmyDepot' => [
            'effect_kind'   => 'rare_resource_grant',
            'effect_params' => [
                'resource_keyword'   => 'medical_compound',
                'rarity_filter'      => 1,
                'amount_range'       => [1, 2],
                'chance_per_tick'    => 0.10,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,  // біом через DB.biome_ids=["2"]
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // ============================================================
        // ⏱ Task-extend events (2 шт): подовжують активні задачі
        // ============================================================

        'MirageOases' => [
            'effect_kind'   => 'task_extend',
            'effect_params' => [
                'task_filter'         => ['Gather', 'ExploreTheArea'],
                'extra_minutes_range' => [1, 15],
                'state_modifier'      => [
                    'base_idle'    => 0.25,  // на базі ефект ослаблений
                    'biome_idle'   => 1.0,
                    'biome_active' => 1.0,
                ],
                'side_effects' => [
                    'water_loss_range'  => [1, 10],
                    'health_loss_range' => [1, 10],
                    'tired_loss_range'  => [1, 10],
                ],
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.08,  // legacy MirageOasisHandler 8% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'PolarNight' => [
            'effect_kind'   => 'task_extend',
            'effect_params' => [
                'task_filter'         => ['Gather', 'ExploreTheArea'],
                'extra_minutes_range' => [3, 10],
                'state_modifier'      => [
                    'base_idle'    => 0.5,
                    'biome_idle'   => 1.0,
                    'biome_active' => 1.0,
                ],
                'side_effects' => [
                    'tired_loss_range' => [1, 5],
                ],
                'grants_immunity_to' => ['NightAttacks'],  // компенсація: при Polar Night нічних нападників немає
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.10,  // нова подія, помірний gate (раз/10 хв apply)
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],
    ];

    /**
     * Стандартний default для відсутнього protection_item factor.
     * Якщо у player'а в інвентарі є вказаний crafted_item — застосовується
     * множник 0.5 (для damage) або 1.5 (для buff).
     */
    public float $protectionItemFactor = 0.5;

    /**
     * Стандартний throttle для notifications: max 1 нотіфікація про події/година
     * на гравця. Override magnitude triggers визначені у NotificationPolicy.
     */
    public int $notificationThrottleMinutes = 60;

    /**
     * Magnitude triggers, які пробивають throttle (надсилають незалежно від cap):
     */
    public array $criticalMagnitudeTriggers = [
        'health_loss_percent_above'   => 25,    // > 25% HP loss від current
        'resource_loss_percent_above' => 50,
        'gold_gain_above'             => 5000,
        'rare_item_drop'              => true,
        'health_after_below'          => 5,     // post-event health < 5
    ];

    /**
     * @return array<string,mixed>|null Подія по name_english ключу, або null якщо
     *                                  ключ не зареєстрований.
     */
    public function get(string $eventKey): ?array
    {
        return $this->events[$eventKey] ?? null;
    }

    /**
     * @return list<string> Зареєстровані ключі подій (name_english з БД).
     */
    public function keys(): array
    {
        return array_keys($this->events);
    }
}
