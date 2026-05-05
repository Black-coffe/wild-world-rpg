<?php

namespace App\Controllers\Admin;

use App\Models\AdminAuditLogModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;

class MessageController extends Controller
{
    // Метод для отображения формы в админке
    public function index(): string
    {
        return view('admin/send_message', [
            'title' => 'Сообщение всем игрокам'
        ]);
    }

    // Метод для отправки сообщений
    public function sendMessage(): RedirectResponse
    {
        $titleRaw          = $this->request->getPost('title');
        $messageContentRaw = $this->request->getPost('message');
        $telegramIdsRaw    = $this->request->getPost('telegram_ids');

        // F0.8 — серверная санитизация. Фронт-валидация через JS обходится,
        // а Markdown parse_mode чувствителен к * _ [ ] ( ) ~ # + - которые
        // могут поломать формат в чужих чатах. Минимум — strip_tags (на
        // случай если parse_mode сменится на HTML) и обрезка длины
        // (Telegram caption limit ≈ 1024, message limit ≈ 4096).
        $title          = mb_substr(strip_tags(is_scalar($titleRaw) ? (string) $titleRaw : ''), 0, 160);
        $messageContent = mb_substr(strip_tags(is_scalar($messageContentRaw) ? (string) $messageContentRaw : ''), 0, 3500);

        $message = "*ℹ️ $title ℹ️*\n\n$messageContent";

        $isBroadcast = empty($telegramIdsRaw);
        if ($isBroadcast) {
            $this->sendToAllUsers($message);
            $recipientsInfo = ['scope' => 'all'];
        } else {
            $telegramIdsString = is_string($telegramIdsRaw) ? $telegramIdsRaw : '';
            $telegramIdsArray  = array_map('trim', explode(',', $telegramIdsString));
            $this->sendToSpecificUsers($message, $telegramIdsArray);
            $recipientsInfo = ['scope' => 'specific', 'count' => count($telegramIdsArray)];
        }

        // F1.9 — audit-лог рассылки. Сохраняем title и длину текста, чтобы
        // понимать что именно рассылал админ, не храня весь текст много раз.
        $auth = service('auth');
        $adminUserId = is_object($auth) && method_exists($auth, 'user') ? (int) ($auth->user()->id ?? 0) : 0;
        (new AdminAuditLogModel())->record(
            $adminUserId,
            'BROADCAST_SEND',
            'telegram_chat',
            null,
            array_merge($recipientsInfo, [
                'title'          => mb_substr($title, 0, 160),
                'message_length' => mb_strlen($messageContent),
            ])
        );

        return redirect()->back()->with('success', 'Сообщение успешно отправлено.');
    }

    // Метод для отправки сообщения всем пользователям
    protected function sendToAllUsers(string $message): void
    {
        $telegramUserModel = new TelegramUserModel();
        $users = $telegramUserModel->findAll();

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $this->sendTelegramMessage($user['telegram_id'], $message);
        }
    }

    /**
     * Метод для отправки сообщения конкретным пользователям.
     *
     * @param array<int, string> $telegramIds
     */
    protected function sendToSpecificUsers(string $message, array $telegramIds): void
    {
        foreach ($telegramIds as $telegramId) {
            $this->sendTelegramMessage($telegramId, $message);
        }
    }

    // Метод для отправки сообщения через Telegram
    protected function sendTelegramMessage(int|string $telegramId, string $message): void
    {
        $API_KEY = (string) getenv('telegram.API_KEY');
        $BOT_USERNAME = (string) getenv('telegram.BOT_USERNAME');

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
