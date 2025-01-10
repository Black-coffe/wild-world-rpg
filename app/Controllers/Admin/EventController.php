<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\EventModel;
use App\Models\BiomeModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

class EventController extends BaseController
{
    use ResponseTrait;

    protected $eventModel;
    protected $biomeModel;

    public function __construct()
    {
        $this->eventModel = new EventModel();
        $this->biomeModel = new BiomeModel();
    }

    // Метод для отображения списка всех событий
    public function index()
    {
        $events = $this->eventModel->findAll();

        return view('admin/event_index', [
            'events' => $events,
            'title' => 'Список всех Событий в игре'
        ]);
    }

    // Метод для отображения формы редактирования события
    public function editEventForm($eventId)
    {
        $event = $this->eventModel->find($eventId);

        if ($event == null) {
            return $this->failNotFound('Событие не найдено.');
        }

        // Загрузка данных о биомах
        $biomes = $this->biomeModel->findAll(); // Убедитесь, что у вас есть модель для биомов и она загружена в контроллере

        return view('admin/event_edit_form', [
            'event' => $event,
            'biomes' => $biomes, // Передача данных о биомах в представление
            'title' => 'Редактирование события: ' . $event['name']
        ]);
    }


    // Метод для обновления информации о событии
    public function updateEvent($eventId)
    {
        $data = $this->request->getPost();

        // Преобразование biome_ids в JSON, как в методе storeEvent
        if (isset($data['biome_ids']) && is_array($data['biome_ids'])) {
            $data['biome_ids'] = json_encode($data['biome_ids']);
        } else {
            $data['biome_ids'] = json_encode([]);
        }

        // Преобразование random_coverage в 1 или 0
        $data['random_coverage'] = isset($data['random_coverage']) && $data['random_coverage'] === 'on' ? 1 : 0;

        // Валидация данных, используя правила, определенные в модели EventModel
        if (!$this->validate($this->eventModel->getValidationRules(), $this->eventModel->getValidationMessages())) {
            // Если данные не проходят валидацию, возвращаем пользователя обратно на форму с ошибками валидации
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        // Проверяем, существует ли событие, которое мы хотим обновить
        $existingEvent = $this->eventModel->find($eventId);
        if ($existingEvent === null) {
            return $this->failNotFound('Событие не найдено.');
        }

        // Обновление события
        $updated = $this->eventModel->update($eventId, $data);

        if ($updated) {
            // Если событие успешно обновлено, устанавливаем флеш-сообщение и перенаправляем на список всех событий
            session()->setFlashdata('success', 'Событие успешно обновлено.');
            return redirect()->to(site_url('admin/events'));
        } else {
            // Если произошла ошибка при обновлении, возвращаем ошибку сервера
            return $this->failServerError('Не удалось обновить событие.');
        }
    }

// Метод для отображения формы создания нового события
    public function createEventForm()
    {
        $bioms =  $this->biomeModel->findAll();
        return view('admin/event_create_form', [
            'title' => 'Создание нового события',
            'bioms' => $bioms
        ]);
    }

// Метод для валидации данных и сохранения нового события
    public function storeEvent()
    {
        // Получение данных POST-запроса
        $data = $this->request->getPost();

        // Преобразование biome_ids в JSON
        $data['biome_ids'] = json_encode($data['biome_ids']);

        // Преобразование random_coverage
        $data['random_coverage'] = isset($data['random_coverage']) ? 1 : 0;

        // Валидация данных
        if (!$this->validate($this->eventModel->getValidationRules(), $this->eventModel->getValidationMessages())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        // Сохранение данных
        $saved = $this->eventModel->save($data);

        if ($saved) {
            session()->setFlashdata('success', 'Новое событие успешно создано.');
            return redirect()->to(site_url('admin/events'));
        } else {
            return $this->failServerError('Не удалось создать событие.');
        }
    }
}
