<?php

namespace App\Controllers;

class Login extends BaseController
{
    public function new()
    {
        return view('Login/new');
    }

    public function authenticate()
    {
        $validationRules = [
            'email' => 'required|valid_email',
            'password' => 'required'
        ];

        if (!$this->validate($validationRules)) {
            return redirect()->back()->withInput()->with('validation', $this->validator->getErrors());
        }

        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        $auth = service('auth');

        if ($auth->login($email, $password))
        {
            return redirect()
                ->to('/dashboard');
        }else{
            return redirect()
                ->back()
                ->withInput()
                ->with('warning', 'Пользователь не найден');
        }
    }

    public function delete(){

        service('auth')->logout();

        return redirect()->to('/login')
            ->with('info', 'Авторизация закрыта');
    }
}