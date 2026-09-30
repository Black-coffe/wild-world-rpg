<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\ActiveEventModel;
use App\Models\BiomeModel;
use App\Models\EventModel;
use DateTime;

/**
 * w2-n5-deeds-02 (ADR-190) — модель экрана «🎉 События» в нейтральном ядре, без `chat_id` и Markdown.
 * Бот (`EventAction`) и веб `/play?view=tasks` рисуют из неё каждый своё.
 *
 * Два блока (задача «видимость событий», 2026-06-20): активные события (`active_events.status='active'`)
 * с местом, эффектом, остатком времени и отметкой «задело ли игрока»; последние
 * {@see HISTORY_LIMIT} завершённых — с началом, концом и длительностью. «Задело» — по accumulator'у
 * `effect_log` (кто реально попал под эффект).
 *
 * @phpstan-type ActiveEvent array{name: string, description: string, where_ru: string, effect_ru: string, biomes: list<string>, end_time: string, time_left: string, touched: bool}
 * @phpstan-type PastEvent array{name: string, start_time: string, end_time: string, start_ru: string, end_ru: string, duration: string, biomes: list<string>, touched: bool}
 */
final class EventsModelService
{
    /** Сколько прошедших событий показывать в истории. */
    public const HISTORY_LIMIT = 3;

    /** @var array<int, string> Месяцы в родительном падеже для «20 июня». */
    private const MONTHS_RU = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    private const EVENT_TYPES = [
        'local'  => 'Выборочно в указанных биомах',
        'global' => 'Повсеместно в указанных биомах',
    ];

    private const EFFECT_TYPES = [
        'damage' => 'Урон',
        'heal'   => 'Лечение',
        'buff'   => 'Усиление',
        'debuff' => 'Ослабление',
        'none'   => 'Без эффекта',
    ];

    private ActiveEventModel $activeEvents;
    private EventModel $events;
    private BiomeModel $biomes;

    public function __construct(?ActiveEventModel $activeEvents = null, ?EventModel $events = null, ?BiomeModel $biomes = null)
    {
        $this->activeEvents = $activeEvents ?? new ActiveEventModel();
        $this->events       = $events ?? new EventModel();
        $this->biomes       = $biomes ?? new BiomeModel();
    }

    /**
     * @return array{active: list<ActiveEvent>, past: list<PastEvent>}
     */
    public function model(int $characterId): array
    {
        $activeRows = $this->activeEvents->where('status', 'active')->orderBy('end_time', 'ASC')->findAll();
        $pastRows   = $this->activeEvents->where('status', 'completed')->orderBy('end_time', 'DESC')->findAll(self::HISTORY_LIMIT);

        $active = [];
        foreach ($activeRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $details = $this->events->find(is_numeric($row['event_id'] ?? null) ? (int) $row['event_id'] : 0);
            if (! is_array($details)) {
                continue;
            }
            $end      = self::asStr($row['end_time'] ?? null);
            $active[] = [
                'name'        => self::asStr($details['name'] ?? null),
                'description' => self::asStr($details['description'] ?? null),
                'where_ru'    => self::translate(self::EVENT_TYPES, self::asStr($details['event_type'] ?? null)),
                'effect_ru'   => self::translate(self::EFFECT_TYPES, self::asStr($details['effect_type'] ?? null)),
                'biomes'      => $this->biomeNames($details),
                'end_time'    => $end,
                'time_left'   => $this->timeLeft($end),
                'touched'     => $this->wasTouched($row, $characterId),
            ];
        }

        $past = [];
        foreach ($pastRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $details = $this->events->find(is_numeric($row['event_id'] ?? null) ? (int) $row['event_id'] : 0);
            $start   = self::asStr($row['start_time'] ?? null);
            $end     = self::asStr($row['end_time'] ?? null);
            $past[]  = [
                'name'       => is_array($details) ? self::asStr($details['name'] ?? null) : 'Неизвестное событие',
                'start_time' => $start,
                'end_time'   => $end,
                'start_ru'   => $this->formatRu($start),
                'end_ru'     => $this->formatRu($end),
                'duration'   => $this->duration($start, $end),
                'biomes'     => is_array($details) ? $this->biomeNames($details) : [],
                'touched'    => $this->wasTouched($row, $characterId),
            ];
        }

        return ['active' => $active, 'past' => $past];
    }

