<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\GeneralModel;
use Config\ResetTables;
use CodeIgniter\API\ResponseTrait;
use App\Controllers\Telegram\BotController;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class CharacterResetController extends BaseController
{
    use ResponseTrait;

    protected $characterModel;
    protected $telegramUserModel;
    protected $generalModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->generalModel = new GeneralModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function index()
    {
        $telegramUserId = 1; // Здесь нужно указать ID телеграм пользователя
        $characterId = null;

        $character = $this->characterModel->where('telegram_user_id', $telegramUserId)->first();
        if ($character !== null) {
            $characterId = $character['id'];
        }

        return view('admin/character_reset_index', [
            'title' => 'Сброс персонажа в игре, его обнуление',
            'characterId' => $characterId
        ]);
    }

    public function check()
    {
        $telegramId = $this->request->getPost('id_telegram');
        $characterId = $this->request->getPost('characterId');

        // Проверяем существование пользователя и персонажа
        $user = $this->telegramUserModel->where('telegram_id', $telegramId)->first();
        $character = $this->characterModel->find($characterId);

        if (!$user || !$character || $user['id'] != $character['telegram_user_id']) {
            return redirect()->back()->with('errors', ['Неверные данные пользователя или персонажа.']);
        }

        // Получение информации о затрагиваемых таблицах
        $affectedRows = $this->getAffectedTablesData($characterId);

        return view('admin/character_reset_index', [
            'affectedRows' => $affectedRows,
            'characterId' => $characterId,
            'telegramId' => $telegramId,
            'title' => 'Подтверждение сброса персонажа'
        ]);
    }

    private function getAffectedTablesData($characterId)
    {
        $resetConfig = new ResetTables();
        $db = db_connect();
        $affectedRows = [];

        foreach ($resetConfig->tables as $table => $column) {
            $count = $db->table($table)->where($column, $characterId)->countAllResults();
            if ($count > 0) {
                $affectedRows[$table] = $count;
            }
        }

        return $affectedRows;
    }

    public function confirmReset()
    {
        $characterId = (int) $this->request->getPost('characterId');
        $telegramId = $this->request->getPost('telegramId');
        $db = db_connect();
        $resetConfig = new ResetTables();

        $deletedTotals = [];
        foreach ($resetConfig->tables as $table => $column) {
            if ($db->tableExists($table) && $db->fieldExists($column, $table)) {
                $db->table($table)->where($column, $characterId)->delete();
                $deletedTotals[$table] = $db->affectedRows();
            }
        }

        // F1.9 — audit-лог destructive админского действия.
        $auth = service('auth');
        $adminUserId = method_exists($auth, 'user') ? (int) ($auth->user()->id ?? 0) : 0;
        (new AdminAuditLogModel())->record(
            $adminUserId,
            'CHARACTER_RESET',
            'character',
            $characterId,
            [
                'telegram_id'     => $telegramId,
                'tables_affected' => $deletedTotals,
            ]
        );

        if ($telegramId) {
            $botController = new BotController();
            $message = "🔥 *Внимание!* Твой персонаж был сброшен (обнулен) до стартовых показателей. 🎲\n" .
                "🧹 Все, что было до этого, удалено и забыто. Даже исторические кнопки, которые у тебя сохранились в ленте чата, не будут работать. 🚫 Советую:\n\n" .
                "1⃣ Почистить всю переписку (историю чата).\n" .
                "2⃣ Написать мне в сообщении: `/start`.\n\n" .
                "🚀 *Готов начать новое приключение? Напиши `/start` и вперёд к новым горизонтам!* 🌌";

            $imagePath = base_url('uploads/telegram/character/reset_character.jpg'); // Assuming the image path is correct

            $botController->sendMessage($telegramId, $message, $imagePath);
        }

        return redirect()->to('/admin/character-reset')->with('message', 'Персонаж успешно обнулён.');
    }

    private function deleteCharacterData($characterId)
    {
        $resetConfig = new ResetTables();
        $db = db_connect();
        $totalDeleted = 0;

        foreach ($resetConfig->tables as $table => $column) {
            $totalDeleted += $db->table($table)->where($column, $characterId)->delete();
        }

        return $totalDeleted;
    }

    public function resetAllCharacters()
    {
        dd("Обеуление всех персонажей отключено");
        exit();
        $db = db_connect();
        $resetConfig = new ResetTables();
        $characters = $this->characterModel->findAll(); // Получаем всех персонажей

        $db->transStart(); // Начало транзакции

        foreach ($characters as $character) {
            foreach ($resetConfig->tables as $table => $column) {
                if ($db->tableExists($table) && $db->fieldExists($column, $table)) {
                    $db->table($table)->where($column, $character['id'])->delete();
                }
            }

            // Если нужно, отправляем уведомление каждому пользователю
            if (isset($character['telegram_user_id'])) {
                $telegramId = $this->telegramUserModel->find($character['telegram_user_id'])['telegram_id'];
                if ($telegramId) {
                    $botController = new BotController();
                    $message = "🔥 *Внимание!* Твой персонаж был сброшен (обнулен) до стартовых показателей. 🎲\n" .
                        "🧹 Все, что было до этого, удалено и забыто. Даже исторические кнопки, которые у тебя сохранились в ленте чата, не будут работать. 🚫 Советую:\n\n" .
                        "1⃣ Почистить всю переписку (историю чата).\n" .
                        "2⃣ Написать мне в сообщении: `/start`.\n\n" .
                        "🚀 *Готов начать новое приключение? Напиши `/start` и вперёд к новым горизонтам!* 🌌";

                    $imagePath = base_url('uploads/telegram/character/reset_character.jpg'); // Assuming the image path is correct

                    $botController->sendMessage($telegramId, $message, $imagePath);
                }
            }
        }

        $db->transComplete(); // Завершение транзакции

        if ($db->transStatus() === false) {
            return redirect()->back()->with('errors', ['Ошибка при сбросе данных всех персонажей.']);
        }

        return redirect()->to('/admin/character-reset')->with('message', 'Все персонажи успешно обнулены.');
    }

}
