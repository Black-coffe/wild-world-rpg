<?php

declare(strict_types=1);

namespace App\Services\Images;

use RuntimeException;

/**
 * Провайдер генерации через OpenAI Images API (модель `gpt-image-2` — флагман,
 * сильнейший по LM Arena среди image-моделей; «medium» ≈$0.04/картинка — паритет с
 * Gemini 2.5 Flash Image, при этом gpt-image-2 — последняя версия у OpenAI, а Gemini
 * 2.5 Flash — не последняя у Google [Gemini 3 Pro/Flash вдвое дороже]). `moderation: low`
 * у OpenAI чуть свободнее по постапок-контенту (самоделки-«пушки», рейдеры).
 *
 * ⚠️ СКАФФОЛД. Реальный HTTP-вызов НЕ реализован — нужен API-ключ (`images.api_key` в `.env`,
 * формат `sk-...`) и финальное согласование. Когда дойдёт до Блока 2:
 *   - endpoint: `POST https://api.openai.com/v1/images/generations`
 *     body: `{"model":"gpt-image-2","prompt": $prompt,"size":"1536x1024","quality":"medium","moderation":"low","n":1}`
 *     (size — landscape ≈3:2; `quality` low|medium|high; `output_format`:"jpeg")
 *   - для итерации «доработай эту»: `POST /v1/images/edits` с `image[]` = $options['refImagePath'] (multipart)
 *   - ответ: `data[0].b64_json` (base64) → decode → байты (либо `data[0].url` → скачать)
 *   - Authorization: `Bearer <key>`; стоимость = output image tokens × $30/1M (gpt-image-2)
 *
 * См. ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`.
 */
final class OpenAiImageProvider implements ImageProviderInterface
{
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
                'OpenAiImageProvider: нет API-ключа (задайте images.api_key=sk-... в .env). '
                . 'Скаффолд — реальная генерация ещё не реализована (Блок 2 «картинок», ADR-022).',
            );
        }

        // TODO(Блок 2): HTTP-вызов /v1/images/generations (size 1536x1024, quality=$this->quality,
        //               moderation=low, output_format=jpeg) или /v1/images/edits с $options['refImagePath'];
        //               распаковать data[0].b64_json (base64) → байты.
        throw new RuntimeException(
            'OpenAiImageProvider::generate() не реализован (скаффолд). '
            . 'См. doc-блок класса + ADR-022 для плана интеграции.',
        );
    }

    public function describe(): string
    {
        return "openai:{$this->model}({$this->quality})";
    }
}
