<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * F7.1 — декларативний registry всех 24 world events (game-доменних событий).
 *
 * Названа `WorldEvents` (а не `Events`) томв що `Config\Events` зайнятий
 * фреймворком CodeIgniter для системних event-listeners (`pre_system` тощо).
 *
 * НЕ ENABLED в v0.27.0 — це паралельний source-of-truth для проверки
 * через WorldEventsConfigTest. Реальне dispatch'ування з'явиться в F7.3
 * (EventTickHandler), когда effect-strategy classes будут готови (F7.2).
 *
 * Замінюесть (поетапно, через F7.10 cleanup):
 *   - 19 hand-rolled handler'ів в app/TaskHandlers/Events/ (~5389 LOC)
 *   - hardcoded probability-gates в каждомв handler'и
 *   - hardcoded `eventModel->where('name_english', X)->first()` lookup'и
 *
 * Структура запису:
 *   - 'effect_kind'         : один с 10 enum'ів — який клас Effect применувати
 *   - 'effect_params'       : kind-specific параметри
 *   - 'duration_minutes'    : цільова дюрація (замінюесть DB events.duration после
 *                             F7.10 migration; зараз DB маесть значительно більші)
 *   - 'frequency_weight'    : вага в weighted random_choice (F7.9 семантика;
 *                             замінюесть DB.frequency_per_week)
 *   - 'tick_chance'         : float 0..1 — ймовірність dispatch'а на одномв tick'у.
 *                             Дзеркало legacy `mt_rand(1,100) > X` гейтів усередини
 *                             handler'ів (Hurricane=0.35, Epidemic=0.10, Fever=1.0).
 *                             Диспетчер F7.3 гейтить compute() по цьомв полю.
 *   - 'protection_item'     : keyword crafted_item, який применуесть -50% damage
 *                             або +50% buff когда есть в інвентари (F7.7)
 *   - 'notification_kind'   : 'lifecycle' (start+end summary) | 'silent' | 'critical'
 *
 * Effect kinds enum (10):
 *   - damage_health        : зменшення health/tired (Hurricane, Snowfall, Volcanic, ...)
 *   - damage_resources     : відсоткова втрата stack ресурсів (MeteorRain rework)
 *   - heal                 : відновлення health/tired (GeothermalFountains)
 *   - attribute_boost      : +до stat або gold (Starfall, NorthernLights)
 *   - reveal_cells         : відкриваесть нови ячейки на мапи (MountainEcho)
 *   - gold_grant           : додаесть gold за формулою (GoldMine)
 *   - rare_resource_grant  : додаесть rare resource (DungeonOpening, BerryBoom, ...)
 *   - task_extend          : подовжуесть end_time активних задач (MirageOases, PolarNight)
 *   - gather_debuff        : знижуесть gather rate / збільшуесть водоспоживання (Dryness, ...)
 *   - noop                 : без механічного ефектв (резерв для майбутніх thematic событий)
 *
 * State modifier (поширений):
 *   ['base_idle' => 0, 'biome_idle' => 0.7, 'biome_active' => 1.0]
 *   - base_idle    : игрок на бази, не Gather/Explore  → захищений (0%)
 *   - biome_idle   : игрок не на бази, не Gather/Explore → частковий ефект (~70%)
 *   - biome_active : игрок в Gather/Explore             → повний ефект (100%)
 *
 * Див. mmorpg-vault/lore/refactor/F7-Audit.md
 */