    /**
     * Имена биомов из event.biome_ids.
     *
     * @param array<array-key, mixed> $details строка events
     * @return list<string>
     */
    private function biomeNames(array $details): array
    {
        $raw = $details['biome_ids'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $ids = json_decode($raw, true);
        if (! is_array($ids)) {
            return [];
        }

        $names = [];
        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }
            // BiomeModel отдаёт BiomeEntity (ArrayAccess), НЕ array — читаем через
            // offset, не `is_array` (иначе биомы молча выпадают; урок Entity-миграции).
            $biome = $this->biomes->find((int) $id);
            if ($biome instanceof \ArrayAccess && isset($biome['name'])) {
                $names[] = self::asStr($biome['name']);
            }
        }

        return $names;
    }

    /**
     * Коснулось ли событие персонажа — по accumulator'у effect_log.
     *
     * @param array<array-key, mixed> $eventRow строка active_events
     */
    private function wasTouched(array $eventRow, int $charId): bool
    {
        if ($charId <= 0) {
            return false;
        }
        $raw = $eventRow['effect_log'] ?? null;
        if (is_array($raw)) {
            $log = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $log     = is_array($decoded) ? $decoded : [];
        } else {
            return false;
        }

        return array_key_exists((string) $charId, $log);
    }

    /** «20 июня, 18:13» из datetime-строки. */
    private function formatRu(string $datetime): string
    {
        if ($datetime === '') {
            return '—';
        }
        try {
            $dt = new DateTime($datetime);
        } catch (\Throwable $e) {
            return $datetime;
        }
        $month = self::MONTHS_RU[(int) $dt->format('n')];

        return (int) $dt->format('j') . ' ' . $month . ', ' . $dt->format('H:i');
    }

    /** Длительность между двумя datetime-строками в «X ч Y мин». */
    private function duration(string $start, string $end): string
    {
        if ($start === '' || $end === '') {
            return '—';
        }
        try {
            $a = new DateTime($start);
            $b = new DateTime($end);
        } catch (\Throwable $e) {
            return '—';
        }
        $minutes = (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);

        return $this->humanMinutes(max(0, $minutes));
    }

    /** Сколько осталось до end_time, «X дн. Y чс. Z мин.». */
    private function timeLeft(string $endTime): string
    {
        if ($endTime === '') {
            return '—';
        }
        try {
            $end = new DateTime($endTime);
            $now = new DateTime();
        } catch (\Throwable $e) {
            return '—';
        }
        if ($end <= $now) {
            return 'меньше минуты';
        }

        return $now->diff($end)->format('%a дн. %H чс. %I мин.');
    }

    /** Минуты → «1 ч 23 мин.» / «45 мин.» / «1 дн. 2 ч». */
    private function humanMinutes(int $minutes): string
    {
        $days  = intdiv($minutes, 1440);
        $rest  = $minutes - $days * 1440;
        $hours = intdiv($rest, 60);
        $mins  = $rest % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} дн.";
        }
        if ($hours > 0) {
            $parts[] = "{$hours} ч";
        }
        if ($mins > 0 || empty($parts)) {
            $parts[] = "{$mins} мин.";
        }

        return implode(' ', $parts);
    }

    /** @param array<string, string> $map */
    private static function translate(array $map, string $type): string
    {
        return $map[$type] ?? $type;
    }

    /** Безопасное приведение mixed → string (phpstan-strict). */
    private static function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }
}
