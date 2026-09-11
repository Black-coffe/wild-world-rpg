<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * pvp-detection-clarity-03 — легаси-«кладбище» брошенных персонажей слежалось в углу
 * карты (0,0)/(1,1): 165 персонажей физически стоят рядом друг с другом и попадают в
 * обнаружение любому, кто там пройдёт (`docs/specs/pvp-detection-clarity/recon-prod.md`
 * §1). Новые персонажи туда с мая 2026 не попадают — это осадок, не течь.
 *
 *   php spark relocate:abandoned                              — сухой план, ничего не пишет
 *   php spark relocate:abandoned --confirm=RELOCATE-ABANDONED  — реальный переезд
 *
 * Кандидат — персонаж на `cell_number` 1 или 1002 (координаты (0,0)/(1,1)), у которого
 * ни одной строки в `action_log` за `--days` (default 30) и нет активной заклеймённой
 * клетки (`claimed_cells.status='active'`). Уровень персонажа в отборе не участвует.
 * Источник активности — `action_log.created_at`: `characters.last_update_time` пуста у
 * всех строк на проде, фильтр по ней отсеет всех до единого (recon-prod.md §1/§4).
 *
 * 🔴 Целевая полоса по умолчанию — `coordinate_y` 850–899, НЕ зона высадки `y >= 900`.
 * Та полоса — точка спавна КАЖДОГО новичка и точка респавна после каждой смерти
 * (см. `StartCommand::indexAction()`); ссыпать туда 165 брошенных значит удвоить
 * плотность призраков ровно там, где картина мира должна читаться чище всего, и включить
 * там кнопки «Атака / Бегство», которые в зоне респавна мертвы по гейту (решение
 * редколлегии 11.09, story pvp-detection-clarity-03 §Acceptance).
 *
 * Биомы целевых клеток — тот же список `[1,2,3,5,6,7,8,9]`, которым пользуется
 * фактический спавн в `StartCommand.php` (канон расходится сам с собой между
 * `GAME_DESCRIPTION.md` и `WipeManifest.php`; код — источник истины, он старше доков).
 *
 * Инструмент внутренний/admin-only (запускается вручную с проверкой на testbot/проде),
 * не player-facing механика — вне области ADMIN-TUNABLE BALANCE / GUIDE / TIPS coverage.
 */