class WorldEvents extends BaseConfig
{
    /**
     * Перелік валідних effect_kind enum'ів. Используетться WorldEventsConfigTest.
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
        'silent',     // зовсем без уведомлений (фоновый ефект)
        'critical',   // только если magnitude превышуесть threshold
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
     * Ключ — `events.name_english` с БД (точне співпадіння). Поки що ключ
     * оставається сполучною ланкою; в F7.10 додамо UUID-стабільний ключ.
     */
    public array $events = [
        // ============================================================
        // 🌪 Damage-events (10 шт): погодни катаклізми, биом-специфічни
        // ============================================================

        'Hurricane' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'   => 'health',
                'state_modifier' => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'level_scaling'   => true,
                'biome_factor'    => true,
                'random_factor'   => [0.5, 1.5],
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
                'time_window'          => ['20:00', '05:00'],  // лише в нічни години
                'sleeping_player_skip' => 12,                  // skip если last_seen > 12h
                'level_scaling'        => false,
                'biome_factor'         => false,
                'random_factor'        => [0.7, 1.3],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.10,  // legacy NightAttacksHandler 10% gate
            'protection_item'   => null,  // F7.7: будь-яка зброя в інвентари
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
            'tick_chance'       => 1.0,   // MeteorRain — apply on tick (rework с legacy random-cells)
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        'Epidemic' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'    => 'both',
                'state_modifier'   => [
                    'base_idle'    => 0.06,  // 6% chance заразитись на бази
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
                'damage_target'   => 'both',
                'tired_ratio'     => 0.5,  // tired loss = damage / 2
                'state_modifier'  => [
                    'base_idle'    => 0.0,
                    'biome_idle'   => 0.5,
                    'biome_active' => 0.75,  // 75% chance hit при gather
                ],
                'level_scaling'   => true,
                'biome_factor'    => true,
                'random_factor'   => [0.5, 1.5],
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
                'damage_target'   => 'random_h_or_t',
                'state_modifier' => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'damage_range'    => [1, 90],
                'level_scaling'   => false,
                'biome_factor'    => false,
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy SnowFallHandler 30% gate
            'protection_item'   => 'Bandage',
            'notification_kind' => 'lifecycle',
        ],

        'SpringFlood' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'   => 'health',
                'state_modifier' => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'level_scaling'   => true,
                'biome_factor'    => false,
                'random_factor'   => [0.5, 1.5],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy SpringFloodHandler 30% gate
            'protection_item'   => 'Bandage',
            'notification_kind' => 'lifecycle',
        ],

        'Tremor' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'   => 'health',
                'state_modifier' => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'level_scaling'   => true,
                'biome_factor'    => false,
                'random_factor'   => [0.5, 1.5],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.30,  // legacy TremorHandler 30% gate
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // ⚠️ name_english в БД = 'volcanic_eruption' (snake_case). НЕ міняємо ключ
        // щоб lookup работав. У F7.10 переімінуємо в 'VolcanicEruption' разом з
        // міграцією іменування в БД.
        'volcanic_eruption' => [
            'effect_kind'   => 'damage_health',
            'effect_params' => [
                'damage_target'   => 'health',
                'state_modifier' => self::DEFAULT_DAMAGE_STATE_MODIFIER,
                'level_scaling'   => true,
                'biome_factor'    => true,
                'random_factor'   => [0.5, 1.5],
                'base_damage_mul' => 10,  // calculateDamage в Volcanic множить на 10
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
                'level_scaling'    => true,
                'biome_factor'     => true,
                'random_factor'    => [0.5, 1.5],
                // Додатковий ефект: -0.01 до случаового атрибуту
                'attr_drain_pool'  => ['experience', 'strength', 'agility', 'intellect'],
                'attr_drain_value' => 0.01,
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
                    'wet'     => ['health' => [5, 10]],
                    'dry'     => ['tired'  => [3, 7]],
                    'default' => ['health' => [1, 3], 'tired' => [1, 3]],
                ],
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // legacy FeverHandler не мав top-level gate (только two_stage внутрі)
            'protection_item'   => 'Antiseptic',
            'notification_kind' => 'lifecycle',
        ],

