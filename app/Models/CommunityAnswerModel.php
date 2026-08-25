<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-176 «Community chat bot» — банк утверждённых ответов + черновики.
 *
 * Одна строка = один ответ (KEEP в Config\WipeManifest — авторский корпус наравне
 * с game_tips). UNIQUE(client_key) — идемпотентность повторного `community:import`
 * (миграция 2026-08-25-100100_Adr176CreateCommunityAnswersTable). Новая строка без
 * явного статуса получает 'draft'.
 */
class CommunityAnswerModel extends Model
{
    protected $table         = 'community_answers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /** @var list<string> */
    protected $allowedFields = [
        'client_key',
        'question_pattern',
        'answer_text',
        'requires_setting',
        'source_ref',
        'status',
        'approved_at',
        'approved_by',
        'revoked_at',
    ];
}