class RelocateAbandonedCharacters extends BaseCommand
{
    protected $group       = 'Game';
    protected $name        = 'relocate:abandoned';
    protected $description = 'Расселяет брошенных персонажей из угла карты (0,0)/(1,1) на южную полосу. По умолчанию — сухой план.';
    protected $usage       = 'relocate:abandoned [--days=30] [--y-min=850] [--y-max=899] [--limit=0] [--confirm=RELOCATE-ABANDONED]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'Окно неактивности в action_log, дней (default: 30)',
        '--y-min'   => 'Нижняя граница целевой полосы coordinate_y (default: 850)',
        '--y-max'   => 'Верхняя граница целевой полосы coordinate_y (default: 899)',
        '--limit'   => 'Максимум персонажей за прогон, 0 = без ограничения (default: 0)',
        '--confirm' => 'Точная фраза RELOCATE-ABANDONED для реального запуска (иначе только сухой план).',
    ];

    public const CONFIRM_PHRASE = 'RELOCATE-ABANDONED';

    /** Клетки угла карты (0,0)/(1,1) — легаси-осадок, не текущая точка спавна. */
    private const ORIGIN_CELLS = [1, 1002];

    /** Тот же список, которым StartCommand фильтрует допустимые клетки спавна. */
    private const SPAWN_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    private const ACTION_NAME = 'abandoned_character_relocated';

    public function run(array $params)
    {
        $days  = (int) (CLI::getOption('days') ?? 30);
        $yMin  = (int) (CLI::getOption('y-min') ?? 850);
        $yMax  = (int) (CLI::getOption('y-max') ?? 899);
        $limit = (int) (CLI::getOption('limit') ?? 0);

        if ($days < 1) {
            CLI::error('--days должно быть >= 1');
            return;
        }
        if ($yMin < 0 || $yMax < $yMin) {
            CLI::error('--y-min/--y-max заданы некорректно');
            return;
        }

        $confirm = $this->resolveConfirm();
        $dryRun  = $confirm !== self::CONFIRM_PHRASE;

        if ($confirm !== '' && $confirm !== self::CONFIRM_PHRASE) {
            CLI::error('Неверная фраза подтверждения. Ожидается: ' . self::CONFIRM_PHRASE);
            return;
        }

        $result = $this->relocate($days, $yMin, $yMax, $limit, $dryRun);

        if ($result['candidates'] === 0) {
            CLI::write('Кандидатов не найдено.', 'green');
            return;
        }

        if ($result['error'] === 'not_enough_target_cells') {
            CLI::error(sprintf(
                'В полосе y=[%d,%d] (биомы %s) свободных клеток меньше, чем кандидатов: %d нужно. Расширьте полосу --y-min/--y-max.',
                $yMin,
                $yMax,
                implode(',', self::SPAWN_BIOMES),
                $result['candidates']
            ));
            return;
        }

        $this->printPlan($result['plan'], $days, $yMin, $yMax);

        if ($dryRun) {
            CLI::write('');
            CLI::write('Это был СУХОЙ ПЛАН — ничего не изменено.', 'cyan');
            CLI::write('Для реального переезда: php spark relocate:abandoned --confirm=' . self::CONFIRM_PHRASE, 'yellow');
            return;
        }

        CLI::write('');
        CLI::write("✓ Переселено: {$result['moved']} из " . count($result['plan']), 'green');
        log_message('info', "[relocate:abandoned] переселено {$result['moved']} персонажей в полосу y=[{$yMin},{$yMax}]");
    }

    /**
     * Ядро логики, независимое от CLI-вывода — так его вызывает тест напрямую.
     *
     * @return array{candidates:int, plan:list<array{character_id:int,chat_id:int,name:string,from:int,to:int}>, moved:int, error:?string}
     */
    public function relocate(int $days = 30, int $yMin = 850, int $yMax = 899, int $limit = 0, bool $dryRun = true): array
    {
        $db = Database::connect();

        $candidates = $this->findCandidates($db, $days, $limit);
        if ($candidates === []) {
            return ['candidates' => 0, 'plan' => [], 'moved' => 0, 'error' => null];
        }

        $targets = $this->findTargetCells($db, $yMin, $yMax);
        if (count($targets) < count($candidates)) {
            return ['candidates' => count($candidates), 'plan' => [], 'moved' => 0, 'error' => 'not_enough_target_cells'];
        }

        shuffle($targets);

        $plan = [];
        foreach ($candidates as $i => $c) {
            $plan[] = [
                'character_id' => $c['character_id'],
                'chat_id'      => $c['chat_id'],
                'name'         => $c['name'],
                'from'         => $c['cell_number'],
                'to'           => $targets[$i],
            ];
        }

        $moved = 0;
        if (! $dryRun) {
            foreach ($plan as $row) {
                if ($this->relocateOne($db, $row)) {
                    $moved++;
                }
            }
        }

        return ['candidates' => count($candidates), 'plan' => $plan, 'moved' => $moved, 'error' => null];
    }

    /**
     * @param BaseConnection<object,object> $db
     * @return list<array{character_id:int,chat_id:int,name:string,cell_number:int}>
     */
    private function findCandidates(BaseConnection $db, int $days, int $limit): array
    {
        $sql = 'SELECT c.id AS character_id, c.name AS name, c.cell_number AS cell_number,
                       COALESCE(tu.telegram_id, 0) AS chat_id
                FROM characters c
                LEFT JOIN telegram_users tu ON tu.id = c.telegram_user_id
                WHERE c.cell_number IN (' . implode(',', self::ORIGIN_CELLS) . ')
                  AND NOT EXISTS (
                      SELECT 1 FROM action_log al
                      WHERE al.character_id = c.id
                        AND al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM claimed_cells cc
                      WHERE cc.character_id = c.id AND cc.status = \'active\'
                  )
                ORDER BY c.id ASC';

        $binds = [$days];
        if ($limit > 0) {
            $sql     .= ' LIMIT ?';
            $binds[]  = $limit;
        }

        $result = $db->query($sql, $binds);
        $rows   = $result instanceof BaseResult ? $result->getResultArray() : [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'character_id' => (int) $r['character_id'],
                'chat_id'      => (int) $r['chat_id'],
                'name'         => is_string($r['name']) && $r['name'] !== '' ? $r['name'] : 'Unknown Hero',
                'cell_number'  => (int) $r['cell_number'],
            ];
        }
        return $out;
    }

    /**
     * Свободные клетки южной полосы: существуют в `map`, биом из фактического списка
     * спавна, не заклеймены никем (нет активной `claimed_cells` на этот `map.id`).
     *
     * @param BaseConnection<object,object> $db
     * @return list<int> cell_number
     */
    private function findTargetCells(BaseConnection $db, int $yMin, int $yMax): array
    {
        $sql = 'SELECT m.cell_number AS cell_number
                FROM map m
                WHERE m.coordinate_y BETWEEN ? AND ?
                  AND m.biome_id IN (' . implode(',', self::SPAWN_BIOMES) . ')
                  AND NOT EXISTS (
                      SELECT 1 FROM claimed_cells cc
                      WHERE cc.map_cell_id = m.id AND cc.status = \'active\'
                  )';

        $result = $db->query($sql, [$yMin, $yMax]);
        $rows   = $result instanceof BaseResult ? $result->getResultArray() : [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r['cell_number'];
        }
        return $out;
    }

    /**
     * Атомарный переезд одного персонажа: условный UPDATE проверяет, что персонаж всё
     * ещё стоит на исходной клетке (idempotency + защита от гонки), и только при
     * `affectedRows()===1` пишет аудит-строку.
     *
     * @param BaseConnection<object,object> $db
     * @param array{character_id:int,chat_id:int,name:string,from:int,to:int} $row
     */
    private function relocateOne(BaseConnection $db, array $row): bool
    {
        $db->query(
            'UPDATE characters SET cell_number = ? WHERE id = ? AND cell_number IN (' . implode(',', self::ORIGIN_CELLS) . ')',
            [$row['to'], $row['character_id']]
        );

        if ($db->affectedRows() !== 1) {
            return false;
        }

        $db->query(
            'INSERT INTO action_log (character_id, chat_id, action_name, action_status, description, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $row['character_id'],
                $row['chat_id'],
                self::ACTION_NAME,
                'Completed',
                "pvp-detection-clarity-03: переезд с клетки {$row['from']} на клетку {$row['to']}",
            ]
        );

        return true;
    }

    /**
     * @param list<array{character_id:int,chat_id:int,name:string,from:int,to:int}> $plan
     */
    private function printPlan(array $plan, int $days, int $yMin, int $yMax): void
    {
        CLI::write('');
        CLI::write(sprintf(
            '═══ ПЛАН ПЕРЕЕЗДА (%d кандидатов, окно неактивности %d дн., полоса y=[%d,%d]) ═══',
            count($plan),
            $days,
            $yMin,
            $yMax
        ), 'white');

        $thead = ['id', 'имя', 'откуда', 'куда'];
        $tbody = [];
        foreach ($plan as $row) {
            $tbody[] = [(string) $row['character_id'], $row['name'], (string) $row['from'], (string) $row['to']];
        }
        CLI::table($tbody, $thead);
    }

    /**
     * Достаёт фразу подтверждения, поддерживая обе формы CLI-опции (как в `game:wipe`):
     *   --confirm RELOCATE-ABANDONED   (пробел; нативно для CI4)
     *   --confirm=RELOCATE-ABANDONED   (=; CI4-парсер кладёт всё в ключ опции)
     */
    private function resolveConfirm(): string
    {
        $confirmRaw = CLI::getOption('confirm');
        if (is_string($confirmRaw) && $confirmRaw !== '') {
            return $confirmRaw;
        }

        foreach (array_keys(CLI::getOptions()) as $key) {
            if (str_starts_with($key, 'confirm=')) {
                return substr($key, strlen('confirm='));
            }
        }

        return '';
    }
}
