<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Пивот пост↔категория (M:N) (ADR-050). Управляется импортёром/CRUD через
 * {@see syncForPost()}; find/update по композитному ключу не используются.
 */
class SitePostCategoryModel extends Model
{
    protected $table         = 'site_post_categories';
    protected $primaryKey    = 'post_id';
    protected $useAutoIncrement = false;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = ['post_id', 'category_id'];

    protected $validationRules = [];
    protected $skipValidation  = true;

    /**
     * Пересобрать связи поста с категориями (delete-then-insert).
     *
     * @param list<int> $categoryIds
     */
    public function syncForPost(int $postId, array $categoryIds): void
    {
        // Свежий builder на каждый вызов (db->table) — без quirk состояния
        // билдера при повторных вызовах (память feedback_ci4_model_builder_state_quirk).
        $this->db->table($this->table)->where('post_id', $postId)->delete();

        $rows = [];
        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $rows[] = ['post_id' => $postId, 'category_id' => $categoryId];
        }

        if ($rows !== []) {
            $this->db->table($this->table)->insertBatch($rows);
        }
    }

    /**
     * Локальные id категорий, к которым привязан пост.
     *
     * @return list<int>
     */
    public function categoryIdsForPost(int $postId): array
    {
        $result = $this->db->table($this->table)
            ->select('category_id')
            ->where('post_id', $postId)
            ->get();
        $rows = $result === false ? [] : $result->getResultArray();

        $ids = [];
        foreach ($rows as $row) {
            $value = $row['category_id'] ?? null;
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
