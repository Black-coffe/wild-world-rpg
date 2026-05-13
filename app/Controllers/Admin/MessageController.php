<?php

namespace App\Controllers\Admin;

use App\Models\AdminAuditLogModel;
use App\Models\TelegramUserModel;
use App\Services\Images\AnnouncementImageService;
use App\Services\Images\OpenAiImageProvider;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\ImageRegistry;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;
use Throwable;

/**
 * Админ-рассылка в Telegram (broadcast или адресная).
 *
 * v2 (2026-05-13): поддержка прикреплённой картинки (upload или AI-генерация в стиле
 * «Найденная фотоплёнка» через {@see AnnouncementImageService}) + Telegram-Markdown в теле.
 * Если картинка есть — `sendPhoto` с caption (≤1024) или `sendPhoto` без caption + `sendMessage`
 * с текстом (если текст длиннее лимита caption). См. ADR-022 (картинки) + лор-задача 2026-05-13.
 */
class MessageController extends Controller
{
    // Telegram-лимиты по API на 2026-05.
    private const TELEGRAM_CAPTION_LIMIT = 1024;
    private const TELEGRAM_MESSAGE_LIMIT = 4096;
    private const UPLOAD_MAX_BYTES       = 5_000_000;
    private const UPLOAD_ALLOWED_MIME    = ['image/jpeg', 'image/png', 'image/webp'];

    public function index(): string
    {
        return view('admin/send_message', [
            'title' => 'Сообщение всем игрокам',
        ]);
    }

    public function sendMessage(): RedirectResponse
    {
        $titleRaw          = $this->request->getPost('title');
        $messageContentRaw = $this->request->getPost('message');
        $telegramIdsRaw    = $this->request->getPost('telegram_ids');
        $imageModeRaw      = $this->request->getPost('image_mode'); // 'none' | 'upload' | 'ai_generate'
        $imageMode         = is_string($imageModeRaw) ? $imageModeRaw : 'none';
        $imageSceneRaw     = $this->request->getPost('image_scene');

        // Серверная санитизация. strip_tags на случай HTML, ограничение длины — Telegram-лимиты.
        // Markdown-символы (`* _ \` [ ]` (`) >`) НЕ режем: они нужны для форматирования.
        // Безопасность Markdown — на стороне фронта (rich-editor выдаёт корректную разметку).
        $title          = mb_substr(strip_tags(is_scalar($titleRaw) ? (string) $titleRaw : ''), 0, 160);
        $messageContent = mb_substr(strip_tags(is_scalar($messageContentRaw) ? (string) $messageContentRaw : ''), 0, 3500);
        $imageScene     = mb_substr(strip_tags(is_scalar($imageSceneRaw) ? (string) $imageSceneRaw : ''), 0, 500);

        $fullMessage = "*ℹ️ {$title} ℹ️*\n\n{$messageContent}";

        // — Картинка (опционально) —
        $imagePath = null;
        $imageInfo = ['mode' => $imageMode];
        try {
            $imagePath = $this->resolveImage($imageMode, $imageScene, $messageContent, $imageInfo);
        } catch (Throwable $e) {
            log_message('error', 'MessageController image resolve: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Не удалось подготовить картинку: ' . $e->getMessage());
        }

        $telegramIds = $this->parseTelegramIds(is_string($telegramIdsRaw) ? $telegramIdsRaw : '');

        try {
            $sent = $this->dispatch($fullMessage, $imagePath, $telegramIds);
        } catch (Throwable $e) {
            log_message('error', 'MessageController dispatch: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Ошибка отправки: ' . $e->getMessage());
        }

        // — audit-log —
        $auth        = service('auth');
        $adminUserId = is_object($auth) && method_exists($auth, 'user') ? (int) ($auth->user()->id ?? 0) : 0;
        (new AdminAuditLogModel())->record(
            $adminUserId,
            'BROADCAST_SEND',
            'telegram_chat',
            null,
            [
                'scope'          => $telegramIds === null ? 'all' : 'specific',
                'recipients'     => $telegramIds === null ? null : count($telegramIds),
                'sent'           => $sent,
                'title'          => mb_substr($title, 0, 160),
                'message_length' => mb_strlen($messageContent),
                'image'          => $imageInfo,
            ],
        );

        return redirect()->back()->with('success', "Сообщение отправлено (получателей: {$sent}).");
    }

