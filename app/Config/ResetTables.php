<?php namespace Config;

use CodeIgniter\Config\BaseConfig;

class ResetTables extends BaseConfig
{
    public $tables = [
        'characters' => 'id',
        'character_buildings' => 'character_id',
        'character_data' => 'character_id',
        'character_factions' => 'character_id',
        'character_game_tips' => 'character_id',
        'character_message_status' => 'character_id',
        'character_resources' => 'id_characters',
        'character_tasks' => 'character_id',
        'claimed_cells' => 'character_id',
        'crafted_items_log' => 'character_id',
        'event_effects_log' => 'character_id',
        'explored_cells' => 'character_id',
        'action_log' => 'character_id',
        'quest_steps' => 'character_id',
        'transactions' => 'character_id',
    ];
}
