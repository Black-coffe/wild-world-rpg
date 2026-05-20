<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * v0.51.111 — Endgame scoring rules (Step 2 of endgame system).
 *
 * Defines point delta per event type + faction routing.
 * 1:1 faction → scenario mapping (per canon 2025-01-29):
 *   1=Militari→Dominance, 2=Partisans→Anarchy,
 *   3=Engineers→ScientificBreakthrough, 4=Farmers→Evacuation.
 *
 * Threshold (75000 default) tuned для medium pace ~3-6 months on 358 chars.
 * Rebalanceable через `.env` без deploy (CI4 BaseConfig auto-mapping).
 */
class EndgameScoring extends BaseConfig
{
    /** PvP kill (winner) — points до winner's faction. */
    public int $pvpKillPoints = 50;

    /** Quest completed — points до character's faction. */
    public int $questCompletionPoints = 100;

    /** Building upgrade — points до faction tied до building (per map below). */
    public int $buildingUpgradePoints = 200;

    /** Strategic object discovered — points до corresponding scenario faction. */
    public int $strategicObjectDiscoveryPoints = 500;

    /** Crafted item generic — points до character's faction. */
    public int $craftedItemPoints = 10;

    // ===================================================================
    // POST-ENDGAME REWARDS (v0.51.119)
    // Apply до winning faction members коли threshold hit.
    // ===================================================================

    /** Gold reward per winning character (winning faction). */
    public int $winnerGoldReward = 50000;

    /** Stat bump (+N до strength/agility/intellect) per winner. */
    public float $winnerStatBonus = 5.0;

    /**
     * Strategic object name_en → faction_id mapping.
     * Bunker → Militari (Dominance)
     * Technopark → Engineers (ScientificBreakthrough)
     * GhostCity → Partisans (Anarchy)
     * IslandFarm → Farmers (Evacuation) — S24
     *
     * @var array<string, int>
     */
    public array $strategicObjectFactionMap = [
        'Bunker'     => 1,
        'Technopark' => 3,
        'GhostCity'  => 2,
        'IslandFarm' => 4,
    ];

    /**
     * Building name_en → faction_id mapping.
     * Arsenal → Militari (id=1)
     * Workshop / Lab / RoboticsWorkshop / TeleportCenter → Engineers (id=3)
     * Greenhouse / HandPump → Farmers (id=4)
     * Other (CommunicationTower, BlastFurnace, Warehouse, Gym, SolarStation) — split by theme:
     *   - BlastFurnace, Warehouse → Militari (industrial/military supply)
     *   - SolarStation, CommunicationTower → Engineers (infrastructure tech)
     *   - Gym → Militari (training)
     *
     * @var array<string, int>
     */
    public array $buildingFactionMap = [
        'Arsenal'              => 1, // Militari
        'BlastFurnace'         => 1, // Militari (heavy industry)
        'Warehouse'            => 1, // Militari (supply)
        'Gym'                  => 1, // Militari (training)
        'Workshop'             => 3, // Engineers
        'Laboratory'           => 3, // Engineers
        'Lab'                  => 3, // Engineers (alias)
        'RoboticsWorkshop'     => 3, // Engineers
        'TeleportCenter'       => 3, // Engineers
        'CommunicationTower'   => 3, // Engineers
        'SolarStation'         => 3, // Engineers
        'Greenhouse'           => 4, // Farmers
        'HandPump'             => 4, // Farmers
    ];

    /**
     * Faction id → scenario name (1:1 canon mapping).
     *
     * @var array<int, string>
     */
    public array $factionScenarioMap = [
        1 => 'Dominance',              // Militari
        2 => 'Anarchy',                // Partisans
        3 => 'ScientificBreakthrough', // Engineers
        4 => 'Evacuation',             // Farmers
    ];

    /**
     * Russian display names для broadcasts/UI.
     *
     * @var array<string, string>
     */
    public array $scenarioRu = [
        'Dominance'              => 'Доминирование',
        'Anarchy'                => 'Анархия',
        'ScientificBreakthrough' => 'Научный прорыв',
        'Evacuation'             => 'Эвакуация',
    ];
}
