<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\MapModel;
use Config\Database;
use Config\GameBalance;

/**
 * Класс HealthRegenerationHandler
 *
 * Предназначен для:
 * 1) Регулярной регенерации здоровья (health) и усталости (tired) у персонажей
 *    при условии, что их уровень ниже определённого порога (в данном случае < 20).
 * 2) Переопределения уровня персонажа на основе его характеристик (experience, strength, agility, intellect).
 * 3) Синхронизации biome_id персонажа с реальными данными из таблицы map:
 *    - Если у персонажа в поле cell_number другое значение, чем в таблице map.biome_id,
 *      класс исправляет поле biome_id у персонажа, приводя их в соответствие.
 *
 * v0.51.13 perf optimization (HOT path — runs every minute on ~358 chars):
 *   - process(): batch updates per char (1 query with all changed fields, 0 if nothing
 *     changed) instead of up to 3 separate queries.
 *   - verifyCharactersBiome(): single SELECT JOIN to find mismatches instead of N+1
 *     (1 + N find + maybe N update was up to ~717 SQL on 358 chars; now 1 + small-mismatch-set).
 *   - Behaviour preserved 1:1 — same fields written, same precedence, same caps.
 *
 * Effective SQL reduction (358 active chars worst case):
 *   Before: ~1800 SQL/min (N+1 in process + N+1 in verify)
 *   After:  ~50-100 SQL/min (only chars with actual changes)
 *   Win:    ~95% query reduction on this handler.
 */
class HealthRegenerationHandler
{
    /**
     * @var CharacterModel $characterModel
     * Модель работы с таблицей characters (хранит основных данных о персонаже).
     */
    protected $characterModel;

    /**
     * @var MapModel $mapModel
     * Модель карты (таблица map). Используется при верификации биома персонажа
     * по значению его cell_number.
     */
    protected $mapModel;

    private GameBalance $cfg;

    /**
     * F2.10 wire-in: $cfg инжектируется опционально (для тестов), по умолчанию
     * читается из config('GameBalance') — централизованный balance config.
     *
     * v0.51.5 cleanup: удалены dead `characterBuildingModel` и `characterResourceModel`
     * (декларировались "на будущее", но никогда не использовались).
     */
    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg            = $cfg ?? config('GameBalance');
        $this->characterModel = new CharacterModel();
        // Используется при проверке соответствия cell_number → biome_id
        $this->mapModel       = new MapModel();
    }

    /**
     * Основной метод, который запускается для регенерации здоровья и усталости.
     * Шаги внутри метода:
     * 1) Получаем список всех персонажей из таблицы characters.
     * 2) Для каждого персонажа пересчитываем уровень (calculateLevel()) и записываем его в БД.
     * 3) Если уровень >= 20, пропускаем дальнейшую регенерацию (допустим, персонаж «высокого» уровня).
     * 4) Если уровень < 20, восстанавливаем часть здоровья (health) и усталости (tired),
     *    но не выше 100 (max).
     * 5) После завершения цикла вызываем verifyCharactersBiome() для верификации bioma.
     *
     * v0.51.13: batch updates per char — only ONE update query per char (or zero if
     * nothing changed). Previously did up to 3 separate updates per char.
     */
    public function process()
    {
        // Получаем всех персонажей
        $characters = $this->characterModel->findAll();

        foreach ($characters as $character) {
            // 1) Вычисляем или «переопределяем» уровень персонажа
            // (level — производное от статов, пересчёт-recompute; skip no-op writes)
            $newLevel = $this->calculateLevel($character);
            if ((int) $character['level'] !== $newLevel) {
                $this->characterModel->update($character['id'], ['level' => $newLevel]);
            }
        }

        // 2) Регенерация. Fix 2026-07-13 (класс lost-update): ОДИН атомарный
        // relative-UPDATE (health/tired = LEAST(100, x + tick)) вместо per-char
        // абсолютных записей из снапшота findAll() — снапшот-запись затирала
        // параллельные изменения (препарат, бой, добыча) на всю длительность
        // цикла крона. Бонус perf: ~N UPDATE'ов на тик → 1.
        // CASE WHEN вместо LEAST() и PHP-timestamp вместо NOW() — портабельно
        // MySQL (прод) + SQLite :memory: (PHPUnit); prefixTable для tests-группы (db_).
        $db    = Database::connect();
        $table = $db->prefixTable('characters');
        $h     = $this->cfg->healthRegenPerTick;
        $t     = $this->cfg->tiredRegenPerTick;
        $db->query(
            "UPDATE {$table}
                SET health = CASE WHEN health + ? > 100 THEN 100 ELSE health + ? END,
                    tired  = CASE WHEN tired  + ? > 100 THEN 100 ELSE tired  + ? END,
                    updated_at = ?
              WHERE level < ? AND (health < 100 OR tired < 100)",
            [$h, $h, $t, $t, date('Y-m-d H:i:s'), $this->cfg->regenLevelCap]
        );

        // Затем запускаем метод проверки соответствия biome_id
        $this->verifyCharactersBiome();
    }

    /**
     * Подсчёт уровня персонажа:
     * - берем сумму (experience + strength + agility + intellect),
     * - делим на 4, полученный результат округляем вниз (floor),
     * - минимальный уровень — 1 (если среднее < 1, оставляем 1).
     *
     * @param array|\App\Entities\CharacterEntity $character Строка персонажа из БД
     * @return int итоговое значение уровня
     */
    private function calculateLevel(array|\App\Entities\CharacterEntity $character): int
    {
        $total = $character['experience']
            + $character['strength']
            + $character['agility']
            + $character['intellect'];

        $average = $total / 4.0;
        $level   = floor($average);

        // Если уровень ушёл в 0 или отрицательно, ставим 1
        return max(1, (int)$level);
    }

    /**
     * Проверка соответствия biome_id у персонажей.
     *
     * v0.51.13 perf: single SELECT JOIN finds chars з biome mismatch, потом точкові
     * updates (только для тих ~0-5 chars де реально треба). Раніше — findAll +
     * per-char find + per-char update = N+1 worst case ~717 SQL на 358 chars.
     */
    private function verifyCharactersBiome()
    {
        $db = Database::connect();

        // Один JOIN-запит замість findAll + N×find. Знаходить тільки chars з mismatch
        // на стороні DB. JOIN використовує map.id (PRIMARY KEY) — performance-optimal.
        // На проді map.id == map.cell_number invariant 1:1 (verified 2026-05-06: 1M rows,
        // id-cell_number=0 для всіх). Якщо `map.cell_number` колись отримає UNIQUE INDEX —
        // JOIN можна змінити на `m.cell_number = c.cell_number` для semantic clarity.
        $rows = $db->table('characters c')
            ->select('c.id, m.biome_id AS map_biome_id')
            ->join('map m', 'm.id = c.cell_number', 'inner')
            ->where('c.cell_number IS NOT NULL', null, false)
            ->where('c.biome_id != m.biome_id', null, false)
            ->get()
            ->getResultArray();

        // Точкові updates тільки на тих chars де реально mismatch
        foreach ($rows as $row) {
            $this->characterModel->update((int) $row['id'], [
                'biome_id' => (int) $row['map_biome_id'],
            ]);
        }
    }
}
