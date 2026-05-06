<?php

namespace App\Repositories;

use App\Models\CharacterModel;
use App\Repositories\Contracts\CharacterRepositoryInterface;
use Config\Database;

/**
 * F2.6 — реализация `CharacterRepositoryInterface` поверх CI4 ActiveRecord
 * (`CharacterModel`). Это «default» реализация — domain-сервисы, которые
 * получают `CharacterRepositoryInterface` через конструктор, в проде
 * получают именно её.
 *
 * Для unit-тестов в F2.11+ мы вместо этого класса будем подсовывать
 * `InMemoryCharacterRepository` (или явный mock). Поэтому никаких
 * упоминаний CharacterModel в `DeathService` и т.п. не появится после
 * полной миграции.
 *
 * Атомарные операции (`adjustGold`, `adjustExperience`) используют raw
 * UPDATE с арифметикой в SQL — это исключает read-modify-write race,
 * который был причиной F0.5/F0.6 фиксов TOCTOU.
 */
class CI4CharacterRepository implements CharacterRepositoryInterface
{
    private CharacterModel $model;

    public function __construct(?CharacterModel $model = null)
    {
        $this->model = $model ?? new CharacterModel();
    }

    public function findById(int $id): ?array
    {
        $row = $this->model->find($id);
        if ($row === null) {
            return null;
        }
        // F1.4.4 Step B: Model->find() теперь возвращает CharacterEntity.
        // (array) cast НЕ работает корректно для CI4 Entity (attributes protected),
        // нужен toArray() чтобы получить плоский ассоциативный массив.
        return $row instanceof \App\Entities\CharacterEntity ? $row->toArray() : (array) $row;
    }

    public function findByTelegramUserId(int $telegramUserId): ?array
    {
        $row = $this->model->where('telegram_user_id', $telegramUserId)->first();
        if ($row === null) {
            return null;
        }
        return $row instanceof \App\Entities\CharacterEntity ? $row->toArray() : (array) $row;
    }

    public function updateStats(int $characterId, array $stats): bool
    {
        if (empty($stats)) {
            return true;
        }
        return (bool) $this->model->update($characterId, $stats);
    }

    public function adjustGold(int $characterId, float $delta): void
    {
        // Используем raw UPDATE для атомарного gold = gold + ?.
        // CI4 model->update + read-modify-write нашёл бы TOCTOU при
        // двойных callback'ах (F0.5).
        $db = Database::connect();
        $db->query(
            'UPDATE characters SET gold = gold + ? WHERE id = ?',
            [$delta, $characterId]
        );
    }

    public function adjustExperience(int $characterId, float $delta): void
    {
        $db = Database::connect();
        $db->query(
            'UPDATE characters SET experience = experience + ? WHERE id = ?',
            [$delta, $characterId]
        );
    }

    public function lockAndReadEconomyState(int $characterId): ?array
    {
        // SELECT ... FOR UPDATE — блокирует строку до конца транзакции
        // вызывающего кода. Используется в Buy/Sell handler'ах (F0.5)
        // для перепроверки балансов после получения lock'а.
        $db = Database::connect();
        $query = $db->query(
            'SELECT gold, trading_karma FROM characters WHERE id = ? FOR UPDATE',
            [$characterId]
        );
        if (!$query instanceof \CodeIgniter\Database\BaseResult) {
            return null;
        }
        $row = $query->getRowArray();
        if ($row === null) {
            return null;
        }
        return [
            'gold'          => (float) $row['gold'],
            'trading_karma' => (float) $row['trading_karma'],
        ];
    }
}
