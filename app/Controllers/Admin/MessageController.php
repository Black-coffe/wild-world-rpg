<?php

namespace App\Controllers\Admin;

use App\Services\Images\AnnouncementImageService;
use App\Services\Images\OpenAiImageProvider;
use App\Services\Notifications\BroadcastService;
use CodeIgniter\HTTP\RedirectResponse;
use Config\ImageRegistry;
use Throwable;

/**
 * Админ-рассылка в Telegram (broadcast или адресная).
 *
 * v2 (2026-05-13): поддержка прикреплённой картинки (upload или AI-генерация в стиле
 * «Найденная фотоплёнка» через {@see AnnouncementImageService}) + Telegram-Markdown в теле.
 * Если картинка есть — `sendPhoto` с caption (≤1024) или `sendPhoto` без caption + `sendMessage`
 * с текстом (если текст длиннее лимита caption). См. ADR-022 (картинки) + лор-задача 2026-05-13.
 */
class MessageController extends BaseAdminController
{
    private const UPLOAD_MAX_BYTES    = 5_000_000;
    private const UPLOAD_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

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
            $result = $this->dispatch($fullMessage, $imagePath, $telegramIds);
        } catch (Throwable $e) {
            log_message('error', 'MessageController dispatch: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Ошибка отправки: ' . $e->getMessage());
        }

        $this->audit('BROADCAST_SEND', 'telegram_chat', null, [
            'scope'          => $telegramIds === null ? 'all' : 'specific',
            'recipients'     => $result['total'],
            'sent'           => $result['sent'],
            'blocked'        => $result['blocked'],
            'failed'         => $result['failed'],
            'title'          => mb_substr($title, 0, 160),
            'message_length' => mb_strlen($messageContent),
            'image'          => $imageInfo,
        ]);

        return redirect()->back()->with(
            'success',
            "Рассылка: доставлено {$result['sent']}, заблокировали бота {$result['blocked']}, "
            . "прочие ошибки {$result['failed']} (всего {$result['total']})."
        );
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
     * Рассылает сообщение получателям с проверкой доставки (isOk).
     * Делегирует в {@see BroadcastService} (общий код с пост-вайп рассылкой, ADR-087).
     *
     * @param list<string>|null $telegramIds null = всем
     * @return array{sent:int, blocked:int, failed:int, total:int}
     */
    private function dispatch(string $fullMessage, ?string $imagePath, ?array $telegramIds): array
    {
        $broadcast = new BroadcastService();

        return $telegramIds === null
            ? $broadcast->broadcastToAll($fullMessage, $imagePath)
            : $broadcast->broadcastTo($telegramIds, $fullMessage, $imagePath);
    }
}
