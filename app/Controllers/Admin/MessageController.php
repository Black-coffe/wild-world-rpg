<?php

namespace App\Controllers\Admin;

use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;

class MessageController extends Controller
{
    // Метод для отображения формы в админке
    public function index()
    {
        return view('admin/send_message', [
            'title' => 'Сообщение всем игрокам'
        ]);
    }

    // Метод для отправки сообщений
    public function sendMessage()
    {
        $title          = $this->request->getPost('title');
        $messageContent = $this->request->getPost('message');
        $telegramIds    = $this->request->getPost('telegram_ids');

        // F0.8 — серверная санитизация. Фронт-валидация через JS обходится,
        // а Markdown parse_mode чувствителен к * _ [ ] ( ) ~ # + - которые
        // могут поломать формат в чужих чатах. Минимум — strip_tags (на
        // случай если parse_mode сменится на HTML) и обрезка длины
        // (Telegram caption limit ≈ 1024, message limit ≈ 4096).
        $title          = mb_substr(strip_tags((string) $title), 0, 160);
        $messageContent = mb_substr(strip_tags((string) $messageContent), 0, 3500);

        $message = "*ℹ️ $title ℹ️*\n\n$messageContent";

        if (empty($telegramIds)) {
            $this->sendToAllUsers($message);
        } else {
            $telegramIdsArray = array_map('trim', explode(',', $telegramIds));
            $this->sendToSpecificUsers($message, $telegramIdsArray);
        }

        return redirect()->back()->with('success', 'Сообщение успешно отправлено.');
    }

    // Метод для отправки сообщения всем пользователям
    protected function sendToAllUsers($message)
    {
        $telegramUserModel = new TelegramUserModel();
        $users = $telegramUserModel->findAll();

        foreach ($users as $user) {
            $this->sendTelegramMessage($user['telegram_id'], $message);
        }
    }

    // Метод для отправки сообщения конкретным пользователям
    protected function sendToSpecificUsers($message, array $telegramIds)
    {
        foreach ($telegramIds as $telegramId) {
            $this->sendTelegramMessage($telegramId, $message);
        }
    }

    // Метод для отправки сообщения через Telegram
    protected function sendTelegramMessage($telegramId, $message)
    {
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($telegram);

            $response = Request::sendMessage([
                'chat_id' => $telegramId,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);

        } catch (TelegramException $e) {
            log_message('error', 'Ошибка отправки сообщения через Telegram: ' . $e->getMessage());
        }
    }
}
