<?php

namespace App\Controllers;

class Password extends BaseController
{
    public function forgot()
    {
        return view('Password/forgot', [
            'title' => 'Восстановление пароля'
        ]);
    }

    public function processForgot()
    {
        $model = new \App\Models\UserModel;

        $user = $model->findByEmail($this->request->getPost('email'));

        if ($user) {

            $user->startPasswordReset();

            $model->save($user);

            $this->sendResetEmail($user);

            return redirect()->to("/password/resetsent");

        } else {

            return redirect()->back()
                ->with('warning', 'No active user found with that email address')
                ->withInput();
        }
    }

    public function resetSent()
    {
        return view('Password/reset_sent', [
            'title' => 'Пароль сброшен, проверьте вашу почту Email'
        ]);
    }

    public function reset($token)
    {
        $model = new \App\Models\UserModel;

        $user = $model->getUserForPasswordReset($token);

        if ($user) {

            return view('Password/reset', [
                'token' => $token,
                'title' => '111'
            ]);

        } else {

            return redirect()->to('/password/forgot')
                ->with('warning', 'Ссылка недействительна или истекла сроком. Попробуйте еще раз');

        }
    }

    public function processReset($token)
    {
        $model = new \App\Models\UserModel;
        $user = $model->getUserForPasswordReset($token);

        if ($user) {
            $data = $this->request->getPost();

            $password = $data['password'];
            $password_confirmation = $data['password_confirmation'];

            // Проверяем совпадение пароля и подтверждения
            if ($password !== $password_confirmation) {
                return view("Password/reset", [
                    'validation' => $model->errors(),
                    'token' => $token,
                    'warning' => 'Пароли не совпадают',
                    'title' => '222'
                ]);
            }

            $user->password_hash = password_hash($password, PASSWORD_DEFAULT); // Add this line
            $updateData = ['password_hash' => $user->password_hash];
//            print_r($data);
//            dd($user);

            if ($model->update($user->id, $updateData)) {
                return redirect()->to('/password/reset-success');
            } else {
                return view("Password/reset", [
                    'validation' => $model->errors(),
                    'token' => $token,
                    'warning' => 'Пароль не принят',
                    'title' => '333'
                ]);
            }
        } else {
            return redirect()->to('/password/forgot')
                ->with('warning', 'Ссылка не верная или же истек срок на восстановлени!');
        }
    }


    public function resetSuccess()
    {
        return view('Password/reset_success', [
            'title' => '444'
        ]);
    }

    private function sendResetEmail($user)
    {
        $email = service('email');

        $email->setTo($user->email);

        $email->setSubject('Password reset');

        $message = view('Password/reset_email', [
            'token' => $user->reset_token,
            'title' => '555'
        ]);

        $email->setMessage($message);

        $email->send();
    }
}










