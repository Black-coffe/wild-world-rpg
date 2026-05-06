<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\UserModel;
use CodeIgniter\API\ResponseTrait;

/**
 * F1.9 dashboard (v0.51.17) — read-only viewer для admin_audit_log table.
 *
 * Доступний admin-only через `/admin/audit-log`. Показує останні записи з фільтром
 * по action / target_type / admin user. Pagination через CI4 pager.
 *
 * Це supplements F1.9 expansion (v0.51.9-11) — раніше audit_log писався але було
 * нечим переглядати. Тепер admin може forensics destructive actions.
 */
class AuditLogController extends BaseController
{
    use ResponseTrait;

    private AdminAuditLogModel $auditModel;

    public function __construct()
    {
        $this->auditModel = new AdminAuditLogModel();
    }

    /**
     * GET /admin/audit-log — list з pagination + filters.
     */
    public function index(): string
    {
        $request = $this->request;

        // Optional filters
        $filterAction = $request->getGet('action');
        $filterTarget = $request->getGet('target_type');
        $filterAdmin  = $request->getGet('admin_user_id');

        $builder = $this->auditModel->orderBy('id', 'DESC');

        if (is_string($filterAction) && $filterAction !== '') {
            $builder = $builder->where('action', $filterAction);
        }
        if (is_string($filterTarget) && $filterTarget !== '') {
            $builder = $builder->where('target_type', $filterTarget);
        }
        if (is_numeric($filterAdmin) && (int) $filterAdmin > 0) {
            $builder = $builder->where('admin_user_id', (int) $filterAdmin);
        }

        $perPage = 50;
        $entries = $builder->paginate($perPage, 'audit');
        $pager   = $this->auditModel->pager;

        // Bulk-load admin user names (avoid N+1)
        $adminIds = array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['admin_user_id'] ?? 0),
            $entries ?? []
        )));

        $adminNames = [];
        if (!empty($adminIds)) {
            $userModel = new UserModel();
            $users = $userModel->whereIn('id', $adminIds)->findAll();
            foreach ($users as $u) {
                // UserModel returnType = App\Entities\User. Доступ через property або array-cast.
                $userArr = is_object($u) && method_exists($u, 'toRawArray') ? $u->toRawArray() : (array) $u;
                $adminNames[(int) $userArr['id']] = (string) ($userArr['name'] ?? "user-{$userArr['id']}");
            }
        }

        // Distinct values for filter dropdowns (raw queries via DB connection)
        $db = \Config\Database::connect();
        $actionRows = $db->query('SELECT DISTINCT action FROM admin_audit_log WHERE action IS NOT NULL ORDER BY action ASC')->getResultArray();
        $targetRows = $db->query('SELECT DISTINCT target_type FROM admin_audit_log WHERE target_type IS NOT NULL ORDER BY target_type ASC')->getResultArray();
        $distinctActions = array_filter(array_column($actionRows, 'action'));
        $distinctTargets = array_filter(array_column($targetRows, 'target_type'));

        return view('admin/audit_log_index', [
            'title'           => 'Журнал admin-действий',
            'entries'         => $entries ?? [],
            'pager'           => $pager,
            'adminNames'      => $adminNames,
            'distinctActions' => array_filter($distinctActions),
            'distinctTargets' => array_filter($distinctTargets),
            'filterAction'    => $filterAction,
            'filterTarget'    => $filterTarget,
            'filterAdmin'     => $filterAdmin,
        ]);
    }
}
