<?php

declare(strict_types=1);

namespace App\Services\Admin;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\WipeManifest;
use RuntimeException;

/**
 * WipeService — движок полного вайпа сервера (ADR-087, задача Asana «ВАЙП всех игроков»).
 *
 * Единственный исполнитель деструктивной механики. Классификацию таблиц берёт
 * из {@see WipeManifest} (single source of truth). Поддерживает:
 *   - preview()         — безопасный сухой отчёт (что удалится/сбросится, сколько игроков);
 *   - coverageGaps()    — детект незаклассифицированных live-таблиц (анти-дрифт);
 *   - wipeAll()         — полный вайп всех игроков (атомарно, FK-safe);
 *   - resetCharacter()  — сброс одного персонажа (тот же манифест, путь для админки/CLI).
 *
 * Инвариант сохранности (НЕ трогаются): мир/карта, контент-определения, game_settings,
 * админ/сайт/SEO, идентичность игроков (telegram_users id/имена, пул имён, characters.name+id).
 */
final class WipeService
{
    /** @var BaseConnection<object, object> */
    private BaseConnection $db;
    private WipeManifest $manifest;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?WipeManifest $manifest = null, ?BaseConnection $db = null)
    {
        $this->manifest = $manifest ?? new WipeManifest();
        $this->db       = $db ?? Database::connect();
    }

    public function manifest(): WipeManifest
    {
        return $this->manifest;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Анти-дрифт: live-схема vs манифест
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array{unclassified: list<string>, stale: list<string>}
     *   unclassified — live-таблицы без записи в манифесте (НОВЫЕ, требуют классификации);
     *   stale        — записи манифеста для несуществующих таблиц.
     */
    public function coverageGaps(): array
    {
        $live  = $this->liveTables();
        $known = array_keys($this->manifest->tables);

        return [
            'unclassified' => array_values(array_diff($live, $known)),
            'stale'        => array_values(array_diff($known, $live)),
        ];
    }

    /**
     * @return list<string>
     */
    private function liveTables(): array
    {
        $tables = $this->db->listTables();
        if ($tables === false) {
            return [];
        }

        /** @var list<string> $out */
        $out = [];
        foreach ($tables as $t) {
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Превью (сухой отчёт)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Сухой отчёт без изменений в БД: что произойдёт с каждой таблицей.
     *
     * @return array{
     *   players:int,
     *   characters:int,
     *   tables: list<array{table:string, strategy:string, note:string, rows:int, affected:int, detail:string}>,
     *   coverage: array{unclassified: list<string>, stale: list<string>},
     *   totals: array{rows_deleted:int, rows_reset:int, tables_kept:int, tables_deleted:int, tables_reset:int}
     * }
     */
    public function preview(): array
    {
        $players    = $this->countRows('telegram_users');
        $characters = $this->countRows('characters');

        $tables       = [];
        $rowsDeleted  = 0;
        $rowsReset    = 0;
        $tablesKept   = 0;
        $tablesDel    = 0;
        $tablesReset  = 0;

        foreach ($this->manifest->tables as $table => $d) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $total    = $this->countRows($table);
            $strategy = $d['strategy'];
            $affected = 0;
            $detail   = '';

            switch ($strategy) {
                case WipeManifest::KEEP:
                    $detail = 'остаётся без изменений';
                    $tablesKept++;
                    break;

                case WipeManifest::PLAYER_DATA:
                case WipeManifest::TRANSIENT:
                    $affected = $total;
                    $detail   = "удалить все строки: {$total}";
                    $rowsDeleted += $total;
                    if ($total > 0) {
                        $tablesDel++;
                    }
                    break;

                case WipeManifest::CHARACTER_RESET:
                    $affected = $total;
                    $detail   = "сброс {$total} персонажей к стартовым (id/имя сохранены) + респавн на юг";
                    $rowsReset += $total;
                    $tablesReset++;
                    break;

                case WipeManifest::IDENTITY_RESET:
                    $affected = $total;
                    $detail   = "сохранить идентичность {$total} аккаунтов, обнулить указатели сообщений";
                    $rowsReset += $total;
                    $tablesReset++;
                    break;

                case WipeManifest::SEED_RESET:
                    $affected = $total;
                    $detail   = "сброс счётчиков в {$total} строках к seed";
                    $rowsReset += $total;
                    $tablesReset++;
                    break;
            }

            $tables[] = [
                'table'    => $table,
                'strategy' => $strategy,
                'note'     => $d['note'],
                'rows'     => $total,
                'affected' => $affected,
                'detail'   => $detail,
            ];
        }

        return [
            'players'    => $players,
            'characters' => $characters,
            'tables'     => $tables,
            'coverage'   => $this->coverageGaps(),
            'totals'     => [
                'rows_deleted'   => $rowsDeleted,
                'rows_reset'     => $rowsReset,
                'tables_kept'    => $tablesKept,
                'tables_deleted' => $tablesDel,
                'tables_reset'   => $tablesReset,
            ],
        ];
    }

    private function countRows(string $table): int
    {
        if (! $this->db->tableExists($table)) {
            return 0;
        }

        return $this->toInt($this->db->table($table)->countAllResults());
    }

    /**
     * Узкое приведение к int (CI4 countAllResults/affectedRows типизированы int|string).
     * Нарроим через is_numeric — НЕ голый (int)$mixed (memory phpstan_no_mixed_to_int_cast).
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Превью сброса ОДНОГО персонажа: player_data таблицы → число строк этого персонажа.
     * (Для admin /character-reset; тот же манифест → без сирот, в отличие от старого ResetTables.)
     *
     * @return array<string,int>
     */
    public function previewCharacter(int $characterId): array
    {
        $out = [];
        if ($characterId <= 0) {
            return $out;
        }
        $tgId = $this->telegramUserIdOf($characterId);

        foreach ($this->manifest->tables as $table => $d) {
            if ($d['strategy'] !== WipeManifest::PLAYER_DATA || ! $this->db->tableExists($table)) {
                continue;
            }
            $by   = $d['by'] ?? 'character';
            $link = $d['link'] ?? null;
            if ($link === null) {
                continue;
            }
            $matchId = $by === 'telegram' ? $tgId : $characterId;
            if ($matchId === null) {
                continue;
            }
            $cols    = is_array($link) ? $link : [$link];
            $builder = $this->db->table($table);
            $builder->groupStart();
            foreach ($cols as $i => $col) {
                if ($i === 0) {
                    $builder->where($col, $matchId);
                } else {
                    $builder->orWhere($col, $matchId);
                }
            }
            $builder->groupEnd();
            $count = $this->toInt($builder->countAllResults());
            if ($count > 0) {
                $out[$table] = $count;
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Полный вайп
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Полный вайп всех игроков. Атомарно (транзакция), FK-safe (отключаем FK-checks
     * на время операции — порядок удаления не важен, защита от будущих таблиц).
     *
     * 🔴 НЕОБРАТИМО. Перед вызовом обязателен coverage-чек: если есть незаклассифицированные
     * таблицы — бросаем исключение (не вайпим вслепую).
     *
     * @return array{deleted: array<string,int>, reset: array<string,int>, characters_respawned:int}
     */
    public function wipeAll(): array
    {
        $gaps = $this->coverageGaps();
        if ($gaps['unclassified'] !== []) {
            throw new RuntimeException(
                'Вайп прерван: незаклассифицированные таблицы в WipeManifest: '
                . implode(', ', $gaps['unclassified'])
            );
        }

        $deleted   = [];
        $reset     = [];
        $respawned = 0;

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->transStart();

        try {
            foreach ($this->manifest->tables as $table => $d) {
                if (! $this->db->tableExists($table)) {
                    continue;
                }
                $strategy = $d['strategy'];

                if ($strategy === WipeManifest::PLAYER_DATA || $strategy === WipeManifest::TRANSIENT) {
                    $count = $this->countRows($table);
                    $this->db->table($table)->emptyTable();
                    $deleted[$table] = $count;
                    continue;
                }

                if ($strategy === WipeManifest::SEED_RESET || $strategy === WipeManifest::IDENTITY_RESET) {
                    $values = $d['reset'] ?? [];
                    if ($values !== []) {
                        $this->db->table($table)->where('id >', 0)->set($values)->update();
                        $reset[$table] = $this->toInt($this->db->affectedRows());
                    }
                    continue;
                }

                if ($strategy === WipeManifest::CHARACTER_RESET) {
                    $respawned = $this->resetAllCharacters();
                    $reset[$table] = $respawned;
                }
                // KEEP — пропускаем намеренно.
            }

            $this->db->transComplete();
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        if ($this->db->transStatus() === false) {
            throw new RuntimeException('Вайп откатан: транзакция провалилась.');
        }

        return [
            'deleted'              => $deleted,
            'reset'                => $reset,
            'characters_respawned' => $respawned,
        ];
    }

    /**
     * Сброс ВСЕХ персонажей: bulk-UPDATE прогресс-колонок к стартовым + индивидуальный респавн.
     *
     * @return int количество персонажей, которым назначен новый спавн
     */
    private function resetAllCharacters(): int
    {
        $values               = $this->manifest->characterResetValues;
        $values['updated_at'] = date('Y-m-d H:i:s');

        $this->db->table('characters')->where('id >', 0)->set($values)->update();

        $spawnCells = $this->spawnCells();
        if ($spawnCells === []) {
            return 0;
        }

        $charRes = $this->db->table('characters')->select('id')->get();
        $charIds = $charRes === false ? [] : $charRes->getResultArray();
        $count   = 0;
        foreach ($charIds as $row) {
            $rawId = $row['id'] ?? null;
            if (! is_numeric($rawId)) {
                continue;
            }
            $cell = $spawnCells[array_rand($spawnCells)];
            $this->db->table('characters')->where('id', (int) $rawId)->update([
                'cell_number' => $cell['cell_number'],
                'biome_id'    => $cell['biome_id'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Сброс одного персонажа (path для CharacterResetController / админ-CRUD).
     * Удаляет player_data строки этого персонажа + сбрасывает его строку characters + респавн.
     * НЕ трогает глобальные TRANSIENT / SEED_RESET / IDENTITY_RESET.
     *
     * @return array{deleted: array<string,int>, respawned: bool}
     */
    public function resetCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            throw new RuntimeException('resetCharacter: некорректный characterId.');
        }

        $telegramUserId = $this->telegramUserIdOf($characterId);
        $rawTelegramId  = $this->rawTelegramIdOf($characterId);
        $deleted        = [];

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->transStart();

        try {
            foreach ($this->manifest->tables as $table => $d) {
                if ($d['strategy'] !== WipeManifest::PLAYER_DATA) {
                    continue;
                }
                if (! $this->db->tableExists($table)) {
                    continue;
                }

                $by   = $d['by'] ?? 'character';
                $link = $d['link'] ?? null;
                if ($link === null) {
                    continue;
                }
                $matchId = match ($by) {
                    'telegram'     => $telegramUserId,
                    'telegram_raw' => $rawTelegramId,
                    default        => $characterId,
                };
                if ($matchId === null) {
                    continue; // telegram-linked, но у персонажа нет tg-аккаунта — пропускаем
                }

                $cols    = is_array($link) ? $link : [$link];
                $builder = $this->db->table($table);
                $builder->groupStart();
                foreach ($cols as $i => $col) {
                    if ($i === 0) {
                        $builder->where($col, $matchId);
                    } else {
                        $builder->orWhere($col, $matchId);
                    }
                }
                $builder->groupEnd();
                $count = $this->toInt($builder->countAllResults(false));
                $builder->delete();
                $deleted[$table] = $count;
            }

            // Сброс самой строки персонажа + респавн.
            $values               = $this->manifest->characterResetValues;
            $values['updated_at'] = date('Y-m-d H:i:s');
            $spawnCells           = $this->spawnCells();
            $respawned            = false;
            if ($spawnCells !== []) {
                $cell                 = $spawnCells[array_rand($spawnCells)];
                $values['cell_number'] = $cell['cell_number'];
                $values['biome_id']    = $cell['biome_id'];
                $respawned             = true;
            }
            $this->db->table('characters')->where('id', $characterId)->set($values)->update();

            $this->db->transComplete();
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        if ($this->db->transStatus() === false) {
            throw new RuntimeException('Сброс персонажа откатан: транзакция провалилась.');
        }

        return ['deleted' => $deleted, 'respawned' => $respawned];
    }

    private function telegramUserIdOf(int $characterId): ?int
    {
        $res = $this->db->table('characters')->select('telegram_user_id')->where('id', $characterId)->get();
        $row = $res === false ? null : $res->getRowArray();
        $rawId = is_array($row) ? ($row['telegram_user_id'] ?? null) : null;

        return is_numeric($rawId) ? (int) $rawId : null;
    }

    /**
     * Мост между двумя системами Telegram-id (story community-chat-bot-74).
     * `characters.telegram_user_id` — ВНУТРЕННИЙ `telegram_users.id`. Таблицы, которые пишет
     * `CommunityIngestService` (`community_messages`), хранят СЫРОЙ Telegram `from.id`
     * (`telegram_users.telegram_id`) — другое пространство id. Явный join вместо совпадения
     * значений — без него `by => 'telegram_raw'` сравнивал бы несравнимые id и удалял 0 строк.
     */
    private function rawTelegramIdOf(int $characterId): ?int
    {
        if (! $this->db->tableExists('telegram_users')) {
            return null;
        }

        $res = $this->db->table('characters')
            ->select('telegram_users.telegram_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.id', $characterId)
            ->get();
        $row   = $res === false ? null : $res->getRowArray();
        $rawId = is_array($row) ? ($row['telegram_id'] ?? null) : null;

        return is_numeric($rawId) ? (int) $rawId : null;
    }

    /**
     * Кандидаты на спавн — безопасный юг (coordinate_y >= min_y) в разрешённых биомах.
     *
     * @return list<array{cell_number:int, biome_id:int}>
     */
    private function spawnCells(): array
    {
        $cfg = $this->manifest->characterRespawn;
        $res = $this->db->table('map')
            ->select('cell_number, biome_id')
            ->where('coordinate_y >=', $cfg['min_y'])
            ->whereIn('biome_id', $cfg['biomes'])
            ->get();
        $rows = $res === false ? [] : $res->getResultArray();

        /** @var list<array{cell_number:int, biome_id:int}> $cells */
        $cells = [];
        foreach ($rows as $r) {
            $cellNumber = $r['cell_number'] ?? null;
            $biomeId    = $r['biome_id'] ?? null;
            if (is_numeric($cellNumber) && is_numeric($biomeId)) {
                $cells[] = ['cell_number' => (int) $cellNumber, 'biome_id' => (int) $biomeId];
            }
        }

        return $cells;
    }
}
