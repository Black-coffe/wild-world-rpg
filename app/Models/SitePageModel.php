<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Статические страницы публичного сайта (ADR-052): О проекте, Контакты и т.п.
 */
class SitePageModel extends Model
{
    protected $table         = 'site_pages';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['slug', 'title', 'content_html', 'meta_description', 'status'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'               => 'permit_empty|is_natural',
        'slug'             => 'required|max_length[200]|is_unique[site_pages.slug,id,{id}]',
        'title'            => 'required|max_length[255]',
        'content_html'     => 'required',
        'meta_description' => 'permit_empty|max_length[320]',
        'status'           => 'required|in_list[draft,published]',
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;
}
