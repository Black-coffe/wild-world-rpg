<?php

declare(strict_types=1);

namespace App\Services\PVE;

use Config\Database;

/**
 * Asana «Во время PVP определять, что делал проигравший» (MVP — контекст в результате).
 *
 * Возвращает человекочитаемую фразу о том, чем персонаж был занят в момент PvP-атаки
 * («за добычей ресурсов», «в походе», «на стройке»…), беря активную задачу из character_tasks
 * (status in_work/paused) → tasks.name. Робот-задачи (ambient, не личная активность) исключены.
 * Чисто флейворная подсказка для PvP-сводки (AttackPlayerAction) — добавляет контекст «застали
 * за делом», делает ambush осмысленным. Баланс не затрагивает (admin-tunable не требуется).
 */
final class PvpActivityContextService
{
    /**
     * Фраза активности для подстановки в «застигнут {phrase}». null → активности нет / idle.
     *
     * Захватывать ДО обработки смерти (она отменяет задачи персонажа).
     */
    public function activityPhraseFor(int $characterId): ?string
    {
        if ($characterId <= 0) {
            return null;
        }

        $db  = Database::connect();
        $res = $db->table('character_tasks ct')
            ->select('t.name AS task_name')
            ->join('tasks t', 't.id = ct.task_id')
            ->whereIn('ct.status', ['in_work', 'paused', 'in progress'])
            ->where('ct.character_id', $characterId)
            ->get();
        if ($res === false) {
            return null;
        }
        $rows = $res->getResultArray();
        if ($rows === []) {
            return null;
        }

        // Выбираем самую «осязаемую» активность: полевые (добыча/разведка/поход) важнее
        // базовых (крафт/стройка/отдых). Робот-задачи игнорируем (фоновые, не личные).
        $best         = null;
        $bestPriority = -1;
        foreach ($rows as $row) {
            $name = is_string($row['task_name'] ?? null) ? $row['task_name'] : '';
            if ($name === '' || $this->isRobotTask($name)) {
                continue;
            }
            [$priority, $phrase] = $this->classify($name);
            if ($phrase !== null && $priority > $bestPriority) {
                $bestPriority = $priority;
                $best         = $phrase;
            }
        }

        return $best;
    }

    /**
     * Чистый маппинг имени задачи → фраза активности (без БД). null → робот-задача / пусто.
     * Вынесено публично для unit-тестов и переиспользования.
     */
    public function phraseForTaskName(string $name): ?string
    {
        if ($name === '' || $this->isRobotTask($name)) {
            return null;
        }

        return $this->classify($name)[1];
    }

    /** Робот-задачи — фоновые, не отражают личную активность игрока. */
    private function isRobotTask(string $name): bool
    {
        return str_contains($name, 'Robot');
    }

    /**
     * Имя задачи → [приоритет, фраза]. Чем выше приоритет, тем «осязаемее» сцена для PvP.
     *
     * @return array{0:int, 1:string|null}
     */
    private function classify(string $name): array
    {
        // Полевые активности (игрок открыт в мире) — высший приоритет для PvP-сцены.
        return match (true) {
            $name === 'Gather'                         => [100, 'за добычей ресурсов'],
            $name === 'ExploreTheArea'                 => [95, 'за разведкой местности'],
            $name === 'Marching'                       => [90, 'в походе вглубь острова'],
            $name === 'plantCrop'                      => [60, 'за работой на грядках'],
            $name === 'Repair'                         => [55, 'за починкой снаряжения'],
            $name === 'HealthRegeneration'             => [50, 'на отдыхе, зализывая раны'],
            $name === 'BaseRelocation',
            $name === 'FullRelocation'                 => [45, 'в разгар переезда лагеря'],
            str_starts_with($name, 'craft')            => [40, 'за работой у верстака'],
            str_starts_with($name, 'build'),
            str_starts_with($name, 'startBuild'),
            str_starts_with($name, 'building')         => [40, 'на стройке'],
            // Любая иная личная задача — общий фолбэк (контент всегда что-то покажет).
            default                                    => [10, 'за своими делами'],
        };
    }
}
