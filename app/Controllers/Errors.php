<?php

namespace App\Controllers;

class Errors extends BaseController
{
    public function show404()
    {
        // Установка кода ответа
        $this->response->setHeader('HTTP/1.1 404 Not Found');

        // Загрузка представления 404
        return view('errors/404');
    }
}

