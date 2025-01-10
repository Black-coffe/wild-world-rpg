<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\CLI\Console;

class MigrationController extends Controller
{
    public function index()
    {
        $migrate = \Config\Services::migrations();

        try {
            $migrate->latest();
            echo "Миграции успешно выполнены.";
        } catch (\Throwable $e) {
            echo "Ошибка при выполнении миграций: " . $e->getMessage();
        }
    }
}

