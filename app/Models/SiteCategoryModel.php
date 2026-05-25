<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Категории публичного сайта (ADR-050). Источник — WP-категории; `wp_term_id`
 * для идемпотентного импорта.
 */
class SiteCategoryModel extends Model
{
    protected $table         = 'site_categories';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['wp_term_id', 'slug', 'name', 'description', 'sort'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'wp_term_id'  => 'permit_empty|is_natural',
        'slug'        => 'required|max_length[160]|is_unique[site_categories.slug,id,{id}]',
        'name'        => 'required|max_length[160]',
        'description' => 'permit_empty',
        'sort'        => 'permit_empty|is_natural',
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;
}
