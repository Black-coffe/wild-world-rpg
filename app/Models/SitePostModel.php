<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Посты публичного сайта (ADR-050) — блог/devblog/лор. Импортируются из WP,
 * сверяются с каноном (`canon_reviewed`) и переписываются под текущий ЛОР.
 */
class SitePostModel extends Model
{
    protected $table         = 'site_posts';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'wp_post_id',
        'slug',
        'title',
        'excerpt',
        'content_html',
        'featured_image',
        'meta_description',
        'status',
        'published_at',
        'canon_reviewed',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'               => 'permit_empty|is_natural',
        'wp_post_id'       => 'permit_empty|is_natural',
        'slug'             => 'required|max_length[200]|is_unique[site_posts.slug,id,{id}]',
        'title'            => 'required|max_length[255]',
        'excerpt'          => 'permit_empty',
        'content_html'     => 'required',
        'featured_image'   => 'permit_empty|max_length[255]',
        'meta_description' => 'permit_empty|max_length[320]',
        'status'           => 'required|in_list[draft,published]',
        'published_at'     => 'permit_empty|valid_date[Y-m-d H:i:s]',
        'canon_reviewed'   => 'permit_empty|in_list[0,1]',
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;

    /**
     * Опубликованные посts для публичной ленты (новые сверху).
     *
     * @return list<array<string,mixed>>
     */
    public function publishedFeed(int $limit = 0): array
    {
        $builder = $this->where('status', 'published')->orderBy('published_at', 'DESC');
        if ($limit > 0) {
            $builder->limit($limit);
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $builder->findAll();

        return $rows;
    }

    /**
     * Опубликованные посты одной категории (через M:N), новые сверху.
     *
     * @return list<array<string,mixed>>
     */
    public function publishedInCategory(int $categoryId, int $limit = 0): array
    {
        $builder = $this->select('site_posts.*')
            ->join('site_post_categories', 'site_post_categories.post_id = site_posts.id')
            ->where('site_post_categories.category_id', $categoryId)
            ->where('site_posts.status', 'published')
            ->orderBy('site_posts.published_at', 'DESC');
        if ($limit > 0) {
            $builder->limit($limit);
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $builder->findAll();

        return $rows;
    }
}
