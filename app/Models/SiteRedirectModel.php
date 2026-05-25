<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * 301/302-редиректы для сохранения SEO при переезде с WordPress (ADR-050).
 * Резолвится в {@see \App\Controllers\Errors::notFound()} перед отдачей 404.
 */
class SiteRedirectModel extends Model
{
    protected $table         = 'site_redirects';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['from_path', 'to_path', 'code', 'hits'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'from_path' => 'required|max_length[255]|is_unique[site_redirects.from_path,id,{id}]',
        'to_path'   => 'required|max_length[255]',
        'code'      => 'required|in_list[301,302,308]',
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;

    /**
     * Найти активный редирект по нормализованному пути (ведущий /, без хвостового /).
     *
     * @return array{from_path:string,to_path:string,code:int,id:int}|null
     */
    public function matchPath(string $normalizedPath): ?array
    {
        /** @var array<string,mixed>|null $row */
        $row = $this->where('from_path', $normalizedPath)->first();
        if ($row === null) {
            return null;
        }

        return [
            'id'        => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'from_path' => is_string($row['from_path'] ?? null) ? $row['from_path'] : $normalizedPath,
            'to_path'   => is_string($row['to_path'] ?? null) ? $row['to_path'] : '/',
            'code'      => is_numeric($row['code'] ?? null) ? (int) $row['code'] : 301,
        ];
    }

    /**
     * Инкремент счётчика срабатываний (аналитика «хвостов»).
     */
    public function recordHit(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->db->table($this->table)->where('id', $id)->increment('hits');
    }
}
