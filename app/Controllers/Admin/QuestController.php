<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\QuestModel;

class QuestController extends BaseController
{
    protected $questModel;

    public function __construct()
    {
        $this->questModel = new QuestModel();
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

    public function index()
    {
        $quests = $this->questModel->orderBy('id', 'DESC')->findAll();

        return view('admin/quest_index', [
            'quests' => $quests,
            'title' => 'Список квестов'
        ]);
    }

    public function createQuestForm()
    {
        return view('admin/quest_create', [
            'title' => 'Создание нового квеста'
        ]);
    }

    public function storeQuest()
    {
        $data = $this->request->getPost();

        if (!$this->validate($this->questModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator?->getErrors() ?? []);
        }

        $id = $this->questModel->insert($data);
        if ($id === false) {
            return redirect()->back()->withInput()->with('errors', $this->questModel->errors());
        }

        $this->auditAdminAction('QUEST_CREATE', 'quest', (int) $id, [
            'title_ru' => $this->request->getPost('title_ru'),
            'reward'   => $this->request->getPost('reward'),
            'is_active' => $this->request->getPost('is_active'),
        ]);

        session()->setFlashdata('success', 'Новый квест успешно добавлен.');
        return redirect()->to(site_url('admin/quests'));
    }

    public function editQuestForm($questId)
    {
        $quest = $this->questModel->find($questId);

        if ($quest === null) {
            return redirect()->back()->with('error', 'Квест не найден.');
        }

        return view('admin/quest_edit_form', [
            'quest' => $quest,
            'title' => 'Редактирование квеста: ' . $quest['title_ru']
        ]);
    }

    public function updateQuest($questId)
    {
        $data = $this->request->getPost();

        if (!$this->validate($this->questModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator?->getErrors() ?? []);
        }

        $result = $this->questModel->update($questId, $data);

        if ($result === false) {
            return redirect()->back()->withInput()->with('error', 'Не удалось обновить квест.');
        }

        $this->auditAdminAction('QUEST_UPDATE', 'quest', (int) $questId, [
            'title_ru' => $this->request->getPost('title_ru'),
            'reward'   => $this->request->getPost('reward'),
            'is_active' => $this->request->getPost('is_active'),
        ]);

        session()->setFlashdata('success', 'Квест успешно обновлен.');
        return redirect()->to(site_url('admin/quests'));
    }

    public function deleteQuest($questId)
    {
        $result = $this->questModel->delete($questId);

        if (!$result) {
            return redirect()->back()->with('error', 'Не удалось удалить квест.');
        }

        $this->auditAdminAction('QUEST_DELETE', 'quest', (int) $questId, []);

        session()->setFlashdata('success', 'Квест успешно удален.');
        return redirect()->to(site_url('admin/quests'));
    }
}
