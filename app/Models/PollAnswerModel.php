<?php
namespace App\Models;

use CodeIgniter\Model;

class PollAnswerModel extends Model
{
    protected $table = 'poll_answers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // Разрешённые поля: ID опроса и текст варианта ответа
    protected $allowedFields = ['poll_id', 'answer_text'];

    protected $useTimestamps = false;
}
