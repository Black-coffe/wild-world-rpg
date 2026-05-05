<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TaskModel;
use CodeIgniter\API\ResponseTrait;
use App\Libraries\MyRules;

class TaskController extends BaseController
{
    use ResponseTrait;

    protected $taskModel;

    public function __construct()
    {
        $this->taskModel = new TaskModel();
    }

    public function index()
    {
        // Получаем все задачи из модели с сортировкой по дате создания (от новых к старым)
        $tasks = $this->taskModel->orderBy('created_at', 'DESC')->findAll();

        // Отправляем данные о задачах в представление
        return view('admin/task_index', [
            'tasks' => $tasks,
            'title' => 'Список всех задач'
        ]);
    }

    public function createTaskForm()
    {
        // Отправляем данные для формы создания задачи
        return view('admin/task_create', [
            'title' => 'Создание новой задачи',
        ]);
    }

    public function storeTask()
    {
        $data = $this->request->getPost();

        // Проводим валидацию данных
        if (!$this->validate($this->taskModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator?->getErrors() ?? []);
        }

        // Сохраняем задачу
        $id = $this->taskModel->insert($data);

        if ($id === false) {
            return redirect()->back()->withInput()->with('errors', $this->taskModel->errors());
        }

        // Успешно сохранено, устанавливаем флеш-сообщение и перенаправляем на страницу списка задач
        session()->setFlashdata('success', 'Новая задача успешно добавлена.');
        return redirect()->to(site_url('admin/tasks'));
    }

    public function editTaskForm($taskId)
    {
        // Получаем данные о задаче из модели
        $task = $this->taskModel->find($taskId);

        if ($task == null) {
            return $this->failNotFound('Задача не найдена.');
        }

        // Отправляем данные о задаче в представление для редактирования
        return view('admin/task_edit_form', [
            'task' => $task,
            'title' => 'Редактирование задачи: ' . $task['name']
        ]);
    }

    public function updateTask($taskId)
    {
        $data = $this->request->getPost();

        // Проверяем существование задачи
        $task = $this->taskModel->find($taskId);
        if ($task == null) {
            return $this->failNotFound('Задача не найдена.');
        }

        // Проводим валидацию данных
        if (!$this->validate($this->taskModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        // Обновляем информацию о задаче
        $updated = $this->taskModel->update($taskId, $data);

        if ($updated) {
            // Успешно обновлено, устанавливаем флеш-сообщение
            session()->setFlashdata('success', 'Задача успешно обновлена.');
            // Перенаправляем пользователя на страницу списка задач
            return redirect()->to(site_url('admin/tasks'));
        } else {
            return $this->failServerError('Не удалось обновить задачу.');
        }
    }
}
