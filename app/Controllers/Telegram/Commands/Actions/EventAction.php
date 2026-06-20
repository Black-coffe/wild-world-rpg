<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\ActiveEventModel;
use App\Models\EventModel;
use App\Models\BiomeModel;

use DateTime;

/**
 * Экран «🎉 События» (callback `events`).
 *
 * Показывает игроку ДВА блока (задача «видимость событий», 2026-06-20 — репорт
 * SarCasM: «событие нет, а урон есть» + Ivan «думал у всех, а оно по локациям»):
 *
 *   1) 🌟 Что происходит ПРЯМО СЕЙЧАС — активные события (`status='active'`) с тем,
 *      где они идут, какой эффект, сколько осталось, и коснулось ли они ИГРОКА.
 *   2) 📜 ИСТОРИЯ — последние 3 завершённых события (`status='completed'`) с началом,
 *      концом и длительностью. История даёт игроку контекст: «Метеоритный дождь шёл
 *      17:50–18:13, тебя задело» — и снимает путаницу, когда событие закончилось
 *      минуту назад, а урон ещё сказывается на здоровье.
 *
 * 🗄 Лог событий не нужен отдельной таблицей — `active_events` уже хранит каждую
 * активацию (start_time/end_time/status; строки не удаляются, только active→completed,
 * см. EventActivationHandler::updateExpiredEventsStatus). Этот экран просто делает
 * накопленную историю видимой.
 *
 * 🖼 MEDIA-OFF (ADR-020): экран чисто текстовый (фото не шлёт) — disable_media не влияет.
 */
class EventAction extends BaseAction
{
    /** Сколько прошедших событий показывать в истории. */
    private const HISTORY_LIMIT = 3;

    /** @var string[] Месяцы в родительном падеже для «20 июня». */
    private const MONTHS_RU = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    protected $characterModel;
    protected $activeEvents;
    protected $events;
    protected $biomeModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->activeEvents = new ActiveEventModel();
        $this->events = new EventModel();
        $this->biomeModel = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $charId = isset($character['id']) && is_numeric($character['id']) ? (int) $character['id'] : 0;

        $activeEvents = $this->activeEvents
            ->where('status', 'active')
            ->orderBy('end_time', 'ASC')
            ->findAll();

        $pastEvents = $this->activeEvents
            ->where('status', 'completed')
            ->orderBy('end_time', 'DESC')
            ->findAll(self::HISTORY_LIMIT);

        $text  = $this->buildCurrentSection(is_array($activeEvents) ? $activeEvents : [], $charId);
        $text .= $this->buildHistorySection(is_array($pastEvents) ? $pastEvents : [], $charId);

        $keyboard = $this->getKeyboard();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Блок «что сейчас». Либо список активных событий, либо «активных нет».
     *
     * @param array<array-key, mixed> $activeEvents строки active_events со status='active'
     */
    private function buildCurrentSection(array $activeEvents, int $charId): string
    {
        if (empty($activeEvents)) {
            return "🎉 *Сейчас активных событий нет.*\n"
                . "Исследуй мир и готовься к новым приключениям.\n\n";
        }

        $text = "🌟 *Сейчас в мире происходят события:* 🌟\n\n";
        $num  = 0;
        foreach ($activeEvents as $event) {
            if (!is_array($event)) {
                continue;
            }
            $details = $this->events->find($event['event_id'] ?? 0);
            if (!is_array($details)) {
                continue;
            }
            $num++;

            $timeLeft = $this->timeLeft(self::asStr($event['end_time'] ?? null));
            $touched  = $this->wasTouched($event, $charId);

            $text .= "№{$num} *" . self::asStr($details['name'] ?? null) . "*\n"
                . "📜 _" . self::asStr($details['description'] ?? null) . "_\n"
                . "🌍 *Где:* _" . $this->translateEventType(self::asStr($details['event_type'] ?? null)) . "_\n"
                . $this->biomesLine($details)
                . "🚀 *Эффект:* _" . $this->translateEffectType(self::asStr($details['effect_type'] ?? null)) . "_\n"
                . "⏳ *Закончится через:* _{$timeLeft}_\n"
                . ($touched ? "🎯 _Тебя уже коснулось._\n" : "")
                . "\n";
        }

        return $text;
    }

    /**
     * Блок «история» — последние завершённые события с началом/концом/длительностью.
     *
     * @param array<array-key, mixed> $pastEvents строки active_events со status='completed'
     */
    private function buildHistorySection(array $pastEvents, int $charId): string
    {
        if (empty($pastEvents)) {
            return '';
        }

        $text = "━━━━━━━━━━━━\n"
            . "📜 *Последние прошедшие события:*\n\n";

        foreach ($pastEvents as $event) {
            if (!is_array($event)) {
                continue;
            }
            $details = $this->events->find($event['event_id'] ?? 0);
            $name    = is_array($details) ? self::asStr($details['name'] ?? null) : 'Неизвестное событие';

            $start    = self::asStr($event['start_time'] ?? null);
            $end      = self::asStr($event['end_time'] ?? null);
            $touched  = $this->wasTouched($event, $charId);

            $text .= "▫️ *{$name}*\n"
                . "🕘 Начало: _" . $this->formatRu($start) . "_\n"
                . "🏁 Конец: _" . $this->formatRu($end) . "_\n"
                . "⏱ Длилось: _" . $this->duration($start, $end) . "_\n"
                . (is_array($details) ? $this->biomesLine($details) : '')
                . ($touched ? "🎯 _Тебя задело._\n" : "🟢 _Тебя не коснулось._\n")
                . "\n";
        }

        return $text;
    }

    /**
     * Строка «🌱 Биомы: …» из event.biome_ids. Пусто, если биомов нет.
     *
     * @param array<array-key, mixed> $details строка events
     */
    private function biomesLine(array $details): string
    {
        $raw = $details['biome_ids'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $ids = json_decode($raw, true);
        if (!is_array($ids)) {
            return '';
        }

        $names = [];
        foreach ($ids as $id) {
            // BiomeModel отдаёт BiomeEntity (ArrayAccess), НЕ array — читаем через
            // offset, не `is_array` (иначе биомы молча выпадают; урок Entity-миграции).
            $biome = $this->biomeModel->find($id);
            if (($biome instanceof \ArrayAccess || is_array($biome)) && isset($biome['name'])) {
                $names[] = self::asStr($biome['name']);
            }
        }

        return empty($names) ? '' : "🌱 *Биомы:* " . implode(', ', $names) . "\n";
    }

    /**
     * Коснулось ли событие персонажа — по accumulator'у effect_log (кто реально
     * попал под эффект). Прямой и достоверный ответ на «у всех или по локациям».
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
            $log = is_array($decoded) ? $decoded : [];
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

    protected function translateEventType($type)
    {
        $translations = [
            'local' => 'Выборочно в указанных биомах',
            'global' => 'Повсеместно в указанных биомах'
        ];
        return $translations[$type] ?? $type;
    }

    protected function translateEffectType($type)
    {
        $translations = [
            'damage' => 'Урон',
            'heal' => 'Лечение',
            'buff' => 'Усиление',
            'debuff' => 'Ослабление',
            'none' => 'Без эффекта'
        ];
        return $translations[$type] ?? $type;
    }

    /** Безопасное приведение mixed → string (phpstan-strict). */
    private static function asStr(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string) $v : $default;
    }

    protected function getKeyboard()
    {
        // Метод для получения клавиатуры, если нужно изменить структуру
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
    }

}
