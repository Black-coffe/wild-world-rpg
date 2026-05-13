<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\GameTipsModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class GameTipsController extends BaseAdminController
{
    protected GameTipsModel $gameTipsModel;

    public function __construct()
    {
        $this->gameTipsModel = new GameTipsModel();
    }

    public function index(): string
    {
        $tips = $this->gameTipsModel->orderBy('created_at', 'DESC')->findAll();

        return view('admin/tips_index', [
            'tips'  => $tips,
            'title' => 'Список всех советов',
        ]);
    }

    public function createTipForm(): string
    {
        return view('admin/tip_create', [
            'title' => 'Создание нового совета',
        ]);
    }

    public function storeTip(): RedirectResponse
    {
        $data = (array) $this->request->getPost();

        if (!$this->validate($this->gameTipsModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }

        $id = $this->gameTipsModel->insert($data);
        if ($id === false) {
            return $this->redirectBackWithErrors($this->gameTipsModel->errors());
        }

        $this->audit('TIP_CREATE', 'tip', (int) $id, [
            'title_ru' => $this->request->getPost('title_ru'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/tips'), 'Новый совет успешно добавлен.');
    }

    public function editTipForm(int|string $tipId): string|ResponseInterface
    {
        $tip = $this->gameTipsModel->find($tipId);
        if ($tip === null) {
            return $this->failNotFound('Совет не найден.');
        }
        $tip = (array) $tip;

        return view('admin/tip_edit_form', [
            'tip'   => $tip,
            'title' => 'Редактирование совета: ' . (string) ($tip['title_ru'] ?? ''),
        ]);
    }

    public function updateTip(int|string $tipId): RedirectResponse|ResponseInterface
    {
        $data = (array) $this->request->getPost();

        $tip = $this->gameTipsModel->find($tipId);
        if ($tip === null) {
            return $this->failNotFound('Совет не найден.');
        }

        if (!$this->validate($this->gameTipsModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        $updated = $this->gameTipsModel->update($tipId, $data);
        if (!$updated) {
            return $this->failServerError('Не удалось обновить совет.');
        }

        $this->audit('TIP_UPDATE', 'tip', (int) $tipId, [
            'title_ru' => $this->request->getPost('title_ru'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/tips'), 'Совет успешно обновлен.');
    }

    public function deleteTip(int|string $tipId): RedirectResponse
    {
        $tip = $this->gameTipsModel->find($tipId);
        if ($tip === null) {
            session()->setFlashdata('error', 'Совет не найден.');
            return redirect()->to(site_url('admin/tips'));
        }

        if ($this->gameTipsModel->delete($tipId)) {
            $this->audit('TIP_DELETE', 'tip', (int) $tipId, []);
            session()->setFlashdata('success', 'Совет успешно удален.');
        } else {
            session()->setFlashdata('error', 'Не удалось удалить совет.');
        }

        return redirect()->to(site_url('admin/tips'));
    }
}
