<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * WipeManifest — единый source-of-truth классификации ВСЕХ таблиц БД для механики
 * полного вайпа сервера (ADR-087, задача Asana «ВАЙП всех игроков», 2026-06-01).
 *
 * 🔴 КОНСТИТУЦИОННЫЙ ИНВАРИАНТ: КАЖДАЯ таблица БД ОБЯЗАНА присутствовать здесь ровно
 * один раз. Любая новая таблица (миграция) без записи здесь → падает
 * {@see \Tests\Wipe\WipeManifestCoverageTest} → блокирует deploy. Это жёсткий
 * анти-дрифт гейт «ни одна таблица не упущена». См. правило в CLAUDE.md
 * (§ КОНСТИТУЦИОННОЕ ПРАВИЛО WIPE-COVERAGE).
 *
 * Заменяет прежний плоский Config\ResetTables (15 таблиц из 71 — был сломан:
 * ~18 player-таблиц не входили в сброс → оставались сиротами; `characters => id`
 * УДАЛЯЛ строку персонажа вместо сброса, теряя имя/id).
 *
 * Стратегии (одна на таблицу):
 *   - KEEP            — НЕ трогаем (мир/карта, контент-определения, идентичность, админ/сайт).
 *   - PLAYER_DATA     — прогресс игрока. Полный вайп → DELETE всех строк. Сброс одного
 *                       персонажа → DELETE WHERE <link>. `by` = character|telegram.
 *   - TRANSIENT       — транзиентная населённость мира / очереди заданий. Полный вайп →
 *                       DELETE всех строк (кроны/игроки наполнят заново). В single-char НЕ трогаем.
 *   - CHARACTER_RESET — таблица `characters`: сохраняем id + telegram_user_id + name +
 *                       created_at + ПРЕФЕРЕНСЫ; сбрасываем прогресс к стартовым; респавн на юг.
 *   - IDENTITY_RESET  — `telegram_users`: сохраняем идентичность (id/telegram_id/имена/
 *                       префы/blocked_at); обнуляем устаревшие указатели на удалённые сообщения.
 *   - SEED_RESET      — экономика/эндгейм-агрегаты: строки остаются, счётчики → 0/seed.
 */
class WipeManifest extends BaseConfig
{
    public const KEEP            = 'keep';
    public const PLAYER_DATA     = 'player_data';
    public const TRANSIENT       = 'transient';
    public const CHARACTER_RESET = 'character_reset';
    public const IDENTITY_RESET  = 'identity_reset';
    public const SEED_RESET      = 'seed_reset';

