<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\GeneralModel; // Модель для общих запросов к БД
use CodeIgniter\API\ResponseTrait;

class CharacterResetController extends BaseController
{
    use ResponseTrait;

    protected $characterModel;
    protected $telegramUserModel;
    protected $generalModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->generalModel = new GeneralModel(); // Это гипотетическая модель для запросов к БД
    }

    public function index()
    {
        // Отправляем данные о ресурсах в представление
        return view('admin/character_reset_index', [
            'title' => 'Сброс персонажа в игре, его обнуление'
        ]);
    }

    public function resetCharacter()
    {
        $telegramId = $this->request->getPost('id_telegram');
        $characterId = $this->request->getPost('id_character');

        // Проверяем ID пользователя и персонажа
        $userExists = $this->telegramUserModel->where('telegram_id', $telegramId)->first();
        $characterExists = $this->characterModel->find($characterId);

        if (!$userExists || !$characterExists || $userExists['character_id'] != $characterId) {
            return redirect()->back()->with('error', 'ID пользователя или персонажа неверен, или они не соответствуют друг другу.');
        }

        // Получаем список всех таблиц и подсчитываем строки
        $affectedRows = $this->generalModel->countAffectedRows($characterId);
        $data = [
            'affectedRows' => $affectedRows,
            'characterId' => $characterId,
            'telegramId' => $telegramId
        ];

        return view('admin/confirm_reset', $data);
    }

    public function confirmReset($characterId)
    {
        // Подтверждение удаления и выполнение операций по очистке данных
        $this->generalModel->deleteCharacterData($characterId);
        return redirect()->to('/admin/characters')->with('message', 'Персонаж успешно обнулён.');
    }
}
