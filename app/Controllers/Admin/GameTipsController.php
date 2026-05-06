<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\GameTipsModel;
use CodeIgniter\API\ResponseTrait;

class GameTipsController extends BaseController
{
    use ResponseTrait;

    protected $gameTipsModel;

    public function __construct()
    {
        $this->gameTipsModel = new GameTipsModel();
    }

    public function index()
    {
        $tips = $this->gameTipsModel->orderBy('created_at', 'DESC')->findAll();
        return view('admin/tips_index', [
            'tips' => $tips,
            'title' => 'Список всех советов'
        ]);
    }

    public function createTipForm()
    {
        return view('admin/tip_create', [
            'title' => 'Создание нового совета',
        ]);
    }

    public function storeTip()
    {
        $data = $this->request->getPost();

        if (!$this->validate($this->gameTipsModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator?->getErrors() ?? []);
        }

        $id = $this->gameTipsModel->insert($data);
        if ($id === false) {
            return redirect()->back()->withInput()->with('errors', $this->gameTipsModel->errors());
        }

        $this->auditAdminAction('TIP_CREATE', 'tip', (int) $id, [
            'title_ru' => $this->request->getPost('title_ru'),
        ]);

        session()->setFlashdata('success', 'Новый совет успешно добавлен.');
        return redirect()->to(site_url('admin/tips'));
    }

    public function editTipForm($tipId)
    {
        $tip = $this->gameTipsModel->find($tipId);
        if ($tip == null) {
            return $this->failNotFound('Совет не найден.');
        }

        return view('admin/tip_edit_form', [
            'tip' => $tip,
            'title' => 'Редактирование совета: ' . $tip['title_ru']
        ]);
    }

    public function updateTip($tipId)
    {
        $data = $this->request->getPost();
        $tip = $this->gameTipsModel->find($tipId);
        if ($tip == null) {
            return $this->failNotFound('Совет не найден.');
        }

        if (!$this->validate($this->gameTipsModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        $updated = $this->gameTipsModel->update($tipId, $data);
        if ($updated) {
            $this->auditAdminAction('TIP_UPDATE', 'tip', (int) $tipId, [
                'title_ru' => $this->request->getPost('title_ru'),
            ]);
            session()->setFlashdata('success', 'Совет успешно обновлен.');
            return redirect()->to(site_url('admin/tips'));
        } else {
            return $this->failServerError('Не удалось обновить совет.');
        }
    }

    public function deleteTip($tipId)
    {
        $tip = $this->gameTipsModel->find($tipId);
        if (!$tip) {
            session()->setFlashdata('error', 'Совет не найден.');
            return redirect()->to(site_url('admin/tips'));
        }

        if ($this->gameTipsModel->delete($tipId)) {
            $this->auditAdminAction('TIP_DELETE', 'tip', (int) $tipId, []);
            session()->setFlashdata('success', 'Совет успешно удален.');
        } else {
            session()->setFlashdata('error', 'Не удалось удалить совет.');
        }

        return redirect()->to(site_url('admin/tips'));
    }

    /**
     * F1.9 expansion (v0.51.11): єдина точка запису destructive admin actions.
     *
     * @param array<string, mixed> $payload
     */
    private function auditAdminAction(string $action, ?string $targetType, ?int $targetId, array $payload = []): void
    {
        $auth = service('auth');
        $adminUserId = is_object($auth) && method_exists($auth, 'user') ? (int) ($auth->user()->id ?? 0) : 0;
        (new AdminAuditLogModel())->record($adminUserId, $action, $targetType, $targetId, $payload);
    }
}
