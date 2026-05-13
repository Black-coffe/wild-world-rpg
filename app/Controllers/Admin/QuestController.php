<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\QuestModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class QuestController extends BaseAdminController
{
    protected QuestModel $questModel;

    public function __construct()
    {
        $this->questModel = new QuestModel();
    }

    public function index(): string
    {
        $quests = $this->questModel->orderBy('id', 'DESC')->findAll();

        return view('admin/quest_index', [
            'quests' => $quests,
            'title'  => 'Список квестов',
        ]);
    }

    public function createQuestForm(): string
    {
        return view('admin/quest_create', [
            'title' => 'Создание нового квеста',
        ]);
    }

    public function storeQuest(): RedirectResponse
    {
        $data = (array) $this->request->getPost();

        if (!$this->validate($this->questModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }

        $id = $this->questModel->insert($data);
        if ($id === false) {
            return $this->redirectBackWithErrors($this->questModel->errors());
        }

        $this->audit('QUEST_CREATE', 'quest', (int) $id, [
            'title_ru'  => $this->request->getPost('title_ru'),
            'reward'    => $this->request->getPost('reward'),
            'is_active' => $this->request->getPost('is_active'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/quests'), 'Новый квест успешно добавлен.');
    }

    public function editQuestForm(int|string $questId): string|RedirectResponse
    {
        $questRaw = $this->questModel->find($questId);

        if ($questRaw === null) {
            return $this->redirectBackWithError('Квест не найден.');
        }
        $quest = (array) $questRaw;

        return view('admin/quest_edit_form', [
            'quest' => $quest,
            'title' => 'Редактирование квеста: ' . (string) ($quest['title_ru'] ?? ''),
        ]);
    }

    public function updateQuest(int|string $questId): RedirectResponse
    {
        $data = (array) $this->request->getPost();

        if (!$this->validate($this->questModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }

        $result = $this->questModel->update($questId, $data);

        if ($result === false) {
            return redirect()->back()->withInput()->with('error', 'Не удалось обновить квест.');
        }

        $this->audit('QUEST_UPDATE', 'quest', (int) $questId, [
            'title_ru'  => $this->request->getPost('title_ru'),
            'reward'    => $this->request->getPost('reward'),
            'is_active' => $this->request->getPost('is_active'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/quests'), 'Квест успешно обновлен.');
    }

    public function deleteQuest(int|string $questId): RedirectResponse
    {
        $result = $this->questModel->delete($questId);

        if (!$result) {
            return $this->redirectBackWithError('Не удалось удалить квест.');
        }

        $this->audit('QUEST_DELETE', 'quest', (int) $questId, []);

        return $this->redirectWithSuccess(site_url('admin/quests'), 'Квест успешно удален.');
    }
}
