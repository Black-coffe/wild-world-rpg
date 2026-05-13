<?php

declare(strict_types=1);

namespace App\Attributes;

use Attribute;

/**
 * PHP-атрибут для декларации метаданных handler-классов в плагин-реестре
 * (Phase B1 рефакторинга админ-панели, ADR-023, 2026-05-13).
 *
 * Цель архитектуры: программист пишет handler-класс с этим атрибутом → handler
 * автоматически появляется в админ-форме (выпашка + dynamic input-fields по
 * `inputSchema`) **без правки кода админки**. Админ выбирает handler из
 * списка → форма генерируется → handler сохраняется в БД с валидным
 * `handler_key`. Невозможно создать «пустую» сущность.
 *
 * См. подробности в [[mmorpg-vault/decisions/ADR-023-Handler-plugin-registry]].
 *
 * Использование (B2+ — пока что только инфра, без подключения существующих
 * effect/task-handler'ов):
 *
 *     #[HandlerKey(
 *         key:         'damage_health',
 *         displayName: 'Урон здоровью',
 *         description: 'Снижает HP персонажа за тик события (Hurricane, Volcanic, ...)',
 *         inputSchema: [
 *             ['name' => 'health_loss_range', 'type' => 'int_range', 'min' => 1, 'max' => 100],
 *             ['name' => 'damage_target',     'type' => 'enum',      'values' => ['health', 'tired']],
 *         ],
 *     )]
 *     final class DamageHealthEffect implements EventEffectInterface { ... }
 *
 * Атрибут readonly + final по-значению — после создания не меняется.
 * `inputSchema` намеренно `array<int, array<string, mixed>>` (не типизирован
 * через объекты-fields) — type-safety обеспечивается отдельным валидатором
 * `InputSchemaValidator` (Phase B5 при подключении к admin-формам).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class HandlerKey
{
    /**
     * @param string                           $key         Машинное имя (snake_case или PascalCase). Должно быть уникальным в рамках одного интерфейса.
     * @param string                           $displayName Человекочитаемое имя для admin-выпашки (по-русски).
     * @param string                           $description Краткое описание (1-2 предложения), показывается в admin-tooltip и `spark handlers:list`.
     * @param list<array<string, mixed>>       $inputSchema Спека динамических полей для admin-формы. Каждый элемент — `['name' => string, 'type' => 'int'|'string'|'int_range'|'enum'|'biome_ids'|..., ...spec]`. Валидация структуры — отложена до B5.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $displayName,
        public readonly string $description = '',
        public readonly array $inputSchema = [],
    ) {
    }
}
