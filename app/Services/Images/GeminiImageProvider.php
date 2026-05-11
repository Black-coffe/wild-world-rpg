<?php

declare(strict_types=1);

namespace App\Services\Images;

use RuntimeException;

/**
 * Провайдер генерации через Google Gemini Image API (модель `gemini-2.5-flash-image`,
 * «Nano Banana» — рекомендована ресёрчем 2026-05-11: ≈$0.039/картинка, нативный 3:2,
 * лучшая итеративность — multi-turn editing + до 3 reference-картинок).
 *
 * ⚠️ СКАФФОЛД. Реальный HTTP-вызов НЕ реализован — нужен API-ключ (`images.api_key` в `.env`)
 * и финальное согласование провайдера. Когда дойдёт до Блока 2:
 *   - endpoint: `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`
 *   - body: `{ "contents":[{"parts":[{"text": $prompt}]}], "generationConfig":{"responseModalities":["IMAGE"], "imageConfig":{"aspectRatio":"3:2"}} }`
 *     (+ `{"parts":[..., {"inlineData":{"mimeType":"image/jpeg","data": base64(ref)}}]}` для refImagePath)
 *   - safety_settings: ослабить `HARM_CATEGORY_DANGEROUS_CONTENT` / `HARASSMENT` до `BLOCK_NONE`
 *     (постапок-арт с самоделками-«пушками»/рейдерами триггерит дефолтные фильтры Google)
 *   - ответ: `candidates[0].content.parts[].inlineData.data` (base64) → decode → байты
 *   - картинки получают невидимый SynthID-watermark (на качество не влияет, удалить нельзя)
 *
 * См. ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`.
 */
final class GeminiImageProvider implements ImageProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash-image',
    ) {
    }

    public function generate(string $prompt, array $options = []): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(
                'GeminiImageProvider: нет API-ключа (задайте images.api_key в .env). '
                . 'Скаффолд — реальная генерация ещё не реализована (Блок 2 «картинок», ADR-022).',
            );
        }

        // TODO(Блок 2): HTTP-вызов generateContent с responseModalities=[IMAGE], imageConfig.aspectRatio,
        //               safety_settings=BLOCK_NONE, опц. inlineData ($options['refImagePath']);
        //               распаковать candidates[0].content.parts[].inlineData.data (base64) → байты.
        throw new RuntimeException(
            'GeminiImageProvider::generate() не реализован (скаффолд). '
            . 'См. doc-блок класса + ADR-022 для плана интеграции.',
        );
    }

    public function describe(): string
    {
        return "gemini:{$this->model}";
    }
}
