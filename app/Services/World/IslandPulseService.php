<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Services\GameSettings\GameSettingsReaderTrait;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * S7 (ROADMAP-RETENTION-10, ADR-145) — «живой остров»: ПРАВДИВЫЙ пульс активности реальных
 * выживших.
 *
 * 🔴 ИНВАРИАНТ ЧЕСТНОСТИ (only-true-data): сервис показывает ТОЛЬКО агрегаты реальных действий
 * настоящих игроков (исследованные клетки, высадки, последняя база, стычки). НИКОГДА не
 * фабрикует «N онлайн», decoy-соседей или выдуманную активность. Дефицит выживших = КАНОН
 * («редкие выжившие, но ты не один») — играем на нём, не прячем: при пустом окне показываем
 * честную «тихо», опираясь на кумулятив (всего высадилось N).
 *
 * Источники (live-запросы, без новой таблицы — те же паттерны, что DashboardAnalyticsService):
 *   • explored_cells.created_at — «исследовано N клеток» + «бродили M выживших» (DISTINCT)
 *   • characters.created_at      — «высадилось L / всего T»
 *   • character_buildings.built_at + map — «последняя база поднята K назад (квадрат X/Y)»
 *   • battle_logs.created_at      — «стычек S»
 *
 * Все запросы drift-safe (try/catch → 0/null), read-only. Гейт: killswitch
 * `social.presence.enabled` (default OFF → кнопка/тизер не добавляются, byte-identical).
 *
 * MEDIA-OFF (ADR-020): экран текстовый, весь смысл в тексте.
 *
 * Overridable seam'ы (тесты без БД): enabled / windowHours / survivorsDays / cellsExplored /
 * distinctMovers / survivorsLanded / survivorsTotal / lastBase / skirmishes.
 *
 * @see [[ADR-145-Living-island-truthful-pulse]]
 */
class IslandPulseService
{
    use GameSettingsReaderTrait;

    // ── Гейты / окна ─────────────────────────────────────────────────────────

    public function enabled(): bool
    {
        return $this->gsBool('social.presence.enabled', false);
    }

    /** Окно «свежей» активности в часах (исследование/стычки). Default 24. */
    protected function windowHours(): int
    {
        return max(1, $this->gsInt('social.presence.window_hours', 24));
    }

    /** Окно «высадок» в днях. Default 7. */
    protected function survivorsDays(): int
    {
        return max(1, $this->gsInt('social.presence.survivors_days', 7));
    }

    // ── Сбор правдивого снимка ───────────────────────────────────────────────

    /**
     * Собрать все правдивые агрегаты в один массив (для рендера экрана/тизера).
     *
     * @return array{cells:int, movers:int, survivors_period:int, survivors_total:int, last_base:array{mins:int,x:int,y:int}|null, skirmishes:int, window_hours:int, survivors_days:int}
     */
    public function snapshot(): array
    {
        $h = $this->windowHours();
        $d = $this->survivorsDays();

        return [
            'cells'            => $this->cellsExplored($h),
            'movers'           => $this->distinctMovers($h),
            'survivors_period' => $this->survivorsLanded($d),
            'survivors_total'  => $this->survivorsTotal(),
            'last_base'        => $this->lastBase(),
            'skirmishes'       => $this->skirmishes($h),
            'window_hours'     => $h,
            'survivors_days'   => $d,
        ];
    }

    // ── DB-seam'ы (drift-safe, read-only) ────────────────────────────────────

    protected function cellsExplored(int $hours): int
    {
        return $this->scalar('SELECT COUNT(*) AS c FROM explored_cells WHERE created_at >= NOW() - INTERVAL ' . $hours . ' HOUR');
    }

    protected function distinctMovers(int $hours): int
    {
        return $this->scalar('SELECT COUNT(DISTINCT character_id) AS c FROM explored_cells WHERE created_at >= NOW() - INTERVAL ' . $hours . ' HOUR');
    }

    protected function survivorsLanded(int $days): int
    {
        return $this->scalar('SELECT COUNT(*) AS c FROM characters WHERE created_at >= NOW() - INTERVAL ' . $days . ' DAY');
    }

    protected function survivorsTotal(): int
    {
        return $this->scalar('SELECT COUNT(*) AS c FROM characters');
    }

    protected function skirmishes(int $hours): int
    {
        return $this->scalar('SELECT COUNT(*) AS c FROM battle_logs WHERE created_at >= NOW() - INTERVAL ' . $hours . ' HOUR');
    }

    /**
     * Последняя построенная база: минут назад + координаты квадрата. null, если баз нет.
     *
     * @return array{mins:int,x:int,y:int}|null
     */
    protected function lastBase(): ?array
    {
        $row = $this->row(
            'SELECT TIMESTAMPDIFF(MINUTE, cb.built_at, NOW()) AS mins, m.coordinate_x AS x, m.coordinate_y AS y '
            . 'FROM character_buildings cb JOIN map m ON m.id = cb.map_cell_id '
            . 'WHERE cb.built_at IS NOT NULL ORDER BY cb.built_at DESC LIMIT 1'
        );
        if (! isset($row['mins'])) {
            return null;
        }

        return [
            'mins' => self::intOf($row['mins']),
            'x'    => self::intOf($row['x'] ?? 0),
            'y'    => self::intOf($row['y'] ?? 0),
        ];
    }