    /**
     * Классификация всех 71 таблицы. Ключ = имя таблицы.
     *
     * Дескриптор:
     *   strategy : одна из констант выше (обязательно)
     *   link     : string|list<string> — колонка(и) связи с игроком (для PLAYER_DATA)
     *   by       : 'character'|'telegram' — чем линкуется (для PLAYER_DATA, single-char путь)
     *   reset    : array<string,mixed> — колонки→значения для IDENTITY_RESET / SEED_RESET
     *   note     : человекочитаемое пояснение (для preview-таблицы в админке)
     *
     * @var array<string, array{strategy:string, link?:string|list<string>, by?:string, reset?:array<string,mixed>, note:string}>
     */
    public array $tables = [
        // ─────────────────────────────────────────────────────────────
        // 🟢 KEEP — Мир и карта (канон «игровой мир и карта остаются»)
        // ─────────────────────────────────────────────────────────────
        'map'                  => ['strategy' => self::KEEP, 'note' => 'Карта мира (~997k ячеек) — структура мира, остаётся'],
        'images'               => ['strategy' => self::KEEP, 'note' => 'Пиксельный кэш карты (x/y/color) — часть мира, остаётся'],
        'biomes'               => ['strategy' => self::KEEP, 'note' => 'Определения биомов'],
        'biome_world_object_map' => ['strategy' => self::KEEP, 'note' => 'Размещение мировых объектов по карте (генерация мира)'],
        'world_objects'        => ['strategy' => self::KEEP, 'note' => 'Определения мировых объектов'],
        'npcs'                 => ['strategy' => self::KEEP, 'note' => 'Определения NPC (не спавны)'],
        'settlements'          => ['strategy' => self::KEEP, 'note' => 'Статические NPC-поселения (ADR-101) — мировой контент, курируемые координаты'],
        'settlement_npcs'      => ['strategy' => self::KEEP, 'note' => 'Ростер жителей поселений (ADR-101) — контент-определение (спавны жителей в npc_spawns=TRANSIENT)'],
        'settlement_shop_items' => ['strategy' => self::KEEP, 'note' => 'Ассортимент фракционных лавок оплотов (ADR-101 рефайн) — курируемый контент-список существующих предметов'],

        // ─────────────────────────────────────────────────────────────
        // 🟢 KEEP — Контент и правила игры (определения, не данные игроков)
        // ─────────────────────────────────────────────────────────────
        'achievements'         => ['strategy' => self::KEEP, 'note' => 'Определения достижений'],
        'titles'               => ['strategy' => self::KEEP, 'note' => 'Определения титулов (ADR-112, E11) — контент, как achievements'],
        'collections'          => ['strategy' => self::KEEP, 'note' => 'Определения коллекций (ADR-119, E19) — контент-сеты для собирательства'],
        'collection_slots'     => ['strategy' => self::KEEP, 'note' => 'Слоты коллекций (ADR-119, E19) — какой ресурс/сколько сдать (контент-определение)'],
        'streak_milestones'    => ['strategy' => self::KEEP, 'note' => 'Определения вех login-стрика (ADR-132) — пороги дней 3/5/7/10/15/30/50/100 и их награды (контент)'],
        'buildings'            => ['strategy' => self::KEEP, 'note' => 'Определения построек'],
        'crafted_items'        => ['strategy' => self::KEEP, 'note' => 'Рецепты/определения предметов'],
        'loot_table_items'     => ['strategy' => self::KEEP, 'note' => 'Лут-таблицы NPC (ADR-107) — контент-определения дропа (npcs.loot_table_id), нет player-связи'],
        'events'               => ['strategy' => self::KEEP, 'note' => 'Определения мировых событий'],
        'factions'             => ['strategy' => self::KEEP, 'note' => 'Определения фракций'],
        'game_tips'            => ['strategy' => self::KEEP, 'note' => 'Контент советов /tips'],
        'outfits'              => ['strategy' => self::KEEP, 'note' => 'Определения брони'],
        'weapons'              => ['strategy' => self::KEEP, 'note' => 'Определения оружия'],
        'resources'            => ['strategy' => self::KEEP, 'note' => 'Определения ресурсов'],
        'tasks'                => ['strategy' => self::KEEP, 'note' => 'Определения задач (хэндлеры)'],
        'quests'               => ['strategy' => self::KEEP, 'note' => 'Определения квестов'],
        'quest_requirements'   => ['strategy' => self::KEEP, 'note' => 'Требования квестов (определения)'],
        'npc_dialogues'        => ['strategy' => self::KEEP, 'note' => 'Реплики NPC (ADR-089, контент-определения, не player-данные)'],
        'npc_dialogue_nodes'   => ['strategy' => self::KEEP, 'note' => 'Узлы ветвящихся диалогов именных NPC (ADR-089 Ф5+, контент)'],
        'sales'                => ['strategy' => self::KEEP, 'note' => 'Прайс-лист рынка (сид, без привязки к игроку)'],
        'game_settings'        => ['strategy' => self::KEEP, 'note' => 'Admin-tunable баланс (КРИТИЧНО сохранить)'],
        'settings'             => ['strategy' => self::KEEP, 'note' => 'CI4 Settings'],
        'migrations'           => ['strategy' => self::KEEP, 'note' => 'Версии схемы — НИКОГДА не трогать'],

        // ─────────────────────────────────────────────────────────────
        // 🟢 KEEP — Админ / сайт / SEO (не игроки)
        // ─────────────────────────────────────────────────────────────
        'users'                => ['strategy' => self::KEEP, 'note' => 'Веб-админы (НЕ игроки)'],
        'admin_audit_log'      => ['strategy' => self::KEEP, 'note' => 'Аудит админ-действий (пишем сюда сам вайп)'],
        'site_categories'      => ['strategy' => self::KEEP, 'note' => 'Контент сайта'],
        'site_pages'           => ['strategy' => self::KEEP, 'note' => 'Контент сайта'],
        'site_posts'           => ['strategy' => self::KEEP, 'note' => 'Контент сайта (блог/гайды)'],
        'site_post_categories' => ['strategy' => self::KEEP, 'note' => 'Связи постов сайта'],
        'site_redirects'       => ['strategy' => self::KEEP, 'note' => 'Редиректы сайта'],
        'seo_gsc_pages'        => ['strategy' => self::KEEP, 'note' => 'SEO снапшоты Search Console'],
        'seo_gsc_queries'      => ['strategy' => self::KEEP, 'note' => 'SEO снапшоты Search Console'],
        'whats_new_topics'     => ['strategy' => self::KEEP, 'note' => 'Контент «что нового» (девблог)'],
        'polls'                => ['strategy' => self::KEEP, 'note' => 'Определения опросов'],
        'poll_answers'         => ['strategy' => self::KEEP, 'note' => 'Варианты ответов опросов (определения)'],

        // ─────────────────────────────────────────────────────────────
        // 🟢 KEEP — Идентичность игроков (канон «имена игроков и их айдишки остаются»)
        // ─────────────────────────────────────────────────────────────
        'character_names'      => ['strategy' => self::KEEP, 'note' => 'Пул имён персонажей'],

        // ─────────────────────────────────────────────────────────────
        // 🟡 IDENTITY_RESET — telegram_users: идентичность остаётся, мусор-указатели чистим
        // ─────────────────────────────────────────────────────────────
        'telegram_users'       => [
            'strategy' => self::IDENTITY_RESET,
            'reset'    => [
                'last_map_message_id'         => null,
                'last_map_message_created_at' => null,
                'last_seen'                   => null, // E6 (ADR-108) — новый сезон = чистый last-seen
            ],
            'note' => 'Аккаунты игроков (id/telegram_id/имена/префы/blocked_at остаются; чистим указатели на удалённые сообщения + last_seen)',
        ],

        // ─────────────────────────────────────────────────────────────
        // 🔵 CHARACTER_RESET — characters: сброс прогресса с сохранением id+name+префов
        // ─────────────────────────────────────────────────────────────
        'characters'           => ['strategy' => self::CHARACTER_RESET, 'note' => 'Персонажи: сброс к стартовым, сохранены id+telegram_user_id+name+created_at+преференсы, респавн на юг'],

        // ─────────────────────────────────────────────────────────────
        // 🔴 PLAYER_DATA — прогресс игрока (полный вайп = DELETE всех; single-char = WHERE link)
        // ─────────────────────────────────────────────────────────────
        'action_log'             => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Лог действий игрока'],
        'base_storage'           => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Хранилище базы'],
        'battle_logs'            => ['strategy' => self::PLAYER_DATA, 'link' => ['player1_id', 'player2_id'], 'by' => 'character', 'note' => 'Логи боёв PvP'],
        'character_tributes'     => ['strategy' => self::PLAYER_DATA, 'link' => ['master_id', 'vassal_id'], 'by' => 'character', 'note' => 'Трофейная подать PvP (ADR-135) — активные/исторические отношения дани между игроками'],
        'character_achievements' => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Разблокированные достижения'],
        'character_titles'       => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Разблокированные титулы (ADR-112, E11)'],
        'character_collection_slots' => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Заполненные слоты коллекций (ADR-119, E19) — сдача-синк прогресса'],
        'character_streak_milestones' => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Забранные вехи login-стрика (ADR-132) — идемпотентность выдачи; новый сезон = с нуля'],
        'character_buildings'    => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Постройки игрока'],
        'character_daily_tasks'  => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Ежедневные задания (ADR-109)'],
        'character_data'         => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Координаты/статы/состояние персонажа'],
        'character_factions'     => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Членство во фракции'],
        'character_game_tips'    => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Просмотренные советы'],
        'character_message_status' => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Статус сообщений персонажа'],
        'character_npc_relations' => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Отношения NPC к персонажу (ADR-089 Фаза 2 reactivity)'],
        'character_resources'    => ['strategy' => self::PLAYER_DATA, 'link' => 'id_characters', 'by' => 'character', 'note' => 'Инвентарь ресурсов'],
        'character_ruin_loot'    => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Кулдаун лута охраняемых руин (ADR-101 Фаза 4)'],
        'character_tasks'        => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Активные/очередные задачи'],
        'characters_outfits'     => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Броня игрока'],
        'characters_weapons'     => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Оружие игрока'],
        'claimed_cells'          => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Захваченные ячейки (база/лагерь)'],
        'crafted_items_log'      => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Журнал скрафченного (инстансы предметов)'],
        'event_effects_log'      => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Эффекты событий на игрока'],
        'explored_cells'         => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Туман войны / исследованные ячейки'],
        'item_modifiers'         => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Модификаторы предметов игрока'],
        'player_detection'       => ['strategy' => self::PLAYER_DATA, 'link' => ['detector_player_id', 'detected_player_id'], 'by' => 'character', 'note' => 'Детект игроков (текущий)'],
        'player_detection_history' => ['strategy' => self::PLAYER_DATA, 'link' => ['detector_player_id', 'detected_player_id'], 'by' => 'character', 'note' => 'История детекта игроков'],
        'poll_votes'             => ['strategy' => self::PLAYER_DATA, 'link' => 'telegram_user_id', 'by' => 'telegram', 'note' => 'Голоса игроков в опросах'],
        'pvp_ladder'             => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'PvP-рейтинг'],
        'quest_steps'            => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Прогресс по квестам'],
        'teleport_beacon_logs'   => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Логи телепортов'],
        'teleport_beacons'       => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Маяки телепортации игрока'],
        'transactions'           => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Торговые транзакции игрока'],
        'oracle_bets'            => ['strategy' => self::PLAYER_DATA, 'link' => 'character_id', 'by' => 'character', 'note' => 'Ставки игрока в «Оракуле острова» (ADR-133) — прогресс/история; новый сезон с нуля'],

