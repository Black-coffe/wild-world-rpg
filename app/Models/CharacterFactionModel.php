<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterFactionModel extends Model
{
    protected $table = 'character_factions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'character_id',
        'faction_id',
        'joined_at',
        'notified_at',
        'notification_count',
        'notification_status'
    ];

    protected $useTimestamps = false;

    protected $validationRules = [
        'character_id'       => 'required|integer',
        'faction_id'         => 'required|integer',
        'joined_at'          => 'permit_empty|valid_date',
        'notified_at'        => 'permit_empty|valid_date',
        'notification_count' => 'required|integer',
        'notification_status'=> 'required|in_list[True,False]'
    ];

    protected $validationMessages = [
        'character_id' => [
            'required' => 'Поле Идентификатор персонажа обязательно для заполнения.',
            'integer'  => 'Идентификатор персонажа должен быть числом.'
        ],
        'faction_id' => [
            'required' => 'Поле Идентификатор фракции обязательно для заполнения.',
            'integer'  => 'Идентификатор фракции должен быть числом.'
        ],
        'joined_at' => [
            'valid_date' => 'Поле Дата присоединения должно быть корректной датой.'
        ],
        'notified_at' => [
            'valid_date' => 'Поле Дата уведомления должно быть корректной датой.'
        ],
        'notification_count' => [
            'required' => 'Поле Количество уведомлений обязательно для заполнения.',
            'integer'  => 'Количество уведомлений должно быть числом.'
        ],
        'notification_status' => [
            'required' => 'Поле Статус уведомления обязательно для заполнения.',
            'in_list'  => 'Статус уведомления должен быть либо True, либо False.'
        ],
    ];

    protected $skipValidation = false;

    /**
     * W15 (ADR-069) — faction_id персонажа (0 если фракция не выбрана / Нейтрал).
     * Raw db-builder — без model-builder-state quirk.
     */
    public function getFactionId(int $characterId): int
    {
        if ($characterId <= 0) {
            return 0;
        }
        $row = $this->db->table('character_factions')
            ->select('faction_id')
            ->where('character_id', $characterId)
            ->get();
        $arr = $row === false ? null : $row->getRowArray();
        return is_array($arr) && is_numeric($arr['faction_id'] ?? null) ? (int) $arr['faction_id'] : 0;
    }

    /**
     * Логирование уведомления о необходимости выбора фракции.
     *
     * @param int $characterId Идентификатор персонажа.
     * @return bool Успешность операции.
     */
    public function logNotification(int $characterId): bool
    {
        if (empty($characterId)) {
            return false;
        }

        $notification = $this->where('character_id', $characterId)->first();

        if ($notification) {
            $data = [
                'notified_at'        => date('Y-m-d H:i:s'),
                'notification_count' => $notification['notification_count'] + 1,
                'notification_status'=> 'True'
            ];
            return $this->update($notification['id'], $data);
        } else {
            $data = [
                'character_id'       => $characterId,
                'notified_at'        => date('Y-m-d H:i:s'),
                'notification_count' => 1,
                'notification_status'=> 'True'
            ];
            return $this->insert($data);
        }
    }
}
