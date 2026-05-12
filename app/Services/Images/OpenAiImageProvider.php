<?php

declare(strict_types=1);

namespace App\Services\Images;

use CodeIgniter\HTTP\CURLRequest;
use CURLFile;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Провайдер генерации через OpenAI Images API (модель `gpt-image-2` — флагман,
 * сильнейший по LM Arena среди image-моделей; «medium» ≈$0.04/картинка — паритет с
 * Gemini 2.5 Flash Image, при этом gpt-image-2 — последняя версия у OpenAI, а Gemini
 * 2.5 Flash — не последняя у Google [Gemini 3 Pro/Flash вдвое дороже]). `moderation: low`
 * у OpenAI чуть свободнее по постапок-контенту (самоделки-«пушки», рейдеры).
 *
 * Endpoints:
 *   - `POST https://api.openai.com/v1/images/generations`
 *     body JSON: `{"model":"gpt-image-2","prompt":<prompt>,"size":"1536x1024","quality":"medium","moderation":"low","output_format":"jpeg","n":1}`
 *   - `POST https://api.openai.com/v1/images/edits` (multipart) — итерация «доработай эту»:
 *     `image[]` = $options['refImagePath'], плюс `prompt`/`model`/`size`/`quality`/`n`
 *   - ответ: `data[0].b64_json` (base64) → decode → байты (фолбэк — `data[0].url` → скачать)
 *   - Authorization: `Bearer <key>` (`images.api_key` в `.env`, формат `sk-...`)
 *   - стоимость: output image tokens × ~$30/1M (gpt-image-2, quality=medium)
 *
 * ⚠️ seed/детерминизм у image-моделей OpenAI недоступен — воспроизводимость/итерации
 * через reference-image (поле `ref` записи реестра / `$options['refImagePath']`), не через seed.
 *
 * См. ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`,
 * runbook `mmorpg-vault/runbooks/image-generation.md`.
 */
final class OpenAiImageProvider implements ImageProviderInterface
{
    private const BASE_URI       = 'https://api.openai.com/v1/';
    private const TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-image-2',
        private readonly string $quality = 'medium',
    ) {
    }

    public function generate(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(
                'OpenAiImageProvider: нет API-ключа — задайте images.api_key=sk-... в .env (gitignored).',
            );
        }

        $size = $this->sizeFor($options['aspectRatio'] ?? '3:2');
        $ref  = $options['refImagePath'] ?? null;

        $client = \Config\Services::curlrequest([
            'baseURI'     => self::BASE_URI,
            'timeout'     => self::TIMEOUT_SECONDS,
            'http_errors' => false,
        ]);

        try {
            if (is_string($ref) && $ref !== '' && is_file($ref)) {
                // Итерация «доработай эту картинку» — /v1/images/edits, multipart.
                $response = $client->request('POST', 'images/edits', [
                    'headers'   => ['Authorization' => 'Bearer ' . $this->apiKey],
                    'multipart' => [
                        'model'   => $this->model,
                        'prompt'  => $prompt,
                        'size'    => $size,
                        'quality' => $this->quality,
                        'n'       => '1',
                        'image[]' => new CURLFile($ref),
                    ],
                ]);
            } else {
                $response = $client->request('POST', 'images/generations', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => json_encode([
                        'model'         => $this->model,
                        'prompt'        => $prompt,
                        'size'          => $size,
                        'quality'       => $this->quality,
                        'moderation'    => 'low',
                        'output_format' => 'jpeg',
                        'n'             => 1,
                    ], JSON_THROW_ON_ERROR),
                ]);
            }
        } catch (JsonException $e) {
            throw new RuntimeException('OpenAiImageProvider: не удалось собрать JSON-запрос — ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException('OpenAiImageProvider: сетевая ошибка вызова OpenAI Images API — ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('OpenAI Images API HTTP %d: %s', $status, $this->extractApiError($body)),
            );
        }

        return $this->extractImageBytes($body, $client);
    }

    public function describe(): string
    {
        return "openai:{$this->model}({$this->quality})";
    }

    /** Сторона картинки в формате OpenAI (`WxH`) под заданное соотношение сторон. */
    private function sizeFor(string $aspect): string
    {
        return match ($aspect) {
            '2:3', '1024x1536' => '1024x1536', // портрет
            '1:1', '1024x1024' => '1024x1024', // квадрат
            default            => '1536x1024', // 3:2 landscape — дефолт игры
        };
    }

    /** Достать байты изображения из ответа API (`b64_json` или, фолбэк, скачать `url`). */
    private function extractImageBytes(string $body, CURLRequest $client): string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('OpenAI Images API: ответ не является JSON — ' . $this->snippet($body));
        }

        $list = is_array($data) && isset($data['data']) ? $data['data'] : null;
        if (! is_array($list) || ! isset($list[0]) || ! is_array($list[0])) {
            throw new RuntimeException('OpenAI Images API: в ответе нет data[0] — ' . $this->snippet($body));
        }
        $item = $list[0];

        if (isset($item['b64_json']) && is_string($item['b64_json']) && $item['b64_json'] !== '') {
            $bytes = base64_decode($item['b64_json'], true);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('OpenAI Images API: не удалось декодировать data[0].b64_json.');
            }

            return $bytes;
        }

        if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
            $img = $client->request('GET', $item['url']);
            if ($img->getStatusCode() !== 200) {
                throw new RuntimeException('OpenAI Images API: не удалось скачать data[0].url (HTTP ' . $img->getStatusCode() . ').');
            }
            $bytes = (string) $img->getBody();
            if ($bytes === '') {
                throw new RuntimeException('OpenAI Images API: data[0].url вернул пустое тело.');
            }

            return $bytes;
        }

        throw new RuntimeException('OpenAI Images API: в data[0] нет ни b64_json, ни url — ' . $this->snippet($body));
    }

    /** Вытащить человекочитаемое сообщение об ошибке из тела ответа OpenAI. */
    private function extractApiError(string $body): string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
                $msg  = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : '';
                $code = is_string($data['error']['code'] ?? null) ? $data['error']['code'] : '';
                $type = is_string($data['error']['type'] ?? null) ? $data['error']['type'] : '';
                $tail = trim($code . ' ' . $type);

                return trim($msg . ($tail !== '' ? " [{$tail}]" : ''));
            }
        } catch (JsonException) {
            // ниже — сырой сниппет
        }

        return $this->snippet($body);
    }

    private function snippet(string $body): string
    {
        $body = trim($body);

        return $body === '' ? '(пустой ответ)' : mb_substr($body, 0, 400);
    }
}
