<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * F1.9 — пишет destructive админские действия.
 *
 * Используй через единственный метод `record()`, чтобы не дублировать
 * логику IP/UA/admin_user_id извлечения.
 */
class AdminAuditLogModel extends Model
{
    protected $table         = 'admin_audit_log';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';

    protected $allowedFields = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'payload',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $useTimestamps = false; // ставим created_at вручную (нет updated_at)
    protected $dateFormat    = 'datetime';

    /**
     * Записать админское действие.
     *
     * @param int         $adminUserId  Текущий users.id (из service('auth')).
     * @param string      $action       Машиночитаемый код, например CHARACTER_RESET.
     * @param string|null $targetType   Тип цели (character/poll/...).
     * @param int|null    $targetId     ID цели.
     * @param array       $payload      Дополнительная деталировка (станет JSON).
     */
    public function record(
        int $adminUserId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $payload = []
    ): bool {
        $request = service('request');

        $row = [
            'admin_user_id' => $adminUserId,
            'action'        => $action,
            'target_type'   => $targetType,
            'target_id'     => $targetId,
            'payload'       => empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ip_address'    => is_object($request) && method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent'    => is_object($request) && method_exists($request, 'getUserAgent') ? (string) $request->getUserAgent() : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        try {
            return (bool) $this->insert($row);
        } catch (\Throwable $e) {
            // НИКОГДА не ронять основной запрос из-за audit-лога.
            log_message('error', '[AdminAuditLog] insert failed: ' . $e->getMessage());
            return false;
        }
    }
}
