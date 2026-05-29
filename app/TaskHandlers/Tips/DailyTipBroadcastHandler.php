<?php

declare(strict_types=1);

namespace App\TaskHandlers\Tips;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\TipService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * ADR-038 Фаза C4 — ежедневная авто-рассылка «Совет дня».
 *
 * Tasks.php everyMinute + внутренние guard'ы (как QuestObjectiveHandler):
 *   1. killswitch `tips.daily_enabled` (GameSettings) — off → выход.
 *   2. hour-guard — рассылаем только в час `tips.daily_hour` (серверное время).
 *   3. once/day-guard — АТОМАРНЫЙ DB-claim строки game_settings `tips.daily_last_broadcast`
 *      (value_string = сегодняшняя дата). Conditional UPDATE `WHERE value_string != today`
 *      + affectedRows()===1 → выигравший процесс владеет рассылкой за сегодня; остальные
 *      тики (и повторы после cache:clear/деплоя в час рассылки) видят value=today → 0 affected → выход.
 *      DB-маркер durable (переживает cache:clear), в отличие от прежнего cache-only — тот при
 *      деплое в час 10:00 стирался → повторная рассылка (прод-аномалии 05-23 ×22 / 05-27 ×4).
 *      Claim ставится ДО цикла → краш в середине не вызывает повторную рассылку получившим.
 *
 * Каждому игроку с `daily_tips_enabled=1` (+ есть telegram-связь) — персональный совет
 * из его недедупнутого пула (та же логика, что /tips: {@see TipService}), лог в общий
 * дедуп `character_game_tips` + та же микро-награда. Throttle между сообщениями (Telegram
 * ~30 msg/sec). Send через safeSendMessage (ловит rate-limit/сетевые ошибки, не падает).
 *
 * Daily = серверная дата + час; глобальный once/day guard (не per-timezone игрока) — осознанно (ADR-038).
 */
#[HandlerKey(
    key: 'tips_daily_broadcast',
    displayName: 'Совет дня (ADR-038)',
    description: 'everyMinute + hour/once-day guard: раз в сутки шлёт каждому игроку (opt-in daily_tips_enabled) персональный совет с микро-наградой. Killswitch tips.daily_enabled, час tips.daily_hour.',
)]
class DailyTipBroadcastHandler extends BaseTaskHandler
{
    private const MARKER_KEY = 'tips.daily_last_broadcast'; // DB once/day-claim (durable)
    private const THROTTLE_USEC = 35000; // ~28 msg/sec < лимит Telegram 30/sec

    private GameSettingsService $settings;
    private TipService $tips;

    public function __construct(?GameSettingsService $settings = null, ?TipService $tips = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
        $this->tips     = $tips ?? new TipService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        // 1. killswitch
        if ($this->settings->get('tips.daily_enabled', true) !== true) {
            return;
        }

        // 2. hour-guard
        $hourRaw = $this->settings->get('tips.daily_hour', 10);
        $hour    = is_numeric($hourRaw) ? (int) $hourRaw : 10;
        if ((int) date('G') !== $hour) {
            return;
        }

        // 3. once/day-guard — атомарный DB-claim (durable, переживает cache:clear/деплой).
        if (! $this->claimToday()) {
            return;
        }

        $this->broadcast();
    }

    /**
     * Атомарно «забирает» рассылку за сегодня через game_settings-маркер.
     *
     * Self-heal: если строки маркера нет (свежая БД/тест) — создаёт её.
     * Conditional UPDATE `WHERE value_string != today` + affectedRows()===1 →
     * этот процесс выиграл claim. Иначе (уже today / гонка проиграна) → false.
     * InnoDB row-lock делает claim race-safe при пересекающихся тиках everyMinute.
     */
    private function claimToday(): bool
    {
        $today = date('Y-m-d');
        $db    = \Config\Database::connect();

        // Self-heal: гарантируем наличие строки маркера (value_string='' → != today → claim сработает).
        $exists = $db->table('game_settings')->where('setting_key', self::MARKER_KEY)->countAllResults();
        if ($exists === 0) {
            try {
                $db->table('game_settings')->insert([
                    'setting_key'  => self::MARKER_KEY,
                    'value_type'   => 'string',
                    'value_string' => '',
                    'category'     => 'system',
                ]);
            } catch (\Throwable) {
                // Гонка на вставке (UNIQUE setting_key) — строку создал параллельный процесс, ок.
            }
        }

        $db->table('game_settings')
            ->where('setting_key', self::MARKER_KEY)
            ->where('value_string !=', $today)
            ->update(['value_string' => $today]);

        return $db->affectedRows() === 1;
    }

    private function broadcast(): void
    {
        // Telegram инициализируется лениво в safeSendMessage() (через sendTip()) —
        // не дёргаем здесь, иначе db-тест (с override sendTip) падает на init без API_KEY.
        $query = (new CharacterModel())->builder()
            ->select('characters.id AS character_id, telegram_users.telegram_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.daily_tips_enabled', 1)
            ->get();
        /** @var list<array<string,mixed>> $rows */
        $rows = $query === false ? [] : $query->getResultArray();

        $sent = 0;
        $seen = [];
        foreach ($rows as $row) {
            $charRaw = $row['character_id'] ?? null;
            $tgRaw   = $row['telegram_id'] ?? null;
            if (! is_numeric($charRaw) || ! is_numeric($tgRaw)) {
                continue;
            }
            $charId = (int) $charRaw;
            $tgId   = (int) $tgRaw;
            if ($charId <= 0 || $tgId === 0 || isset($seen[$tgId])) {
                continue;
            }
            $seen[$tgId] = true;

            $tip = $this->tips->serveTip($charId);
            if ($tip === null) {
                continue; // пул советов исчерпан для этого игрока
            }

            $this->sendTip($tgId, TipService::renderTip($tip));
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        log_message('info', "[DailyTipBroadcast] sent {$sent} советов");
    }

    /**
     * Seam для тестов: реальная отправка совета игроку. Переопределяется в db-тесте.
     */
    protected function sendTip(int $tgId, string $text): void
    {
        $this->safeSendMessage($tgId, $text, ['parse_mode' => 'Markdown']);
    }
}
