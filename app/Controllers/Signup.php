<?php

namespace App\Controllers;


class Signup extends BaseController
{
    public function new()
    {
        return view("Signup/new");
    }

    public function create()
    {
        $model = new \App\Models\UserModel;

        $data = new \App\Entities\User($this->request->getPost());
        $data->is_admin = 0;

        // Если данные прошли валидацию и были успешно вставлены...
        if ($model->validate($data) && $model->insert($data))
        {
            return redirect()->to("/signup/success");
        }
        else
        {
            // ...иначе отображаем форму регистрации снова с сообщениями об ошибках и сохраненными данными.
            return view("Signup/new", [
                'validation' => $model->errors(),
                'data' => $data,
            ]);
        }
    }

    public function success()
    {
        return view('Signup/success');
    }

}