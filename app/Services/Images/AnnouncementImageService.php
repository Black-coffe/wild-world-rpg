<?php

declare(strict_types=1);

namespace App\Services\Images;

use Config\ImageRegistry;
use RuntimeException;

/**
 * Генерация картинки для админ-рассылки (broadcast) в стиле «Найденная фотоплёнка».
 *
 * Промпт = STYLE CORE ({@see ImageRegistry::$styleCore}) + LEXICON['announcement.generic']
 * + опциональная пользовательская сцена. Провайдер — {@see OpenAiImageProvider}
 * (`gpt-image-2`, key из `.env` `images.api_key`).
 *
 * Готовые байты → даунскейл ≤ {@see ImageRegistry::$maxLongSide} → JPEG q84 ≤ ~300 КБ →
 * запись в `public/uploads/telegram/announcements/<timestamp>-<slug>.jpg`.
 *
 * Используется {@see \App\Controllers\Admin\MessageController}.
 */
final class AnnouncementImageService
{
    public function __construct(
        private readonly ImageRegistry $registry,
        private readonly ImageProviderInterface $provider,
    ) {
    }

    /**
     * Сгенерировать картинку под анонс. Возвращает абсолютный путь к JPEG-файлу.
     *
     * @param string $messageText  Текст анонса (используется как fallback-сцена, если $sceneTweak пуст).
     * @param string $sceneTweak   Опциональное уточнение сцены (1-2 предложения), по-английски лучше.
     *                             Если пусто — берётся LEXICON-only промпт + 12 первых слов сообщения.
     */
    public function generate(string $messageText, string $sceneTweak = ''): string
    {
        $prompt = $this->buildPrompt($messageText, $sceneTweak);

        $bytes = $this->provider->generate($prompt, ['aspectRatio' => $this->registry->aspectRatio]);

        $slug = $this->slug($messageText);
        $name = date('Y-m-d-His') . ($slug !== '' ? '-' . $slug : '') . '.jpg';
        $rel  = 'uploads/telegram/announcements/' . $name;

        $this->writeImage($rel, $bytes);

        return FCPATH . $rel;
    }

    /**
     * Собирает финальный промпт: STYLE CORE с {SCENE} = LEXICON['announcement.generic'] + (опц.) пользовательская сцена.
     */
    public function buildPrompt(string $messageText, string $sceneTweak = ''): string
    {
        $lex = $this->registry->lexicon['announcement.generic'] ?? '';
        if ($lex === '') {
            throw new RuntimeException(
                'AnnouncementImageService: lexicon "announcement.generic" не найден в Config\\ImageRegistry.',
            );
        }

        $scene = $this->normaliseSentence($lex);

        if ($sceneTweak !== '') {
            $scene .= ' ' . $this->normaliseSentence($sceneTweak);
        } else {
            // Деривируем сцену из первых ~12 слов сообщения, чтобы AI ловил тематику.
            $derived = $this->deriveSceneFromMessage($messageText);
            if ($derived !== '') {
                $scene .= ' Echoing this announcement: ' . $this->normaliseSentence($derived);
            }
        }

        return str_replace('{SCENE}', $scene, $this->registry->styleCore);
    }

    /**
     * Достать первые ~12 слов из текста анонса (для слабого hint'а AI о тематике),
     * без markdown-символов и переносов.
     */
    private function deriveSceneFromMessage(string $messageText): string
    {
        $clean = preg_replace('/[*_`\\[\\]()~>#+\\-=|{}]/u', ' ', $messageText) ?? '';
        $clean = preg_replace('/\\s+/u', ' ', $clean) ?? '';
        $clean = trim((string) $clean);

        if ($clean === '') {
            return '';
        }

        $words = preg_split('/\\s+/u', $clean) ?: [];
        $first = array_slice($words, 0, 12);

        return implode(' ', $first);
    }

    private function normaliseSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $last = mb_substr($text, -1);
        if (!in_array($last, ['.', '!', '?'], true)) {
            $text .= '.';
        }

        return $text;
    }

    private function slug(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\\p{L}\\p{N}]+/u', '-', $text) ?? '';
        $text = trim($text, '-');

        return mb_substr($text, 0, 40);
    }

    /**
     * Декодирует байты → даунскейл ≤ maxLongSide → JPEG ≤ maxBytes → атомарная запись.
     * Логика идентична {@see \App\Commands\GenerateImages::writeImage()}.
     */
    private function writeImage(string $relFile, string $bytes): void
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new RuntimeException(
                'AnnouncementImageService: провайдер вернул не-изображение ('
                . strlen($bytes) . ' байт) — не удалось декодировать.',
            );
        }

        $w    = imagesx($img);
        $h    = imagesy($img);
        $long = max($w, $h);
        if ($long > $this->registry->maxLongSide) {
            $scale  = $this->registry->maxLongSide / $long;
            $scaled = imagescale(
                $img,
                max(1, (int) round($w * $scale)),
                max(1, (int) round($h * $scale)),
                IMG_BICUBIC,
            );
            if ($scaled !== false) {
                imagedestroy($img);
                $img = $scaled;
            }
        }

        $path = FCPATH . $relFile;
        @mkdir(dirname($path), 0775, true);
        $tmp = $path . '.tmp';

        $quality = $this->registry->jpegQuality;
        while (true) {
            if (! imagejpeg($img, $tmp, $quality)) {
                imagedestroy($img);
                throw new RuntimeException('AnnouncementImageService: imagejpeg() не смог записать ' . $tmp);
            }
            $size = filesize($tmp);
            if ($size === false || $size <= $this->registry->maxBytes || $quality <= 40) {
                break;
            }
            $quality -= 8;
        }
        imagedestroy($img);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('AnnouncementImageService: не удалось переименовать ' . $tmp . ' в ' . $path);
        }
    }
}
