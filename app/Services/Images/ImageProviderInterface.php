<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Адаптер провайдера генерации изображений (OpenAI gpt-image / Gemini Image / …).
 *
 * Реализации лежат рядом: {@see OpenAiImageProvider} (по умолчанию — `gpt-image-2`),
 * {@see GeminiImageProvider} (альтернатива — Nano Banana, пока стаб).
 * Выбор провайдера — `Config\ImageRegistry::$provider`.
 *
 * См. ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`,
 * runbook `mmorpg-vault/runbooks/image-generation.md`.
 */
interface ImageProviderInterface
{
    /**
     * Сгенерировать изображение по полному промпту (STYLE CORE + LEXICON + сцена уже склеены).
     *
     * @param string                                                      $prompt    полный текстовый промпт
     * @param array{aspectRatio?:string, refImagePath?:string|null}        $options   aspectRatio (напр. '3:2'),
     *                                                                                refImagePath — путь к референс-картинке
     *                                                                                для итерации «доработай эту» (если провайдер поддерживает)
     * @return string                                                     сырые байты изображения (PNG/JPEG как отдал провайдер)
     *
     * @throws \RuntimeException если провайдер не сконфигурирован (нет API-ключа) или вызов не удался
     */
    public function generate(string $prompt, array $options = []): string;

    /** Человекочитаемое имя провайдера/модели — для логов и `images:generate --status`. */
    public function describe(): string;
}