    // ── Текст (media-off, markdown-balanced) ─────────────────────────────────

    /**
     * Полный экран «🌍 Остров живёт». Показываем только ненулевые строки (честно: 0 не пишем).
     * Если окно пустое — честная «тихо» + кумулятив (всего высадилось T) — он всегда > 0.
     *
     * @param array{cells:int, movers:int, survivors_period:int, survivors_total:int, last_base:array{mins:int,x:int,y:int}|null, skirmishes:int, window_hours:int, survivors_days:int} $s
     */
    public function buildIslandText(array $s): string
    {
        $win = self::windowLabel($s['window_hours']);
        $dlabel = self::daysLabel($s['survivors_days']);

        $lines = [];
        if ($s['cells'] > 0) {
            $lines[] = "🧭 Исследовано *{$s['cells']}* клеток пустоши ({$win})";
        }
        if ($s['movers'] > 0) {
            $lines[] = "👣 По пустоши бродили *{$s['movers']}* выживших ({$win})";
        }
        if ($s['last_base'] !== null) {
            $ago = self::agoLabel($s['last_base']['mins']);
            $lines[] = "🏕 Последняя база поднята *{$ago}* (квадрат {$s['last_base']['x']}/{$s['last_base']['y']})";
        }
        if ($s['survivors_period'] > 0) {
            $lines[] = "🆕 {$dlabel} высадилось *{$s['survivors_period']}* новых выживших";
        }
        if ($s['skirmishes'] > 0) {
            $lines[] = "⚔️ Стычек на пустоши: *{$s['skirmishes']}* ({$win})";
        }

        $head = "🌍 *Остров живёт*\n\n";

        if ($lines === []) {
            // Честная «тихо»: ни одного свежего следа за окно — но остров помнит кумулятив.
            return $head
                . "Сейчас на пустоши тихо — свежих следов за {$win} не видно.\n\n"
                . "Но остров помнит: всего на берег выбросило *{$s['survivors_total']}* выживших. "
                . "Редко, да — зато ты точно не один.";
        }

        return $head
            . "Выживших на острове мало — но ты не один. Реальные следы за последнее время:\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Всего на острове отметилось *{$s['survivors_total']}* выживших.\n"
            . "_Все цифры настоящие — это такие же выжившие, как ты._";
    }

    /**
     * Короткий тизер для подвала карты (одна строка). null, если показывать нечего.
     *
     * @param array{cells:int, movers:int, survivors_period:int, survivors_total:int, last_base:array{mins:int,x:int,y:int}|null, skirmishes:int, window_hours:int, survivors_days:int} $s
     */
    public function teaserLine(array $s): ?string
    {
        if ($s['cells'] > 0) {
            return "🌍 *Остров жив:* за {$this->windowShort($s['window_hours'])} разведано {$s['cells']} клеток. Жми «🌍 Остров живёт».";
        }
        if ($s['survivors_total'] > 0) {
            return "🌍 *Остров жив:* всего высадилось {$s['survivors_total']} выживших. Жми «🌍 Остров живёт».";
        }

        return null;
    }

    /**
     * Готовый payload для IslandAction (текст + кнопка назад к карте).
     *
     * @return array{text:string, reply_markup:string}
     */
    public function payload(): array
    {
        $s = $this->snapshot();

        return [
            'text'         => $this->buildIslandText($s),
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '⬅️ К карте', 'callback_data' => 'move']],
                ],
            ]) ?: '',
        ];
    }

    // ── Хелперы форматирования (чистые, тестируемые) ─────────────────────────

    public static function agoLabel(int $mins): string
    {
        if ($mins < 1) {
            return 'только что';
        }
        if ($mins < 60) {
            return "{$mins} мин назад";
        }
        if ($mins < 1440) {
            return intdiv($mins, 60) . ' ч назад';
        }

        return intdiv($mins, 1440) . ' дн назад';
    }

    private static function windowLabel(int $hours): string
    {
        return $hours === 24 ? 'за сутки' : "за {$hours} ч";
    }

    private function windowShort(int $hours): string
    {
        return $hours === 24 ? 'сутки' : "{$hours} ч";
    }

    private static function daysLabel(int $days): string
    {
        return $days === 7 ? 'За неделю' : "За {$days} дн";
    }

    // ── Низкоуровневое ───────────────────────────────────────────────────────

    private function scalar(string $sql): int
    {
        return self::intOf($this->row($sql)['c'] ?? 0);
    }

    /**
     * Drift-safe одиночная строка (зеркало DashboardAnalyticsService::row): любая ошибка БД
     * (нет колонки/таблицы на стейл-дампе, DBDebug) → []. read-only.
     *
     * @return array<string,mixed>
     */
    private function row(string $sql): array
    {
        try {
            $q = Database::connect()->query($sql);
            if (! $q instanceof BaseResult) {
                return [];
            }
            $row = $q->getRowArray();

            return is_array($row) ? $row : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function intOf(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
