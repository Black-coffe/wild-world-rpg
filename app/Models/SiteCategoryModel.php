<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Категории публичного сайта (ADR-052). Источник — WP-категории; `wp_term_id`
 * для идемпотентного импорта.
 */
class SiteCategoryModel extends Model
{
    protected $table         = 'site_categories';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    // `seo_title` — короткий заголовок только для тега <title> (SEO-аудит 24.07); H1 берётся
    // из `name`. Обязателен в allowedFields, иначе Model::update молча выбросит поле
    // (memory feedback_ci4_alter_column_needs_allowedfields).
    protected $allowedFields = ['wp_term_id', 'slug', 'name', 'seo_title', 'description', 'sort'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'          => 'permit_empty|is_natural',
        'wp_term_id'  => 'permit_empty|is_natural',
        'slug'        => 'required|max_length[160]|is_unique[site_categories.slug,id,{id}]',
        'name'        => 'required|max_length[160]',
        'description' => 'permit_empty',
        'sort'        => 'permit_empty|is_natural',
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;
}
