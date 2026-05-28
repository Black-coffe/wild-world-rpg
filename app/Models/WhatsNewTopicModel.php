<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * W7b (ADR-065 Part 2) — темы каталога «📚 Что нового».
 *
 * Контент-таблица (топики добавляются как новая строка без релиза). Читается
 * {@see \App\Services\Player\WhatsNewService}. returnType=array (как GameTipsModel).
 */
class WhatsNewTopicModel extends Model
{
    protected $table         = 'whats_new_topics';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'topic_key', 'title', 'summary', 'image_path', 'sort_order', 'content',
        'created_at', 'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
