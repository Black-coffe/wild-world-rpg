<?php

namespace App\Libraries;

class Authentication
{
    private $user;

    public function login($email, $password)
    {
        $model = new \App\Models\UserModel;

        $user = $model->findByEmail($email);

        if ($user === null) {
            return false;
        }

        if ( ! $user->verifyPassword($password)) {
            return false;
        }

        $session = session();
        $session->regenerate();
        $session->set('user_id', $user->id);

        return true;
    }

    public function logout()
    {
        session()->destroy();
    }

    public function getCurrentUser()
    {
        if (! session()->has('user_id')){
            return null;
        }

        if ($this->user === null)
        {
            $model = new \App\Models\UserModel;

            $this->user = $model->find(session()->get('user_id'));
        }

        return $this->user;
    }

    public function isLoggedIn()
    {
        return session()->has('user_id');
    }

    public function isAdmin()
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return false;
        }

        return $currentUser->is_admin == 1;
    }

    public function isSuperAdmin()
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $currentUser = $this->getCurrentUser();
        if ($currentUser === null) {
            return false;
        }

        if ($currentUser->email != "super@admin.com" and $currentUser->name != "Administrator") {
            return false;
        }

        return true;
    }
}