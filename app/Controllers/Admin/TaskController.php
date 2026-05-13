<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\TaskModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class TaskController extends BaseAdminController
{
    protected TaskModel $taskModel;

    public function __construct()
    {
        $this->taskModel = new TaskModel();
    }

    public function index(): string
    {
        $tasks = $this->taskModel->orderBy('created_at', 'DESC')->findAll();

        return view('admin/task_index', [
            'tasks' => $tasks,
            'title' => 'Список всех задач',
        ]);
    }

    public function createTaskForm(): string
    {
        return view('admin/task_create', [
            'title' => 'Создание новой задачи',
        ]);
    }

    public function storeTask(): RedirectResponse
    {
        $data = (array) $this->request->getPost();

        if (!$this->validate($this->taskModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }

        $id = $this->taskModel->insert($data);

        if ($id === false) {
            return $this->redirectBackWithErrors($this->taskModel->errors());
        }

        $this->audit('TASK_CREATE', 'task', (int) $id, [
            'name'             => $this->request->getPost('name'),
            'duration'         => $this->request->getPost('duration'),
            'reward_resources' => $this->request->getPost('reward_resources'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/tasks'), 'Новая задача успешно добавлена.');
    }

    public function editTaskForm(int|string $taskId): string|ResponseInterface
    {
        $task = $this->taskModel->find($taskId);

        if ($task === null) {
            return $this->failNotFound('Задача не найдена.');
        }
        $task = (array) $task;

        return view('admin/task_edit_form', [
            'task'  => $task,
            'title' => 'Редактирование задачи: ' . (string) ($task['name'] ?? ''),
        ]);
    }

    public function updateTask(int|string $taskId): RedirectResponse|ResponseInterface
    {
        $data = (array) $this->request->getPost();

        $task = $this->taskModel->find($taskId);
        if ($task === null) {
            return $this->failNotFound('Задача не найдена.');
        }

        if (!$this->validate($this->taskModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        $updated = $this->taskModel->update($taskId, $data);

        if (!$updated) {
            return $this->failServerError('Не удалось обновить задачу.');
        }

        $this->audit('TASK_UPDATE', 'task', (int) $taskId, [
            'name'             => $this->request->getPost('name'),
            'duration'         => $this->request->getPost('duration'),
            'reward_resources' => $this->request->getPost('reward_resources'),
        ]);

        return $this->redirectWithSuccess(site_url('admin/tasks'), 'Задача успешно обновлена.');
    }
}
