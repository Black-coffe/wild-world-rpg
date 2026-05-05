<?php

namespace App\Controllers;

use App\Models\BattleLogModel;
use CodeIgniter\Controller;

class BattlesController extends Controller
{
    /**
     * Показываем список последних 10 боёв.
     */
    public function index()
    {
        // Инициализируем модель
        $model = new BattleLogModel();

        // Получаем последние 10 записей (по убыванию id или finished_at)
        $battles = $model->orderBy('id', 'DESC')
            ->limit(10)
            ->findAll();

        // Передаём их во вьюху
        return view('battles/index', [
            'battles' => $battles
        ]);
    }

    /**
     * Просмотр детальной информации о конкретном бое.
     *
     * @param int $id - ID записи из battle_logs
     */
    public function view($id)
    {
        // Инициализируем модель
        $model = new BattleLogModel();

        // Ищем запись
        $battle = $model->find($id);

        if (!$battle) {
            // Если боя нет, можно кинуть 404
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound("Бой #{$id} не найден.");
        }

        // Декодируем JSON из log_data
        // F1.4.3: $battle тепер BattleLogEntity (Model returnType) — typed property access.
        $logData = json_decode($battle->log_data, true);

        // Передаём все данные в шаблон
        return view('battles/view', [
            'battle'  => $battle,   // cодержит поля battle_type, created_at, etc.
            'logData' => $logData   // здесь массив, включающий characters / rounds / outcome
        ]);
    }
}
