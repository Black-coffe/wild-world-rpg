<?php
namespace App\Models;

use CodeIgniter\Model;

class GeneralModel extends Model
{
    public function countAffectedRows($characterId)
    {
        $tables = $this->db->listTables();
        $countData = [];
        if ($tables === false) {
            return $countData;
        }
        foreach ($tables as $table) {
            if ($this->db->fieldExists('character_id', $table)) {
                $builder = $this->db->table($table);
                $count = $builder->where('character_id', $characterId)->countAllResults();
                if ($count > 0) {
                    $countData[$table] = $count;
                }
            }
        }
        return $countData;
    }

    public function deleteCharacterData($characterId)
    {
        $tables = $this->db->listTables();
        if ($tables === false) {
            return;
        }
        foreach ($tables as $table) {
            if ($this->db->fieldExists('character_id', $table)) {
                $builder = $this->db->table($table);
                $builder->where('character_id', $characterId)->delete();
            }
        }
    }
}
