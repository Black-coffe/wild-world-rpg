<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\TaskModel;
use App\TaskHandlers\Contracts\TaskHandlerInterface;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class TaskController extends BaseAdminController
{
    protected TaskModel $taskModel;

    public function __construct()
    {
        $this->taskModel = new TaskModel();
    }

    /**
     * Phase B5 (ADR-023) — registered task-handler options для form info-панели.
     *
     * @return list<array{key: string, displayName: string, description: string}>
     */
    protected function taskHandlerOptions(): array
    {
        return Services::handlerRegistry()->optionsFor(TaskHandlerInterface::class);
    }

    /**
     * Phase B6 (ADR-023) — нормалізує input.handler_key для tasks. Same контракт
     * як у EventController::normalizeHandlerKey але проти TaskHandlerInterface.
     *
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    protected function normalizeHandlerKey(mixed $raw, array &$errors): ?string
    {
        $errors = [];
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $key = trim($raw);
        $entry = Services::handlerRegistry()->getByKey($key);
        if ($entry === null || !in_array(TaskHandlerInterface::class, $entry->interfaces, true)) {
            $errors[] = "handler_key '{$key}' не зарегистрирован для TaskHandlerInterface — оставлено NULL (legacy fallback).";
            return null;
        }
        return $key;
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
            'title'        => 'Создание новой задачи',
            'taskHandlers' => $this->taskHandlerOptions(),
        ]);
    }

    public function storeTask(): RedirectResponse
    {
        $data = (array) $this->request->getPost();

        $handlerKeyErrors = [];
        $data['handler_key'] = $this->normalizeHandlerKey($data['handler_key'] ?? null, $handlerKeyErrors);

        if (!$this->validate($this->taskModel->getValidationRules())) {
            return $this->redirectBackWithErrors($this->validator?->getErrors() ?? []);
        }
        if ($handlerKeyErrors !== []) {
            return $this->redirectBackWithErrors($handlerKeyErrors);
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
            'task'         => $task,
            'taskHandlers' => $this->taskHandlerOptions(),
            'title'        => 'Редактирование задачи: ' . (string) ($task['name'] ?? ''),
        ]);
    }

    public function updateTask(int|string $taskId): RedirectResponse|ResponseInterface
    {
        $data = (array) $this->request->getPost();

        $task = $this->taskModel->find($taskId);
        if ($task === null) {
            return $this->failNotFound('Задача не найдена.');
        }

        $handlerKeyErrors = [];
        $data['handler_key'] = $this->normalizeHandlerKey($data['handler_key'] ?? null, $handlerKeyErrors);

        if (!$this->validate($this->taskModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }
        if ($handlerKeyErrors !== []) {
            return $this->redirectBackWithErrors($handlerKeyErrors);
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
