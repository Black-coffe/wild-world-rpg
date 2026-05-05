<?php namespace App\Models;

use CodeIgniter\Model;

class TelegramUserModel extends Model
{
    protected $table = 'telegram_users';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType = 'array';

    /**
     * Добавляем два новых поля:
     *  - last_map_message_id
     *  - last_map_message_created_at
     */
    protected $allowedFields = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'last_map_message_id',
        'last_map_message_created_at',
        'event_pref',  // F7.5 — JSON для NotificationPolicy (throttle/mute prefs)
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Получает ID персонажа по ID Telegram пользователя.
     *
     * @param int $telegramId ID пользователя в Telegram.
     * @return int|null Возвращает ID персонажа или null, если персонаж не найден.
     */
    public function getCharacterIdByTelegramId(int $telegramId): ?int
    {
        $db = db_connect();
        $builder = $db->table('characters');
        $builder->select('characters.id');
        $builder->join('telegram_users', 'telegram_users.id = characters.telegram_user_id', 'inner');
        $builder->where('telegram_users.telegram_id', $telegramId);
        $query = $builder->get();
        if ($query === false) {
            return null;
        }

        if ($row = $query->getRowArray()) {
            return (int) $row['id'];
        } else {
            return null;
        }
    }
}