    /**
     * Подготовить путь к картинке в зависимости от выбранного режима.
     *
     * @param array{mode:string, source?:string, file?:string} $info  By-ref — заполняется метаданными для audit.
     */
    private function resolveImage(string $mode, string $scene, string $messageContent, array &$info): ?string
    {
        if ($mode === 'none' || $mode === '') {
            $info = ['mode' => 'none'];
            return null;
        }

        if ($mode === 'upload') {
            $request = $this->request;
            $file    = method_exists($request, 'getFile') ? $request->getFile('image_file') : null;
            if ($file === null || !$file->isValid() || $file->getSize() === 0) {
                $info = ['mode' => 'upload', 'source' => 'missing'];
                return null;
            }
            if ($file->getSize() > self::UPLOAD_MAX_BYTES) {
                throw new \RuntimeException('Файл больше ' . self::UPLOAD_MAX_BYTES . ' байт.');
            }
            if (!in_array($file->getMimeType(), self::UPLOAD_ALLOWED_MIME, true)) {
                throw new \RuntimeException('Неподдерживаемый формат: ' . $file->getMimeType() . '. Разрешены JPEG/PNG/WebP.');
            }

            $dir = FCPATH . 'uploads/telegram/announcements/';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Не удалось создать ' . $dir);
            }

            $name = date('Y-m-d-His') . '-upload.' . ($file->getExtension() ?: 'jpg');
            $file->move($dir, $name);
            $info = ['mode' => 'upload', 'file' => 'uploads/telegram/announcements/' . $name];

            return $dir . $name;
        }

        if ($mode === 'ai_generate') {
            $service  = $this->buildAnnouncementImageService();
            $path     = $service->generate($messageContent, $scene);
            $relative = str_starts_with($path, FCPATH) ? substr($path, strlen(FCPATH)) : $path;
            $info     = ['mode' => 'ai_generate', 'file' => $relative, 'scene' => $scene];

            return $path;
        }

        $info = ['mode' => $mode, 'source' => 'unknown'];
        return null;
    }

    private function buildAnnouncementImageService(): AnnouncementImageService
    {
        /** @var ImageRegistry $registry */
        $registry = config(ImageRegistry::class);

        $envValue    = env($registry->apiKeyEnv);
        $getenvValue = getenv($registry->apiKeyEnv);
        $apiKey      = '';
        if (is_string($envValue) && $envValue !== '') {
            $apiKey = $envValue;
        } elseif (is_string($getenvValue) && $getenvValue !== '') {
            $apiKey = $getenvValue;
        }

        $provider = new OpenAiImageProvider($apiKey, $registry->model, $registry->quality);

        return new AnnouncementImageService($registry, $provider);
    }

    /**
     * @return list<string>|null  null = broadcast всем
     */
    private function parseTelegramIds(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ids = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '');
        return array_values($ids);
    }

    /**
     * @param list<string>|null $telegramIds  null = всем
     * @return int  количество фактически отправленных сообщений
     */
    private function dispatch(string $fullMessage, ?string $imagePath, ?array $telegramIds): int
    {
        $apiKeyEnv      = getenv('telegram.API_KEY');
        $botUsernameEnv = getenv('telegram.BOT_USERNAME');
        $apiKey         = is_string($apiKeyEnv) ? $apiKeyEnv : '';
        $botUsername    = is_string($botUsernameEnv) ? $botUsernameEnv : '';
        $telegram       = new Telegram($apiKey, $botUsername);
        Request::initialize($telegram);

        $recipients = $telegramIds;
        if ($recipients === null) {
            /** @var list<string> $collected */
            $collected = [];
            foreach ((new TelegramUserModel())->findAll() as $u) {
                if (is_array($u) && isset($u['telegram_id']) && is_scalar($u['telegram_id'])) {
                    $id = (string) $u['telegram_id'];
                    if ($id !== '') {
                        $collected[] = $id;
                    }
                }
            }
            $recipients = $collected;
        }

        $sent = 0;
        foreach ($recipients as $telegramId) {
            try {
                $this->sendOne($telegramId, $fullMessage, $imagePath);
                $sent++;
            } catch (TelegramException $e) {
                log_message('error', 'Telegram send to ' . $telegramId . ': ' . $e->getMessage());
            } catch (Throwable $e) {
                log_message('error', 'Send failure to ' . $telegramId . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    private function sendOne(string $chatId, string $fullMessage, ?string $imagePath): void
    {
        if ($imagePath === null) {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => mb_substr($fullMessage, 0, self::TELEGRAM_MESSAGE_LIMIT),
                'parse_mode' => 'Markdown',
            ]);
            return;
        }

        // Есть картинка. Если текст помещается в caption (≤1024) — sendPhoto с caption.
        // Если длиннее — sendPhoto без caption + отдельным sendMessage полный текст.
        if (mb_strlen($fullMessage) <= self::TELEGRAM_CAPTION_LIMIT) {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $fullMessage,
                'parse_mode' => 'Markdown',
            ]);
            return;
        }

        Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
        ]);
        Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => mb_substr($fullMessage, 0, self::TELEGRAM_MESSAGE_LIMIT),
            'parse_mode' => 'Markdown',
        ]);
    }
}
