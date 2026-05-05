<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

class ResourceController extends BaseController
{
    use ResponseTrait;

    protected $resourceModel;
    protected $biomeModel;

    public function __construct()
    {
        $this->resourceModel = new ResourceModel();
        $this->biomeModel = new BiomeModel();
    }

    // Метод для отображения списка всех ресурсов
    public function index()
    {
        // Получаем все ресурсы из модели с сортировкой по дате создания (от новых к старым)
        $resources = $this->resourceModel->orderBy('created_at', 'DESC')->findAll();


        // Подготовка данных для отображения
        foreach ($resources as &$resource) {
            // Преобразование ID биомов в имена
            if (!empty($resource['biome_id'])) {
                // Преобразуем строку с ID биомов в массив
                $biomeIds = explode(',', $resource['biome_id']);
                // Получаем имена биомов по их ID
                $resource['biome_names'] = $this->getBiomeNamesByIds($biomeIds);
            } else {
                $resource['biome_names'] = 'Не привязан к биому';
            }

            // Перевод типов ресурсов
            $resource['translated_types'] = $this->translateResourceTypes($resource['type']);
        }
        unset($resource); // Отсекаем ссылку на последний элемент массива

        // Отправляем данные о ресурсах в представление
        return view('admin/resource_index', [
            'resources' => $resources,
            'title' => 'Список всех Ресурсов в игре'
        ]);
    }


    // Метод для отображения формы создания ресурса
    public function createResourceForm()
    {
        $biomes = $this->biomeModel->findAll();
        // Отправляем данные о ресурсах в представление
        return view('admin/resource_create', [
            'title' => 'Создание нового игрового ресурса',
            'biomes' => $biomes,
        ]);
    }

    public function storeResource()
    {
        $data = $this->request->getPost();

        // Обработка множественных значений для biome_id и type
        if (isset($data['biome_id'])) {
            $data['biome_id'] = implode(',', $data['biome_id']); // Преобразование массива в строку
        }
        if (isset($data['type'])) {
            $data['type'] = implode(',', $data['type']); // Аналогично
        }

        // Проводим валидацию данных
        if (!$this->validate($this->resourceModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator?->getErrors() ?? []);
        }

        // Сохраняем ресурс
        $id = $this->resourceModel->insert($data);

        if ($id === false) {
            return redirect()->back()->withInput()->with('errors', $this->resourceModel->errors());
        }

        // Успешно сохранено, устанавливаем флеш-сообщение и перенаправляем на страницу списка ресурсов
        session()->setFlashdata('success', 'Новый ресурс успешно добавлен.');
        return redirect()->to(site_url('admin/resources'));
    }


    // Метод для отображения формы редактирования ресурса
    public function editResourceForm($resourceId)
    {
        // Получаем данные о ресурсе из модели
        $resource = $this->resourceModel->find($resourceId);
        $biomes = $this->biomeModel->findAll();

        if ($resource == null) {
            return $this->failNotFound('Ресурс не найден.');
        }

        // Отправляем данные о ресурсе в представление
        return view('admin/resource_edit_form', [
            'resource' => $resource,
            'biomes' => $biomes,
            'title' => 'Редактирование ресурса: ' . $resource['name']
        ]);
    }

    // Метод для обновления информации о ресурсе
    public function updateResource($resourceId)
    {
        // Получаем данные из POST-запроса
        $data = $this->request->getPost();

        // Аналогичная обработка множественных значений
        if (isset($data['biome_id'])) {
            $data['biome_id'] = implode(',', $data['biome_id']);
        }
        if (isset($data['type'])) {
            $data['type'] = implode(',', $data['type']);
        }

        // Проверяем существование ресурса
        $resource = $this->resourceModel->find($resourceId);
        if ($resource == null) {
            return $this->failNotFound('Ресурс не найден.');
        }

        // Проводим валидацию данных
        if (!$this->validate($this->resourceModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator?->getErrors() ?? []);
        }

        // Обновляем информацию о ресурсе
        $updated = $this->resourceModel->update($resourceId, $data);

        if ($updated) {
            // Успешно обновлено, устанавливаем флеш-сообщение
            session()->setFlashdata('success', 'Ресурс успешно обновлен.');
            // Перенаправляем пользователя на страницу всех ресурсов
            return redirect()->to(site_url('admin/resources'));
        } else {
            return $this->failServerError('Не удалось обновить ресурс.');
        }
    }

    /**
     * Возвращает строку с именами биомов, разделенными запятой, по их ID.
     *
     * @param array $biomeIds Массив ID биомов.
     * @return string Строка с именами биомов.
     */
    protected function getBiomeNamesByIds(array $biomeIds): string
    {
        $biomes = $this->biomeModel->find($biomeIds);

        $biomeNames = array_map(function ($biome) {
            return $biome['name'];
        }, $biomes);

        return implode(', ', $biomeNames);
    }

    /**
     * Возвращает строку с переводом типов ресурсов, разделенных запятой.
     *
     * @param string $types Строка с типами ресурсов, разделенными запятой.
     * @return string Строка с переводами типов.
     */
    protected function translateResourceTypes(string $types): string
    {
        $typeList = explode(',', $types);
        $translatedTypes = [];

        foreach ($typeList as $type) {
            // Перевод типов ресурсов. Замените или дополните ключи и значения в соответствии с вашими данными.
            switch ($type) {
                case 'crafting':
                    $translatedTypes[] = 'Крафтовые';
                    break;
                case 'food':
                    $translatedTypes[] = 'Еда';
                    break;
                case 'water':
                    $translatedTypes[] = 'Вода';
                    break;
                case 'material':
                    $translatedTypes[] = 'Материалы';
                    break;
                // Добавьте другие типы по необходимости.
            }
        }

        return implode(', ', $translatedTypes);
    }


}
