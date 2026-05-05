<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
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
            session()->setFlashdata('success', 'Совет успешно удален.');
        } else {
            session()->setFlashdata('error', 'Не удалось удалить совет.');
        }

        return redirect()->to(site_url('admin/tips'));
    }

}
