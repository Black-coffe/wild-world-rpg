<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BiomeModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

class BiomeController extends BaseController
{
    use ResponseTrait;

    protected $biomeModel;

    public function __construct()
    {
        $this->biomeModel = new BiomeModel();
    }

    // Метод для отображения списка всех биомов
    public function index()
    {
        // Получаем все биомы из модели
        $biomes = $this->biomeModel->findAll();

        // Отправляем данные о биомах в представление
        return view('admin/biome_index', [
            'biomes' => $biomes,
            'title' => 'Список всех Биомов в игре'
        ]);
    }


    // Метод для отображения формы редактирования биома
    public function editBiomeForm($biomeId)
    {
        // Получаем данные о биоме из модели
        $biome = $this->biomeModel->find($biomeId);

        if ($biome == null) {
            return $this->failNotFound('Биом не найден.');
        }

        // Отправляем данные о биоме в представление
        return view('admin/biome_edit_form', [
            'biome' => $biome,
            'title' => 'Редактирование биома: '.$biome['name']
        ]);
    }

    // Метод для обновления информации о биоме
    public function updateBiome($biomeId)
    {
        // Получаем данные из POST-запроса
        $data = $this->request->getPost();

        // Проверяем существование биома
        $biome = $this->biomeModel->find($biomeId);
        if ($biome == null) {
            return $this->failNotFound('Биом не найден.');
        }

        // Проводим валидацию данных
        if (!$this->validate($this->biomeModel->getValidationRules())) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        // Обновляем информацию о биоме
        $updated = $this->biomeModel->update($biomeId, $data);

        if ($updated) {
            // Успешно обновлено, устанавливаем флеш-сообщение
            session()->setFlashdata('success', 'Биом успешно обновлен.');
            // Перенаправляем пользователя на страницу всех биомов
            return redirect()->to(site_url('admin/biomes'));
        } else {
            return $this->failServerError('Не удалось обновить биом.');
        }
    }

}
