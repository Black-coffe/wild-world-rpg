<?php
use App\Models\BiomeModel;

function validate_biomes($str, string &$error = null, $parameters = null)
{
    $biomeModel = new BiomeModel();

    // If $str is an array, convert it to a string.
    if (is_array($str)) {
        $str = implode(',', $str);
    }

    $biomeIds = explode(',', $str);
    foreach ($biomeIds as $id) {
        if (!is_numeric($id) || !$biomeModel->find($id)) {
            $error = 'Invalid biome ID: ' . $id;
            return false;
        }
    }
    return true;
}
