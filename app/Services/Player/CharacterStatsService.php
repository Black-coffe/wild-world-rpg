<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use CodeIgniter\Database\BaseResult;

/**
 * Атомарные ОТНОСИТЕЛЬНЫЕ апдейты стат-полей персонажа.
 *
 * Класс бага (багрепорт 2026-07-13, «Успокоительное → Стимулятор»): handler читает
 * персонажа в начале запроса, применяет дельту к снапшоту и пишет обратно АБСОЛЮТНЫЕ
 * значения. Конкурентный запрос (быстрые тапы, webhook-retry Телеграма, параллельный
 * task-worker) молча затирается last-writer-wins: эффект первого действия откатывается,
 * параллельные начисления XP/золота теряются.
 *
 * Контракт: короткая транзакция + SELECT ... FOR UPDATE → дельта применяется к СВЕЖИМ
 * значениям под row-lock'ом; UPDATE только реально затронутых полей. Возвращает
 * before/after для «было → стало» сообщений.
 *
 * Использование:
 *   $r = (new CharacterStatsService())->adjust($charId, ['health' => +25, 'tired' => +15]);
 *   // $r = ['before' => ['health' => 63.92, ...], 'after' => ['health' => 88.92, ...]]
 *
 * Floor/cap по умолчанию: health/tired — 0..100, остальные — >= 0. Переопределение
 * (например, floor 0.01 у выносливости в добыче):
 *   ->adjust($charId, ['tired' => -4.5], ['tired' => ['min' => 0.01]]);
 *
 * Для не-дельтовых мутаций (например, health = max(0.01, health / 2)) — mutate():
 * callback получает свежие статы под lock'ом и возвращает новые абсолютные значения.
 */
class CharacterStatsService
{
    /** Стат-поля characters, которые покрывает сервис. */
    public const STAT_FIELDS = ['health', 'tired', 'gold', 'experience', 'strength', 'agility', 'intellect'];

    /** @var array<string, array{min: float|null, max: float|null}> */
    private const DEFAULT_BOUNDS = [
        'health'     => ['min' => 0.0, 'max' => 100.0],
        'tired'      => ['min' => 0.0, 'max' => 100.0],
        'gold'       => ['min' => 0.0, 'max' => null],
        'experience' => ['min' => 0.0, 'max' => null],
        'strength'   => ['min' => 0.0, 'max' => null],
        'agility'    => ['min' => 0.0, 'max' => null],
        'intellect'  => ['min' => 0.0, 'max' => null],
    ];

    /**
     * Атомарно применить дельты к статам персонажа (от свежих значений под row-lock).
     *
     * @param array<string, int|float>                        $deltas         поле => дельта (знак = направление)
     * @param array<string, array{min?: float, max?: float}>  $boundsOverride per-поле floor/cap поверх дефолтных
     *
     * @return array{before: array<string, float>, after: array<string, float>}|null null = персонаж не найден
     */
    public function adjust(int $characterId, array $deltas, array $boundsOverride = []): ?array
    {
        return $this->mutate(
            $characterId,
            array_keys($deltas),
            static fn (array $stats): array => self::applyDeltas($stats, $deltas, $boundsOverride)
        );
    }

    /**
     * Общий примитив: транзакция + SELECT <fields> ... FOR UPDATE → $mutator(свежие
     * статы) → UPDATE только возвращённых полей → commit.
     *
     * SELECT'ится ТОЛЬКО запрошенный список полей — тесты с минимальной схемой
     * characters (например, id+gold) работают, и лишние колонки не читаются.
     *
     * @param list<string>                                                $fields  какие стат-поля читать/лочить (⊆ STAT_FIELDS)
     * @param callable(array<string, float>): array<string, int|float>    $mutator свежие статы → новые АБСОЛЮТНЫЕ значения
     *                                                                             (только поля из $fields; вернуть [] = ничего не писать)
     *
     * @return array{before: array<string, float>, after: array<string, float>}|null null = персонаж не найден
     */
    public function mutate(int $characterId, array $fields, callable $mutator): ?array
    {
        $fields = array_values(array_intersect(self::STAT_FIELDS, $fields));
        if ($fields === []) {
            return ['before' => [], 'after' => []];
        }

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            // prefixTable — уважаем DBPrefix группы. FOR UPDATE — только MySQL:
            // SQLite (fallback tests-группы) синтаксис не знает, а его
            // writer-lock и так сериализует запись.
            $table = $db->prefixTable('characters');
            $sql   = 'SELECT ' . implode(', ', $fields) . " FROM {$table} WHERE id = ?";
            if ($db->DBDriver === 'MySQLi') {
                $sql .= ' FOR UPDATE';
            }
            $result = $db->query($sql, [$characterId]);
            $row    = $result instanceof BaseResult ? $result->getRowArray() : null;

            if ($row === null) {
                $db->transRollback();

                return null;
            }

            $stats = [];
            foreach ($fields as $field) {
                $value         = $row[$field] ?? null;
                $stats[$field] = is_numeric($value) ? (float) $value : 0.0;
            }

            $before = [];
            $write  = [];
            foreach ($mutator($stats) as $field => $value) {
                if (! in_array($field, $fields, true) || ! array_key_exists($field, $stats)) {
                    continue;
                }
                $before[$field] = $stats[$field];
                $write[$field]  = (float) $value;
            }

            if ($write !== []) {
                (new CharacterModel())->update($characterId, $write);
            }

            $db->transCommit();

            return ['before' => $before, 'after' => $write];
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Чистая математика adjust'а (без БД) — выделена для unit-тестов.
     *
     * @param array<string, float>                            $stats          свежие значения статов
     * @param array<string, int|float>                        $deltas         поле => дельта
     * @param array<string, array{min?: float, max?: float}>  $boundsOverride per-поле floor/cap
     *
     * @return array<string, float> поле => новое (клампнутое) значение — только поля из $deltas
     */
    public static function applyDeltas(array $stats, array $deltas, array $boundsOverride = []): array
    {
        $new = [];
        foreach ($deltas as $field => $delta) {
            if (! in_array($field, self::STAT_FIELDS, true) || ! array_key_exists($field, $stats)) {
                continue;
            }
            $new[$field] = self::clamp($field, $stats[$field] + (float) $delta, $boundsOverride);
        }

        return $new;
    }

    /**
     * @param array<string, array{min?: float, max?: float}> $boundsOverride
     */
    public static function clamp(string $field, float $value, array $boundsOverride = []): float
    {
        $bounds = self::DEFAULT_BOUNDS[$field] ?? ['min' => null, 'max' => null];
        $min    = $boundsOverride[$field]['min'] ?? $bounds['min'];
        $max    = $boundsOverride[$field]['max'] ?? $bounds['max'];

        if ($min !== null) {
            $value = max($min, $value);
        }
        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }
}
