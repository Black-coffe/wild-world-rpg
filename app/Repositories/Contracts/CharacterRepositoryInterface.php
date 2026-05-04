<?php

namespace App\Repositories\Contracts;

/**
 * F2.6 — контракт persistence-слоя для Character.
 *
 * Цель: отвязать domain logic от конкретной реализации хранилища.
 * Сейчас сервисы (`DeathService`, `CharacterService`, `BattleService`)
 * напрямую зависят от `CharacterModel`, что:
 *   - связывает domain с CI4 ActiveRecord;
 *   - усложняет unit-тестирование (нужно мокать model + Query Builder);
 *   - блокирует переход на Entity-возврат (см. F1.4).
 *
 * Этот интерфейс описывает минимальный набор операций над персонажем,
 * который покрывает 80% реальных use-сайтов. Реализация по умолчанию —
 * `CI4CharacterRepository` поверх существующего `CharacterModel`.
 *
 * Стратегия миграции (отдельные коммиты после F2.6):
 *   1. Этот интерфейс + `CI4CharacterRepository` появляются и сами
 *      по себе ничего не ломают (никто пока их не использует).
 *   2. По одному сервису переписываем под inject `CharacterRepositoryInterface`
 *      через конструктор: `DeathService` → `CharacterService` →
 *      `PvEService` → action-handler'ы.
 *   3. Через 2-3 sprint'а: `CharacterModel` остаётся только как
 *      persistence-detail внутри репозитория, domain про него не знает.
 *
 * Возвращаемые типы:
 *   - Сейчас — `array<string,mixed>` (как у CharacterModel),
 *   - Целевое — `App\Entities\CharacterEntity` (после F1.4).
 *   В интерфейсе пока `array`, поменяем при едином переходе.
 */
interface CharacterRepositoryInterface
{
    /**
     * Найти персонажа по primary key.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * Найти персонажа по `telegram_user_id` (FK на `telegram_users.id`).
     *
     * @return array<string,mixed>|null
     */
    public function findByTelegramUserId(int $telegramUserId): ?array;

    /**
     * Обновить статы персонажа.
     *
     * @param array<string,mixed> $stats Произвольное подмножество
     *                                  полей, разрешённых в
     *                                  `$allowedFields` модели
     *                                  (level, experience, health,
     *                                  tired, strength, agility,
     *                                  intellect, gold, и т.д.).
     */
    public function updateStats(int $characterId, array $stats): bool;

    /**
     * Прибавить золото (атомарно, без race-condition).
     * Положительное значение — прибавить, отрицательное — отнять.
     */
    public function adjustGold(int $characterId, float $delta): void;

    /**
     * Прибавить опыт (атомарно).
     */
    public function adjustExperience(int $characterId, float $delta): void;

    /**
     * Получить gold + trading_karma атомарно (FOR UPDATE).
     * Возвращает `null` если персонажа нет.
     *
     * @return array{gold:float,trading_karma:float}|null
     */
    public function lockAndReadEconomyState(int $characterId): ?array;
}
