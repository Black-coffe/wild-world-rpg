<?php

declare(strict_types=1);

namespace App\Services\Handlers;

use App\Attributes\HandlerKey;

/**
 * Запись о зарегистрированном handler-классе в {@see HandlerRegistry}.
 *
 * Создаётся {@see HandlerDiscoveryScanner} при scan'е class-map'а + Reflection
 * атрибута {@see HandlerKey}. Иммутабельная (readonly).
 *
 * Phase B1 (Foundation, 2026-05-13, ADR-023). Используется в admin-формах с B5.
 */
final class HandlerEntry
{
    /**
     * @param class-string $class    FQCN handler-класса (e.g. `App\Services\Events\Effects\DamageHealthEffect`).
     * @param HandlerKey   $metadata Атрибут с метаданными (key/displayName/description/inputSchema).
     * @param list<string> $interfaces FQCN интерфейсов, которые реализует класс (e.g. `[EventEffectInterface::class]`). Позволяет registry фильтровать handler'ы по «типу» (event-effect / task-handler / ...).
     */
    public function __construct(
        public readonly string $class,
        public readonly HandlerKey $metadata,
        public readonly array $interfaces,
    ) {
    }

    public function getKey(): string
    {
        return $this->metadata->key;
    }

    /**
     * @return array{key: string, displayName: string, description: string}
     */
    public function toAdminOption(): array
    {
        return [
            'key'         => $this->metadata->key,
            'displayName' => $this->metadata->displayName,
            'description' => $this->metadata->description,
        ];
    }
}