        // ─────────────────────────────────────────────────────────────
        // 🟠 TRANSIENT — транзиентная населённость мира / очереди (полный вайп = DELETE всех)
        // ─────────────────────────────────────────────────────────────
        'active_events'        => ['strategy' => self::TRANSIENT, 'note' => 'Активные мировые события (крон наполнит заново)'],
        'npc_spawns'           => ['strategy' => self::TRANSIENT, 'note' => 'Спавны NPC на карте (крон респавнит)'],
        'caravans'             => ['strategy' => self::TRANSIENT, 'note' => 'Торговые караваны (крон спавнит)'],
        'faction_projects'     => ['strategy' => self::TRANSIENT, 'note' => 'Прогресс фракционных проектов (пересоздаются)'],
        'oracle_markets'       => ['strategy' => self::TRANSIENT, 'note' => 'Экземпляры рынков «Оракул острова» (ADR-133) — крон пересоздаёт каждый цикл'],
        'oracle_outcomes'      => ['strategy' => self::TRANSIENT, 'note' => 'Исходы рынков «Оракул острова» (ADR-133) — привязаны к oracle_markets, пересоздаются'],
        'queue_jobs'           => ['strategy' => self::TRANSIENT, 'note' => 'Очередь фоновых заданий'],
        'queue_jobs_failed'    => ['strategy' => self::TRANSIENT, 'note' => 'Проваленные фоновые задания'],

