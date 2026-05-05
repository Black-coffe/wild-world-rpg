<?php namespace App\Models;

use CodeIgniter\Model;

class PollVoteModel extends Model
{
    protected $table = 'poll_votes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // Разрешённые поля для записи голоса:
    // poll_id - опрос, telegram_user_id - ссылка на пользователя (из таблицы telegram_users),
    // answer_id - выбранный вариант, voted_at - время голосования.
    protected $allowedFields = ['poll_id', 'telegram_user_id', 'answer_id', 'voted_at'];

    protected $useTimestamps = false;

    /**
     * Получает статистику голосования по опросу.
     *
     * @param int $pollId
     * @return array
     */
    public function getStatistics(int $pollId): array
    {
        $db = \Config\Database::connect();
        $builder = $db->table('poll_answers as pa');
        $builder->select('pa.answer_text, COUNT(pv.id) as votes');
        $builder->join('poll_votes as pv', 'pv.answer_id = pa.id', 'left');
        $builder->where('pa.poll_id', $pollId);
        $builder->groupBy('pa.id');
        $query = $builder->get();
        if ($query === false) {
            return [];
        }
        return $query->getResultArray();
    }
}
