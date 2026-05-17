<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * S5 (v0.51.187) — модель таблицы `game_settings` (ADR-024).
 *
 * Foundation для admin-tunable balance framework. Хранит key/value
 * параметры с rich rationale (rationale_text/effect_text/above/below) +
 * бandz (recommended_min/max + hard_min/max). См. CLAUDE.md §🎛️ —
 * constitutional rule: каждый balance param обязан жить здесь, а не в
 * hardcoded числах.
 *
 * returnType=array (а не Entity) — это простой KV-хранитель без бизнес-логики
 * (бизнес-логика — в `GameSettingsService` с кешем).
 *
 * @phpstan-type SettingRow array{
 *     id: int,
 *     setting_key: string,
 *     category: string,
 *     value_type: 'int'|'float'|'bool'|'string',
 *     value_int: int|null,
 *     value_float: numeric-string|null,
 *     value_bool: int|null,
 *     value_string: string|null,
 *     default_value_text: string,
 *     rationale_text: string,
 *     effect_text: string,
 *     above_effect_text: string,
 *     below_effect_text: string,
 *     recommended_min: string|null,
 *     recommended_max: string|null,
 *     hard_min: string|null,
 *     hard_max: string|null,
 *     updated_by: string|null,
 *     created_at: string,
 *     updated_at: string,
 * }
 */
class GameSettingsModel extends Model
{
    protected $table            = 'game_settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'setting_key',
        'category',
        'value_type',
        'value_int',
        'value_float',
        'value_bool',
        'value_string',
        'default_value_text',
        'rationale_text',
        'effect_text',
        'above_effect_text',
        'below_effect_text',
        'recommended_min',
        'recommended_max',
        'hard_min',
        'hard_max',
        'updated_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        $row = $this->where('setting_key', $key)->first();
        if (! is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($row as $k => $v) {
            $normalized[(string) $k] = $v;
        }
        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByCategory(string $category): array
    {
        $rows = $this->where('category', $category)->orderBy('setting_key', 'ASC')->findAll();
        $out  = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized = [];
                foreach ($row as $k => $v) {
                    $normalized[(string) $k] = $v;
                }
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function listCategories(): array
    {
        $result = $this->builder()
            ->select('category')
            ->distinct()
            ->orderBy('category', 'ASC')
            ->get();

        if ($result === false) {
            return [];
        }

        $rows = $result->getResultArray();
        $out  = [];
        foreach ($rows as $row) {
            $cat = $row['category'] ?? null;
            if (is_string($cat) && $cat !== '') {
                $out[] = $cat;
            }
        }
        return $out;
    }
}
