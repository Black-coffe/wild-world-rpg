<?php

namespace App\Helpers;

use App\Models\BiomeModel;

class MyRules
{
    public function valid_biomes_array(string $str = null, string &$error = null, array $data): bool
    {
        $biomeModel = new BiomeModel();

        // If $str is an array, convert it to a string.
        if (is_array($str)) {
            $str = implode(',', $str);
        }

        $biomeIds = explode(',', (string) $str);
        foreach ($biomeIds as $id) {
            if (!is_numeric($id) || !$biomeModel->find($id)) {
                $error = 'Invalid biome ID: ' . $id;
                return false;
            }
        }
        return true;
    }
}