        // ─────────────────────────────────────────────────────────────
        // 🟣 SEED_RESET — экономика/эндгейм-агрегаты (строки остаются, счётчики → 0)
        // ─────────────────────────────────────────────────────────────
        'faction_endgame_scores' => [
            'strategy' => self::SEED_RESET,
            'reset'    => [
                'score'            => 0,
                'threshold_hit'    => 0,
                'threshold_hit_at' => null,
                'last_event_at'    => null,
            ],
            'note' => 'Эндгейм-очки фракций (4 строки) — сброс к 0',
        ],
        'resources_bank'       => [
            'strategy' => self::SEED_RESET,
            'reset'    => [
                'current_quantity'    => 0,
                'resources_purchased' => 0,
                'resources_sold'      => 0,
            ],
            'note' => 'Глобальный рыночный банк — сброс счётчиков к 0',
        ],
    ];

    /**
     * CHARACTER_RESET payload — прогресс-колонки `characters` → стартовые значения.
     * Сохраняются (НЕ перечислены здесь): id, telegram_user_id, name, created_at и
     * ПРЕФЕРЕНСЫ (disable_media, daily_tips_enabled, duels_open, locale, notify_sound,
     * preferred_map_type) — настройки переживают вайп (как сохранение настроек в Rust).
     *
     * gold=1000 — каноничное стартовое значение из StartCommand (НЕ db-default 100).
     * cell_number/biome_id вычисляются на лету (респавн), см. $characterRespawn.
     *
     * @var array<string, int|float|string|null>
     */
    public array $characterResetValues = [
        'level'                     => 1,
        'experience'                => 0.01,
        'health'                    => 100,
        'tired'                     => 100,
        'strength'                  => 0.01,
        'agility'                   => 0.01,
        'intellect'                 => 0.01,
        'specialization'            => null,
        'gold'                      => 1000,
        'combat_drone_active_until' => null,
        'weight_capacity'           => 9999,
        'trading_karma'             => 100,
        'insurance'                 => 0,
        'has_renamed'               => 0,
        'last_name_change'          => null,
        'low_health_notified_at'    => null,
        'last_message_id'           => null,
        'endgame_state'             => 'active',
        'endgame_lock_at'           => null,
        'last_respawn_at'           => null,
        'last_planted_crop'         => null,
        'well_fed_until'            => null,
        'specialization_changed_at' => null,
        'whats_new_seen'            => null,
        'npc_kills'                 => 0, // ADR-088 Фаза 2 — счётчик побед над NPC (прогресс).
        'login_streak'              => 0,    // E6 (ADR-108) Ф3 — серия входов (прогресс) → 0 после вайпа.
        'login_streak_last_day'     => null, // E6 (ADR-108) Ф3 — дата последнего входа → null после вайпа.
        'active_title_id'           => null, // E11 (ADR-112) — экипированный титул → null после вайпа.
    ];

    /**
     * Параметры респавна персонажа после сброса — идентично StartCommand:
     * случайная ячейка на безопасном юге (coordinate_y >= min_y) в разрешённых биомах.
     *
     * @var array{min_y:int, biomes:list<int>}
     */
    public array $characterRespawn = [
        'min_y'  => 900,
        'biomes' => [1, 2, 3, 5, 6, 7, 8, 9],
    ];
}
