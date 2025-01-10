<?php

namespace App\Controllers;

use App\Models\UserModel;

class UserController extends BaseController
{
    protected $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new UserModel();
    }

    public function index()
    {
        $users = $this->model->findAll();

        // Здесь вы можете отобразить пользователей с помощью вашего представления
        return view('users/index', [
            'users' => $users,
            'title' => 'Все зарегистрированные пользователи',
            ]);
    }

    public function edit($id)
    {
        $user = $this->model->find($id);

        // Отображаем форму редактирования пользователя
        return view('users/edit', [
            'user' => $user,
            'title' => 'Редактирование пользователя: '. $user->name,
        ]);
    }

    public function update($id)
    {
        $data = $this->request->getPost();
        // Получение файла из запроса
        if($imageFile = $this->request->getFile('photo_url'))
        {
            if ($imageFile->isValid() && ! $imageFile->hasMoved())
            {
                // Generate a new secure name
                $newName = date('YmdHis') . "_" . $imageFile->getRandomName();

                // Move the file to its new home
                $imageFile->move('./uploads/users', $newName);

                // Save the new name to the database
                $data['photo_url'] = $newName;
            }
        }
        if ($data['is_admin'] === "yes"){
            $data['is_admin'] = 1;
        }else{
            $data['is_admin'] = 0;
        }
        // Добавлял эти строки
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']); // удаляем оригинальный пароль, он больше не нужен
        }


        if ($this->model->update($id, $data)) {
            return redirect()->to('/users')->with('info', 'Данные водителя успешно обновлены');
        } else {
            // Создание нового экземпляра User и заполнение его данными, введенными пользователем
            $user = new \App\Entities\User($data);

            // Возвращение представления с ошибками и сохраненными данными
            return view("users/edit", [
                'validation' => $this->model->errors(),
                'user' => $user,
                'title' => 'Редактирование пользователя',
            ]);
        }
    }


    public function delete($id)
    {
        // Удаление связанных файлов
        if ($user = $this->model->find($id)) {
            @unlink('./uploads/users/' . $user->photo_url);        }
        $this->model->delete($id);

        return redirect()->to('/users');
    }
}