        'LocustExodus' => [
            'effect_kind'   => 'gather_debuff',
            'effect_params' => [
                'gather_rate_modifier'         => -0.50,
                'water_consumption_multiplier' => 1.0,
                'food_only'                    => true,
                'biome_type_filter'            => null,  // биом 6 (Поля) задаеться через DB.biome_ids
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 1.0,   // gather_debuff — це state, не tick
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],

        // ============================================================
        // ✨ Buff events (5 шт): атрибутни бонуси
        // ============================================================

        'NorthernLights' => [
            'effect_kind'   => 'attribute_boost',
            'effect_params' => [
                'attribute_pool'    => ['experience', 'health', 'strength', 'agility', 'intellect', 'tired', 'gold'],
                'small_boost_range' => [1.00, 1.11],   // для exp/str/agi/int
                'large_boost_range' => [1, 100],        // для health/tired/gold
                'cap_h_t_g'         => 100,
                'one_shot_at_start' => true,            // 1 boost / игрок / событие (не tick)
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
            'duration_minutes'  => 35,    // швидка событие (як зараз)
            'frequency_weight'  => 5,     // маесть бути найчастішою (лор: «сотни падаючих зірок»)
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
                'chance_per_tick'   => 0.06,             // 6% (1:1 с GoldVeinHandler)
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
                'on_base_modifier'  => 0.25,  // на бази ефект ослаблений (як зараз)
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
                'chance_per_tick'    => 0.20,            // 20% за tick (часта событие)
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,            // биом 1 (Лісостеп) задаеться DB.biome_ids
            ],
            'duration_minutes'  => 60,
            'frequency_weight'  => 2,
            'tick_chance'       => 1.0,   // RareResource.compute робить власний chance_per_tick=0.20
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
                'biome_type_filter'  => null,            // биом 4 (Ріки) через DB
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
                'rarity_filter'      => 1,               // только rarity=1
                'amount_range'       => [1, 2],
                'chance_per_tick'    => 0.10,
                'requires_state'     => 'gather',
                'biome_type_filter'  => null,            // биом 5 (Тропіки) через DB
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
                'resource_keyword'   => 'RevealingHiddenCameras',  // 1:1 с handler
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

        // ============================================================
        // ⏱ Task-extend events (2 шт): подовжують активни задачи
        // ============================================================

        'MirageOases' => [
            'effect_kind'   => 'task_extend',
            'effect_params' => [
                'task_filter'         => ['Gather', 'ExploreTheArea'],
                'extra_minutes_range' => [1, 15],
                'state_modifier'      => [
                    'base_idle'    => 0.25,  // на бази ефект ослаблений
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
                'grants_immunity_to' => ['NightAttacks'],  // компенсація: при Polar Night нічних нападників немаесть
            ],
            'duration_minutes'  => 90,
            'frequency_weight'  => 1,
            'tick_chance'       => 0.10,  // нова событие, помірний gate (раз/10 хв apply)
            'protection_item'   => null,
            'notification_kind' => 'lifecycle',
        ],
    ];

    /**
     * Стандартний default для отсутствього protection_item factor.
     * Если в player'а в інвентари есть вказаний crafted_item — применується
     * множник 0.5 (для damage) або 1.5 (для buff).
     */
    public float $protectionItemFactor = 0.5;

    /**
     * Стандартний throttle для notifications: max 1 уведомления про подіи/година
     * на игрока. Override magnitude triggers определени в NotificationPolicy.
     */
    public int $notificationThrottleMinutes = 60;

    /**
     * Magnitude triggers, яки пробитають throttle (посылаають незалежно від cap):
     */
    public array $criticalMagnitudeTriggers = [
        'health_loss_percent_above'   => 25,    // > 25% HP loss від current
        'resource_loss_percent_above' => 50,
        'gold_gain_above'             => 5000,
        'rare_item_drop'              => true,
        'health_after_below'          => 5,     // post-event health < 5
    ];

    /**
     * @return array<string,mixed>|null Событие по name_english ключу, або null если
     *                                  ключ не зареєстрований.
     */
    public function get(string $eventKey): ?array
    {
        return $this->events[$eventKey] ?? null;
    }

    /**
     * @return list<string> Зареєстровани ключи событий (name_english с БД).
     */
    public function keys(): array
    {
        return array_keys($this->events);
    }
}
